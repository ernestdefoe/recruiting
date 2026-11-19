<?php

namespace Ernestdefoe\Recruiting\Api\Controller;

use Ernestdefoe\Recruiting\Job\RefreshRecruitsJob;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Queue;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /api/cfbd-recruits.
 *
 * Serves the recruiting class from the cache and never calls CFBD or On3
 * itself: every fetch is RefreshRecruitsJob's (and RefreshPhotosJob's).
 * This is a custom handler, not an API resource, because the data is a
 * cached copy of a third-party API, not database records.
 *
 * Cache shape:
 *   $cacheKey -> ['data' => list<recruit>, 'fetched_at' => unix ts]
 *
 *   Kept for RefreshRecruitsJob::RETENTION_SECONDS (7 days), far longer
 *   than the soft TTL (default 6 hours), so a string of CFBD outages
 *   doesn't blank the page.
 *
 * Paths:
 *   - fresh: served as is;
 *   - stale: served as is, and a refresh is queued;
 *   - cold (nothing cached): a refresh is queued and the frontend is told
 *     to retry (202 warming_up) until the envelope exists.
 *
 *   A short "refreshing" cache key (atomic add) makes sure concurrent
 *   requests queue one refresh, not one each. With a real queue driver the
 *   push returns at once; on Flarum's default sync driver the job runs
 *   inside the push, so the cold request is answered with the data.
 */
class RecruitingController implements RequestHandlerInterface
{
    /** Don't queue another refresh while one is in flight. */
    private const REFRESH_LOCK_SECONDS = 90;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private CacheRepository $cache,
        private LoggerInterface $log,
        private Queue $queue,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // The board is public data, cached once for everyone, and the page and
        // its nav link are shown to guests. Gate it on the forum's own view
        // permission so a forum closed to guests stays closed.
        RequestUtil::getActor($request)->assertCan('viewForum');

        $apiKey = trim((string) $this->settings->get('ernestdefoe-recruiting.api_key'));
        $year = trim((string) $this->settings->get('ernestdefoe-recruiting.year'));
        $year = preg_match('/^\d{4}$/', $year) ? $year : date('Y');

        if ($apiKey === '') {
            return $this->json([], $year, 'api_key_missing');
        }

        $team = trim((string) $this->settings->get('ernestdefoe-recruiting.team'));
        $maxRecruits = max(1, min(100, (int) $this->settings->get('ernestdefoe-recruiting.max_recruits')));
        $softTtl = max(60, (int) $this->settings->get('ernestdefoe-recruiting.cache_minutes') * 60);
        $cacheKey = 'ernestdefoe-recruiting.'.md5("{$year}|{$team}|{$maxRecruits}");

        try {
            $cached = $this->envelope($cacheKey);

            if ($cached !== null) {
                if (time() - (int) $cached['fetched_at'] > $softTtl) {
                    $this->queueRefresh($cacheKey, $year, $team, $maxRecruits);
                }

                return $this->json($cached['data'], $year);
            }

            // Cold. A fetch that just failed is not retried on every visit;
            // the failure is keyed by the API key too, so fixing the key in
            // the admin panel takes effect at once.
            $failureKey = RefreshRecruitsJob::failureKey($cacheKey, $apiKey);
            if (! $this->cache->has($failureKey)) {
                $this->queueRefresh($cacheKey, $year, $team, $maxRecruits);
            }

            return $this->serveCold($cacheKey, $failureKey, $year);
        } catch (\Throwable $e) {
            $this->log->error('[recruiting] RecruitingController: '.$e->getMessage(), ['exception' => $e]);

            return $this->json([], $year, 'unexpected_error');
        }
    }

    /**
     * After the cold path queued its refresh: the data if the job already
     * ran (sync driver), its error code if it failed, else a 202 so the
     * frontend retries shortly.
     */
    private function serveCold(string $cacheKey, string $failureKey, string $year): ResponseInterface
    {
        $cached = $this->envelope($cacheKey);
        if ($cached !== null) {
            return $this->json($cached['data'], $year);
        }

        $failed = $this->cache->get($failureKey);
        if (is_string($failed) && $failed !== '') {
            return $this->json([], $year, $failed);
        }

        return new JsonResponse(['data' => [], 'year' => (int) $year, 'error' => 'warming_up'], 202);
    }

    /** Queue one refresh for this query, unless one is already in flight. */
    private function queueRefresh(string $cacheKey, string $year, string $team, int $maxRecruits): void
    {
        if ($this->cache->add($cacheKey.'.refreshing', '1', self::REFRESH_LOCK_SECONDS)) {
            $this->queue->push(new RefreshRecruitsJob($cacheKey, $year, $team, $maxRecruits));
        }
    }

    /** @return array{data: list<array<string, mixed>>, fetched_at: int}|null */
    private function envelope(string $cacheKey): ?array
    {
        $cached = $this->cache->get($cacheKey);

        return is_array($cached) && isset($cached['data'], $cached['fetched_at']) ? $cached : null;
    }

    private function json(array $data, string $year, ?string $error = null): JsonResponse
    {
        return new JsonResponse(['data' => $data, 'year' => (int) $year] + ($error !== null ? ['error' => $error] : []));
    }
}
