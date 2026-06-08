<?php

namespace Ernestdefoe\Recruiting\Api\Controller;

use Ernestdefoe\Recruiting\Job\RefreshRecruitsJob;
use Ernestdefoe\Recruiting\Service\CfbdClient;
use Ernestdefoe\Recruiting\Service\On3PhotoEnricher;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

    /**
     * Cold-cache single-flight window. Held while one request does the inline
     * fetch. Worst case is sequential CFBD (connect 5 + read 10 = 15 s) + On3
     * (connect 5 + read 12 = 17 s) ≈ 32 s, so a 30 s lock could expire
     * mid-fetch and let a second request stampede. 60 s comfortably covers the
     * combined worst case (plus map-building overhead) while still self-healing
     * within a minute if the holder dies.
     */
    private const COLD_LOCK_SECONDS = 60;

    /**
     * How long a concurrent cold request will block waiting for the
     * in-flight fetch before giving up and telling the client to retry.
     * Kept short so PHP-FPM workers aren't tied up for the full fetch.
     */
    private const COLD_WAIT_SECONDS = 5;

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
        $actor->assertRegistered();

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
                // Guarded by a single-flight lock so N concurrent cold
                // requests don't each spawn their own CFBD + On3 round-trip.
                return $this->serveCold($cacheKey, $apiKey, $year, $team, $maxRecruits);
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
     * Cold-cache path with single-flight protection. At most one request
     * runs the inline CFBD + On3 fetch for a given query; concurrent callers
     * wait briefly for it to populate the cache and serve that, or return a
     * 202 "warming_up" so the frontend retries — never a stampede.
     *
     * Prefers an atomic cache lock (auto-releases on crash, lets waiters
     * block). Falls back to an add()-based mutex on the rare store that
     * doesn't provide atomic locks.
     */
    private function serveCold(string $cacheKey, string $apiKey, string $year, string $team, int $maxRecruits): ResponseInterface
    {
        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            $lock = $this->cache->lock('ernestdefoe-recruiting.cold.' . $cacheKey, self::COLD_LOCK_SECONDS);

            try {
                // Block until it's our turn (or the fetch is taking too long).
                $lock->block(self::COLD_WAIT_SECONDS);
            } catch (LockTimeoutException $e) {
                // Another request is mid-fetch and slow — serve whatever it
                // has produced so far, else ask the client to retry.
                return $this->serveFreshOrRetry($cacheKey, $year);
            }

            try {
                // The previous holder may have already populated the cache.
                $cached = $this->cache->get($cacheKey);
                if (is_array($cached) && isset($cached['data'], $cached['fetched_at'])) {
                    return $this->jsonRecruits($cached['data'], $year);
                }

                return $this->serveInline($cacheKey, $apiKey, $year, $team, $maxRecruits);
            } finally {
                $lock->release();
            }
        }

        // Fallback single-flight for stores without atomic locks.
        $mutexKey = $cacheKey . '.cold';
        if (! $this->cache->add($mutexKey, '1', self::COLD_LOCK_SECONDS)) {
            return $this->serveFreshOrRetry($cacheKey, $year);
        }

        try {
            return $this->serveInline($cacheKey, $apiKey, $year, $team, $maxRecruits);
        } finally {
            $this->cache->forget($mutexKey);
        }
    }

    /**
     * Re-read the cache (the in-flight cold fetch may have finished) and
     * serve it if present, otherwise return a 202 telling the frontend to
     * retry shortly rather than launching a competing fetch.
     */
    private function serveFreshOrRetry(string $cacheKey, string $year): ResponseInterface
    {
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['data'], $cached['fetched_at'])) {
            return $this->jsonRecruits($cached['data'], $year);
        }

        return new JsonResponse([
            'data'  => [],
            'year'  => (int) $year,
            'error' => 'warming_up',
        ], 202);
    }

    /**
     * First-ever request (or a hard cache eviction) — fetch CFBD
     * inline, populate the envelope, return the result. This is the
     * only path that pays the API round-trip on the request thread.
     */
    private function serveInline(string $cacheKey, string $apiKey, string $year, string $team, int $maxRecruits): ResponseInterface
    {
        $data = $this->cfbd->fetchRecruits($apiKey, $year, $team, $maxRecruits);

        // Pre-enrich before storing so every subsequent serve-from-cache
        // path (including the stale-while-revalidate branch above) reads
        // ready-to-render data and never blocks on the On3 scrape.
        $data = $this->photos->enrich($data, $year);

        $this->cache->put($cacheKey, [
            'data'       => $data,
            'fetched_at' => time(),
        ], self::HARD_RETENTION_SECONDS);

        return $this->jsonRecruits($data, $year);
    }

    /**
     * Frame the JSON response. Photo enrichment already happened at
     * cache-write time (RefreshRecruitsJob or serveInline), so we serve
     * the cached payload verbatim — no inline On3 fetch on the request
     * path.
     */
    private function jsonRecruits(array $data, string $year): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'year' => (int) $year,
        ]);
    }
}
