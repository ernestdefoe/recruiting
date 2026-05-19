<?php

namespace Ernestdefoe\Recruiting\Api\Controller;

use Ernestdefoe\Recruiting\Job\RefreshRecruitsJob;
use Ernestdefoe\Recruiting\Service\CfbdClient;
use Ernestdefoe\Recruiting\Service\On3PhotoEnricher;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /api/cfbd-recruits
 *
 * Thin orchestrator. Resolves settings, checks the cache envelope,
 * decides whether to serve fresh / serve stale + refresh / fetch
 * inline (first-ever request only). Delegates HTTP + transform to
 * CfbdClient and headshot scraping to On3PhotoEnricher.
 *
 * Cache shape:
 *   $cacheKey -> ['data' => list<recruit>, 'fetched_at' => unix ts]
 *
 *   Hard retention is much longer than the soft TTL (default 7 days
 *   vs. 6 hours) so a string of CFBD outages doesn't blank the
 *   widget — we'd rather show data that's a few days old than the
 *   empty state.
 *
 * Refresh path:
 *   When data is past the soft TTL we dispatch RefreshRecruitsJob
 *   and return the stale data immediately. A short-lived
 *   "refresh-in-flight" cache lock prevents N concurrent workers
 *   from all dispatching the job in the cold-cache stampede.
 *
 *   On Flarum's default `sync` queue driver the job runs inline so
 *   that one unlucky request still pays the CFBD round-trip. With
 *   any real driver (`database`, `redis`, `sqs`) the dispatch
 *   returns immediately and the worker handles the fetch out of
 *   band.
 */
class RecruitingController implements RequestHandlerInterface
{
    /** Hard cache retention. Stale data is still better than no data. */
    private const HARD_RETENTION_SECONDS = 7 * 24 * 3600;

    /** Lock window: don't re-dispatch a refresh while another is in flight. */
    private const REFRESH_LOCK_SECONDS = 90;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private CacheRepository             $cache,
        private LoggerInterface             $log,
        private CfbdClient                  $cfbd,
        private On3PhotoEnricher            $photos,
        private BusDispatcher               $bus,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            return new JsonResponse(['error' => 'unauthenticated'], 401);
        }

        $apiKey       = trim((string) $this->settings->get('ernestdefoe-recruiting.api_key', ''));
        $year         = trim((string) $this->settings->get('ernestdefoe-recruiting.year', ''));
        $team         = trim((string) $this->settings->get('ernestdefoe-recruiting.team', ''));
        $maxRecruits  = max(1, min(100, (int) $this->settings->get('ernestdefoe-recruiting.max_recruits', 25)));
        $softTtl      = max(60, (int) $this->settings->get('ernestdefoe-recruiting.cache_minutes', 360) * 60);

        if (! $apiKey) {
            return new JsonResponse([
                'data'  => [],
                'year'  => (int) ($year ?: date('Y')),
                'error' => 'api_key_missing',
            ]);
        }

        $year = $year !== '' && preg_match('/^\d{4}$/', $year) ? $year : (string) date('Y');

        $cacheKey = 'ernestdefoe-recruiting.' . md5("{$year}|{$team}|{$maxRecruits}");
        $lockKey  = $cacheKey . '.refreshing';

        try {
            $cached = $this->cache->get($cacheKey);

            if (! is_array($cached) || ! isset($cached['data'], $cached['fetched_at'])) {
                // First-ever request for this query: inline fetch is the
                // only option (we have nothing to serve while a job runs).
                // Subsequent requests use the stale-while-revalidate path
                // below.
                return $this->serveInline($cacheKey, $apiKey, $year, $team, $maxRecruits);
            }

            $age      = time() - (int) $cached['fetched_at'];
            $isStale  = $age > $softTtl;

            if ($isStale && $this->cache->add($lockKey, '1', self::REFRESH_LOCK_SECONDS)) {
                // Stale, and no other worker has already taken the lock —
                // dispatch a refresh and serve the stale data immediately.
                $this->bus->dispatch(new RefreshRecruitsJob(
                    cacheKey:         $cacheKey,
                    apiKey:           $apiKey,
                    year:             $year,
                    team:             $team,
                    maxRecruits:      $maxRecruits,
                    retentionSeconds: self::HARD_RETENTION_SECONDS,
                ));
            }

            return $this->jsonRecruits($cached['data'], $year);
        } catch (\RuntimeException $e) {
            // Stable error codes from CfbdClient → pass through verbatim.
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

    /**
     * First-ever request (or a hard cache eviction) — fetch CFBD
     * inline, populate the envelope, return the result. This is the
     * only path that pays the API round-trip on the request thread.
     */
    private function serveInline(string $cacheKey, string $apiKey, string $year, string $team, int $maxRecruits): ResponseInterface
    {
        $data = $this->cfbd->fetchRecruits($apiKey, $year, $team, $maxRecruits);

        $this->cache->put($cacheKey, [
            'data'       => $data,
            'fetched_at' => time(),
        ], self::HARD_RETENTION_SECONDS);

        return $this->jsonRecruits($data, $year);
    }

    /**
     * Enrich with photos + frame the JSON response. Pulled out so
     * both the inline-fetch and serve-from-cache paths produce the
     * same shape.
     */
    private function jsonRecruits(array $data, string $year): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->photos->enrich($data, $year),
            'year' => (int) $year,
        ]);
    }
}
