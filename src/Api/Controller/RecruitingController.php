<?php

namespace Ernestdefoe\Recruiting\Api\Controller;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/cfbd-recruits
 *
 * Proxies the College Football Data API recruiting/players endpoint.
 * Results are cached in Flarum's cache store for the configured duration.
 *
 * Player headshots are scraped from ESPN recruit profile pages (og:image)
 * and cached individually per athlete for 7 days so the scraping only
 * happens on first encounter.
 *
 * Settings read (all under the ernestdefoe-recruiting.* namespace):
 *   api_key       — CFBD bearer token (required)
 *   year          — recruiting class year (empty = current calendar year)
 *   team          — filter by committed/recruiting team name (empty = national)
 *   max_recruits  — maximum results to return (default 25)
 *   cache_minutes — how long to cache CFBD responses (default 360)
 *   widget_title  — display title passed back to the widget
 */
class RecruitingController implements RequestHandlerInterface
{
    private const CFBD_BASE    = 'https://api.collegefootballdata.com';
    private const ESPN_RECRUIT = 'https://www.espn.com/college-sports/recruiting/football/player/_/id/';

    /** Browser-like UA so ESPN serves the full server-rendered HTML. */
    private const SCRAPE_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/124.0.0.0 Safari/537.36';

    public function __construct(
        private SettingsRepositoryInterface $settings
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $apiKey       = trim((string) $this->settings->get('ernestdefoe-recruiting.api_key', ''));
        $year         = trim((string) $this->settings->get('ernestdefoe-recruiting.year', ''));
        $team         = trim((string) $this->settings->get('ernestdefoe-recruiting.team', ''));
        $maxRecruits  = max(1, min(100, (int) $this->settings->get('ernestdefoe-recruiting.max_recruits', 25)));
        $cacheMinutes = max(1, (int) $this->settings->get('ernestdefoe-recruiting.cache_minutes', 360));
        $widgetTitle  = trim((string) $this->settings->get('ernestdefoe-recruiting.widget_title', 'Top Recruits')) ?: 'Top Recruits';

        if (!$apiKey) {
            return new JsonResponse([
                'data'  => [],
                'year'  => (int) ($year ?: date('Y')),
                'title' => $widgetTitle,
                'error' => 'api_key_missing',
            ]);
        }

        $year = $year ?: (string) date('Y');

        // Deterministic cache key based on query parameters.
        $cacheKey = 'ernestdefoe-recruiting.' . md5("{$year}|{$team}|{$maxRecruits}");

        try {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = resolve('cache.store');

            // Fetch (or hit cache for) the core recruit list.
            $data = $cache->remember(
                $cacheKey,
                $cacheMinutes * 60,
                fn () => $this->fetchFromCfbd($apiKey, $year, $team, $maxRecruits)
            );

            // Enrich with player headshots — each photo has its own 7-day cache
            // so re-scraping only happens when individual entries expire, not
            // every time the main recruit cache refreshes.
            $data = $this->enrichWithPhotos($data, $cache);

            return new JsonResponse([
                'data'  => $data,
                'year'  => (int) $year,
                'title' => $widgetTitle,
            ]);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'data'  => [],
                'year'  => (int) $year,
                'title' => $widgetTitle,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            resolve('log')->error('[recruiting] RecruitingController: ' . $e->getMessage(), ['exception' => $e]);

            return new JsonResponse([
                'data'  => [],
                'year'  => (int) $year,
                'title' => $widgetTitle,
                'error' => 'unexpected_error',
            ]);
        }
    }

    // ── CFBD fetch ────────────────────────────────────────────────────────────

    /**
     * Fetch and transform recruiting data from the CFBD API.
     *
     * @throws \RuntimeException on HTTP/auth errors
     */
    private function fetchFromCfbd(string $apiKey, string $year, string $team, int $maxRecruits): array
    {
        $client = new Client(['timeout' => 10, 'connect_timeout' => 5]);

        $query = ['year' => $year];
        if ($team !== '') {
            $query['team'] = $team;
        }

        try {
            $response = $client->get(self::CFBD_BASE . '/recruiting/players', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept'        => 'application/json',
                    'User-Agent'    => 'FBSFB/1.0 (Flarum extension)',
                ],
                'query'       => $query,
                'http_errors' => false,
            ]);
        } catch (RequestException $e) {
            throw new \RuntimeException('cfbd_unreachable');
        }

        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new \RuntimeException('invalid_api_key');
        }

        if ($status !== 200) {
            throw new \RuntimeException('cfbd_error_' . $status);
        }

        $raw = json_decode((string) $response->getBody(), true);

        if (!is_array($raw)) {
            return [];
        }

        // Sort by national ranking ascending; unranked recruits go to the end.
        usort($raw, fn ($a, $b) => ($a['ranking'] ?? 99999) <=> ($b['ranking'] ?? 99999));

        return array_values(
            array_map(
                fn ($r) => $this->transform($r),
                array_slice($raw, 0, $maxRecruits)
            )
        );
    }

    /**
     * Transform a raw CFBD player object into our serialized format.
     */
    private function transform(array $r): array
    {
        $heightIn = isset($r['height']) ? (int) $r['height'] : null;
        $height   = $heightIn ? sprintf("%d'%d\"", intdiv($heightIn, 12), $heightIn % 12) : null;

        $city     = $r['city']          ?? null;
        $state    = $r['stateProvince'] ?? null;
        $hometown = implode(', ', array_filter([$city, $state])) ?: null;

        $committedTo = isset($r['committedTo']) && $r['committedTo'] !== ''
            ? (string) $r['committedTo']
            : null;

        return [
            'id'          => $r['id']          ?? null,
            'athleteId'   => isset($r['athleteId']) ? (int) $r['athleteId'] : null,
            'name'        => $r['name']        ?? 'Unknown',
            'position'    => isset($r['position']) ? strtoupper((string) $r['position']) : null,
            'height'      => $height,
            'weight'      => isset($r['weight']) ? ((int) $r['weight']) . ' lbs' : null,
            'city'        => $city,
            'state'       => $state,
            'hometown'    => $hometown,
            'country'     => isset($r['country']) && $r['country'] !== 'USA' ? (string) $r['country'] : null,
            'stars'       => isset($r['stars'])   ? (int)   $r['stars']   : null,
            'rating'      => isset($r['rating'])  ? round((float) $r['rating'], 4) : null,
            'ranking'     => isset($r['ranking']) ? (int)   $r['ranking'] : null,
            'status'      => $committedTo ? 'committed' : 'undecided',
            'school'      => $committedTo,
            'highSchool'  => $r['school']      ?? null,
            'recruitType' => $r['recruitType'] ?? 'HighSchool',
            'photoUrl'    => null, // filled in by enrichWithPhotos()
        ];
    }

    // ── Photo scraping ────────────────────────────────────────────────────────

    /**
     * Enrich each recruit with a headshot URL scraped from their ESPN recruit
     * profile page.  Each athlete's photo URL is cached independently for 7 days
     * so re-scraping only happens when individual entries expire.
     *
     * Requests are sent concurrently (up to 8 at a time) to keep latency low.
     */
    private function enrichWithPhotos(array $recruits, $cache): array
    {
        // Separate recruits that have an athleteId and need photo resolution.
        $withId = array_filter($recruits, fn ($r) => !empty($r['athleteId']));

        if (empty($withId)) {
            return $recruits;
        }

        $resolved = []; // athleteId → URL|null
        $toFetch  = []; // athleteId values not yet in cache

        foreach ($withId as $r) {
            $id  = $r['athleteId'];
            $key = "ernestdefoe-recruiting.photo.{$id}";
            $hit = $cache->get($key);

            if ($hit !== null) {
                // Empty string is a cached miss (no photo found).
                $resolved[$id] = $hit !== '' ? $hit : null;
            } else {
                $toFetch[] = $id;
            }
        }

        // Batch-fetch uncached athlete pages from ESPN.
        if (!empty($toFetch)) {
            $this->scrapeEspnPhotos($toFetch, $resolved, $cache);
        }

        // Merge photo URLs back into the recruit list.
        return array_map(function ($r) use ($resolved) {
            $r['photoUrl'] = $resolved[$r['athleteId'] ?? 0] ?? null;
            return $r;
        }, $recruits);
    }

    /**
     * Concurrently scrape ESPN recruit profile pages for og:image headshots.
     * Populates $resolved and writes per-athlete cache entries.
     *
     * @param  int[]  $athleteIds
     * @param  array  &$resolved   athleteId → URL|null  (written into)
     */
    private function scrapeEspnPhotos(array $athleteIds, array &$resolved, $cache): void
    {
        $client = new Client([
            'timeout'         => 8,
            'connect_timeout' => 4,
            'allow_redirects' => true,
            'http_errors'     => false,
            'headers'         => [
                'User-Agent'      => self::SCRAPE_UA,
                'Accept'          => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ]);

        $requests = function () use ($athleteIds, $client) {
            foreach ($athleteIds as $id) {
                yield (string) $id => new GuzzleRequest(
                    'GET',
                    self::ESPN_RECRUIT . $id
                );
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => 8,

            'fulfilled' => function ($response, string $id) use (&$resolved, $cache) {
                $url = null;

                if ($response->getStatusCode() === 200) {
                    $url = $this->extractOgImage((string) $response->getBody());
                }

                $resolved[(int) $id] = $url;
                // Cache success for 7 days, miss for 24 hours.
                $ttl = $url ? (7 * 24 * 3600) : (24 * 3600);
                $cache->put("ernestdefoe-recruiting.photo.{$id}", $url ?? '', $ttl);
            },

            'rejected' => function ($reason, string $id) use (&$resolved, $cache) {
                $resolved[(int) $id] = null;
                // Cache the failure briefly so we don't hammer ESPN on errors.
                $cache->put("ernestdefoe-recruiting.photo.{$id}", '', 3600);
            },
        ]);

        $pool->promise()->wait();
    }

    /**
     * Extract the og:image URL from an ESPN HTML page.
     * Handles both attribute orderings of the <meta> tag.
     */
    private function extractOgImage(string $html): ?string
    {
        // <meta property="og:image" content="...">
        if (preg_match(
            '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i',
            $html,
            $m
        )) {
            $url = trim($m[1]);
            return $url ?: null;
        }

        // <meta content="..." property="og:image">  (reversed attribute order)
        if (preg_match(
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i',
            $html,
            $m
        )) {
            $url = trim($m[1]);
            return $url ?: null;
        }

        return null;
    }
}
