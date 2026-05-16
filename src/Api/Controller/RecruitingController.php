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
 * Player headshots are fetched from On3's search page:
 *   https://www.on3.com/rivals/search/?query={name}
 * This endpoint returns real server-rendered HTML (no Cloudflare block)
 * and includes on3static.com image URLs directly in the search results.
 * Photos are cached independently per recruit for 7 days.
 *
 * Settings read (all under the ernestdefoe-recruiting.* namespace):
 *   api_key       — CFBD bearer token (required)
 *   year          — recruiting class year (empty = current calendar year)
 *   team          — filter by committed/recruiting team name (empty = national)
 *   max_recruits  — maximum results to return (default 25)
 *   cache_minutes — how long to cache CFBD responses (default 360)
 */
class RecruitingController implements RequestHandlerInterface
{
    private const CFBD_BASE       = 'https://api.collegefootballdata.com';
    private const ON3_SEARCH      = 'https://www.on3.com/rivals/search/';
    private const ON3_STATIC_HOST = 'https://on3static.com';

    /** Browser-like UA so On3 serves full server-rendered HTML. */
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

        if (!$apiKey) {
            return new JsonResponse([
                'data'  => [],
                'year'  => (int) ($year ?: date('Y')),
                'error' => 'api_key_missing',
            ]);
        }

        $year = $year ?: (string) date('Y');

        $cacheKey = 'ernestdefoe-recruiting.' . md5("{$year}|{$team}|{$maxRecruits}");

        try {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = resolve('cache.store');

            $data = $cache->remember(
                $cacheKey,
                $cacheMinutes * 60,
                fn () => $this->fetchFromCfbd($apiKey, $year, $team, $maxRecruits)
            );

            // Enrich with On3 headshots — independent per-player cache so photos
            // survive a main-list cache bust and only re-scrape when they expire.
            $data = $this->enrichWithPhotos($data, $cache);

            return new JsonResponse([
                'data' => $data,
                'year' => (int) $year,
            ]);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'data'  => [],
                'year'  => (int) $year,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            resolve('log')->error('[recruiting] RecruitingController: ' . $e->getMessage(), ['exception' => $e]);

            return new JsonResponse([
                'data'  => [],
                'year'  => (int) $year,
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

    // ── On3 photo scraping ────────────────────────────────────────────────────

    /**
     * Enrich each recruit with a headshot URL from On3's search results page.
     *
     * On3's search endpoint returns server-rendered HTML (no JS required, no
     * Cloudflare block) that already contains on3static.com image URLs in the
     * result rows.  We scrape that page by name and extract the first player
     * image we find, then cache it per recruit independently of the main list.
     */
    private function enrichWithPhotos(array $recruits, $cache): array
    {
        $resolved = []; // nameHash → URL|null
        $toFetch  = []; // nameHash → playerName  (not yet in cache)

        foreach ($recruits as $r) {
            if (empty($r['name'])) {
                continue;
            }

            $nameHash = md5(strtolower(trim((string) $r['name'])));
            $cacheKey = "ernestdefoe-recruiting.photoon3.{$nameHash}";
            $hit      = $cache->get($cacheKey);

            if ($hit !== null) {
                $resolved[$nameHash] = ($hit !== '') ? $hit : null;
            } else {
                $toFetch[$nameHash] = (string) $r['name'];
            }
        }

        if (!empty($toFetch)) {
            $this->scrapeOn3Photos($toFetch, $resolved, $cache);
        }

        return array_map(function ($r) use ($resolved) {
            $nameHash      = md5(strtolower(trim((string) ($r['name'] ?? ''))));
            $r['photoUrl'] = $resolved[$nameHash] ?? null;
            return $r;
        }, $recruits);
    }

    /**
     * Concurrently fetch On3 search pages for each recruit and extract a photo URL.
     *
     * @param  array  $namesToFetch  nameHash => playerName
     * @param  array  &$resolved     nameHash => URL|null  (written into)
     */
    private function scrapeOn3Photos(array $namesToFetch, array &$resolved, $cache): void
    {
        $client = new Client([
            'timeout'         => 10,
            'connect_timeout' => 5,
            'allow_redirects' => ['max' => 5],
            'http_errors'     => false,
            'headers'         => [
                'User-Agent'      => self::SCRAPE_UA,
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer'         => 'https://www.on3.com/',
            ],
        ]);

        $requests = function () use ($namesToFetch) {
            foreach ($namesToFetch as $nameHash => $name) {
                yield $nameHash => new GuzzleRequest(
                    'GET',
                    self::ON3_SEARCH . '?' . http_build_query(['query' => $name])
                );
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => 4, // stay gentle with On3

            'fulfilled' => function ($response, string $nameHash) use (&$resolved, $cache) {
                $url = null;

                if ($response->getStatusCode() === 200) {
                    $url = $this->extractOn3Image((string) $response->getBody());
                }

                $resolved[$nameHash] = $url;
                $ttl = $url ? (7 * 24 * 3600) : (24 * 3600);
                $cache->put("ernestdefoe-recruiting.photoon3.{$nameHash}", $url ?? '', $ttl);
            },

            'rejected' => function ($reason, string $nameHash) use (&$resolved, $cache) {
                $resolved[$nameHash] = null;
                $cache->put("ernestdefoe-recruiting.photoon3.{$nameHash}", '', 3600);
            },
        ]);

        $pool->promise()->wait();
    }

    /**
     * Extract the first player headshot URL from an On3 search results page.
     *
     * On3 search results embed on3static.com image URLs in the HTML.
     * We match the CDN pattern and strip the image-resize proxy prefix so we
     * get the full-quality direct CDN URL.
     *
     * Pattern in HTML:
     *   https://on3static.com/cdn-cgi/image/{params}/uploads/assets/{a}/{b}/{id}.{ext}
     * We return:
     *   https://on3static.com/uploads/assets/{a}/{b}/{id}.{ext}
     *
     * SVG files are skipped (those are team logos, not player headshots).
     */
    private function extractOn3Image(string $html): ?string
    {
        // Match the cdn-cgi resized variant (most common in search results).
        if (preg_match(
            '~https://on3static\.com/cdn-cgi/image/[^/\s"\']+(/uploads/assets/\d+/\d+/\d+\.(?:jpg|jpeg|png|webp))~i',
            $html,
            $m
        )) {
            return self::ON3_STATIC_HOST . $m[1];
        }

        // Fallback: match a direct on3static URL (no resize proxy).
        if (preg_match(
            '~https://on3static\.com(/uploads/assets/\d+/\d+/\d+\.(?:jpg|jpeg|png|webp))~i',
            $html,
            $m
        )) {
            return self::ON3_STATIC_HOST . $m[1];
        }

        return null;
    }
}
