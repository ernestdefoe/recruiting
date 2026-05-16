<?php

namespace Ernestdefoe\Recruiting\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /api/cfbd-recruits
 *
 * Proxies the College Football Data API recruiting/players endpoint and
 * enriches each recruit with a headshot from On3's rankings page.
 *
 * Photo strategy:
 *   Fetch https://www.on3.com/rivals/rankings/player/football/{year}/ once.
 *   That page returns server-rendered HTML with 150 + players, each with a
 *   profile href (/rivals/{slug}-{id}/) and an on3static.com image URL.
 *   We build a name-slug → image-URL map and cache it for 24 hours.
 *   One HTTP request per year covers all top recruits; no per-player scraping.
 *   An empty map is NOT cached — it is retried on the next request.
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
    private const ON3_RANKINGS    = 'https://www.on3.com/rivals/rankings/player/football/';
    private const ON3_STATIC_HOST = 'https://on3static.com';

    /** Browser-like UA so On3 serves full server-rendered HTML. */
    private const SCRAPE_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/124.0.0.0 Safari/537.36';

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private CacheRepository             $cache,
        private LoggerInterface             $log,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Require a logged-in user — guests have no business hitting this endpoint.
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            return new JsonResponse(['error' => 'unauthenticated'], 401);
        }

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

        // Sanitise year: must be a four-digit integer; fall back to current year.
        $year = $year ?: (string) date('Y');
        if (!preg_match('/^\d{4}$/', $year)) {
            $year = (string) date('Y');
        }

        $cacheKey = 'ernestdefoe-recruiting.' . md5("{$year}|{$team}|{$maxRecruits}");

        try {
            $data = $this->cache->remember(
                $cacheKey,
                $cacheMinutes * 60,
                fn () => $this->fetchFromCfbd($apiKey, $year, $team, $maxRecruits)
            );

            // Enrich with On3 headshots from the rankings page.
            // The image map is cached separately so it survives a CFBD cache bust.
            $data = $this->enrichWithPhotos($data, $year);

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
            $this->log->error('[recruiting] RecruitingController: ' . $e->getMessage(), ['exception' => $e]);

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

    // ── On3 photo enrichment ──────────────────────────────────────────────────

    /**
     * Attach On3 headshot URLs to each recruit using the rankings page image map.
     */
    private function enrichWithPhotos(array $recruits, string $year): array
    {
        $cacheKey = "ernestdefoe-recruiting.on3map.football.{$year}";

        // Use get/put instead of remember so we can skip caching an empty map.
        // An empty map means the On3 fetch failed; we want to retry next time.
        $imageMap = $this->cache->get($cacheKey);

        if ($imageMap === null) {
            $imageMap = $this->buildOn3ImageMap($year);

            if (!empty($imageMap)) {
                $this->cache->put($cacheKey, $imageMap, 24 * 3600); // 24-hour TTL
            }
        }

        if (empty($imageMap)) {
            return $recruits;
        }

        return array_map(function ($r) use ($imageMap) {
            $slug = $this->nameToSlug((string) ($r['name'] ?? ''));
            $r['photoUrl'] = $imageMap[$slug] ?? null;
            return $r;
        }, $recruits);
    }

    /**
     * Fetch the On3 football rankings page for a given year and return a
     * name-slug → image-URL map built from the server-rendered HTML.
     *
     * Returns an empty array on any network or parse failure (graceful degradation).
     */
    private function buildOn3ImageMap(string $year): array
    {
        $client = new Client([
            'timeout'         => 12,
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

        try {
            $response = $client->get(self::ON3_RANKINGS . $year . '/');
        } catch (\Throwable $e) {
            $this->log->warning('[recruiting] On3 rankings fetch failed', ['error' => $e->getMessage()]);
            return [];
        }

        if ($response->getStatusCode() !== 200) {
            $this->log->warning('[recruiting] On3 rankings HTTP ' . $response->getStatusCode());
            return [];
        }

        $map = $this->parseOn3Rankings((string) $response->getBody());

        $this->log->info('[recruiting] On3 image map built', [
            'year'    => $year,
            'players' => count($map),
        ]);

        return $map;
    }

    /**
     * Parse the On3 rankings HTML and return a name-slug → image-URL map.
     *
     * Strategy:
     *  1. Collect all on3static.com player image positions.
     *  2. Collect all /rivals/{name}-{id}/ profile-path positions.
     *  3. For each unique profile path, find the closest image (≤ 5 000 chars).
     *  4. Store as nameSlug → full CDN URL (cdn-cgi resize prefix stripped).
     */
    private function parseOn3Rankings(string $html): array
    {
        // All on3static player images (jpg/png/webp only — skip SVG logos).
        $imgPattern = '~https://on3static\.com(?:/cdn-cgi/image/[^\s"\'>\]]+)?'
                    . '(/uploads/assets/\d+/\d+/\d+\.(?:jpg|jpeg|png|webp))~i';

        preg_match_all($imgPattern, $html, $imgAll, PREG_OFFSET_CAPTURE);

        // All /rivals/{name}-{id}/ profile paths anywhere in the page.
        // Group 1 → name slug (e.g. "jared-curtis")
        // Group 2 → numeric On3 ID (e.g. "159433")
        preg_match_all(
            '~/rivals/([a-z0-9][a-z0-9\-]+)-(\d{4,})/~i',
            $html,
            $hrefAll,
            PREG_OFFSET_CAPTURE
        );

        if (empty($imgAll[0]) || empty($hrefAll[0])) {
            return [];
        }

        $map  = [];
        $seen = [];

        foreach ($hrefAll[0] as $i => [, $hrefPos]) {
            $nameSlug = $hrefAll[1][$i][0]; // e.g. "jared-curtis"

            if (empty($nameSlug) || isset($seen[$nameSlug])) {
                continue;
            }

            $seen[$nameSlug] = true;

            // Find the on3static image whose position is closest to this profile link.
            $bestDist = PHP_INT_MAX;
            $bestPath = null;

            foreach ($imgAll[0] as $j => [, $imgPos]) {
                $dist = abs($hrefPos - $imgPos);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestPath = $imgAll[1][$j][0]; // captured path group
                }
            }

            if ($bestPath !== null && $bestDist < 5000) {
                $map[$nameSlug] = self::ON3_STATIC_HOST . $bestPath;
            }
        }

        return $map;
    }

    /**
     * Normalise a full player name to an On3-compatible slug.
     * "Jared Curtis" → "jared-curtis"
     * "C.J. Stroud"  → "cj-stroud"
     */
    private function nameToSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}
