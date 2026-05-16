<?php

namespace Ernestdefoe\Recruiting\Api\Controller;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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
 * Settings read (all under the ernestdefoe-recruiting.* namespace):
 *   api_key       — CFBD bearer token (required)
 *   year          — recruiting class year (empty = current calendar year)
 *   team          — filter by committed/recruiting team name (empty = national)
 *   max_recruits  — maximum results to return (default 25)
 *   cache_minutes — how long to cache CFBD responses (default 360)
 */
class RecruitingController implements RequestHandlerInterface
{
    private const CFBD_BASE = 'https://api.collegefootballdata.com';

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

        // Deterministic cache key based on query parameters.
        $cacheKey = 'ernestdefoe-recruiting.' . md5("{$year}|{$team}|{$maxRecruits}");

        try {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = resolve('cache.store');

            $data = $cache->remember(
                $cacheKey,
                $cacheMinutes * 60,
                fn () => $this->fetchFromCfbd($apiKey, $year, $team, $maxRecruits)
            );

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
        ];
    }
}
