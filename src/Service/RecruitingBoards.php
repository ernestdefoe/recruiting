<?php

namespace Ernestdefoe\Recruiting\Service;

use Ernestdefoe\Recruiting\Job\RefreshRecruitsJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;

/**
 * Reads the configured recruiting class from the cache, and never calls
 * CFBD or On3 itself: every fetch is RefreshRecruitsJob's (and
 * RefreshPhotosJob's).
 *
 * Cache shape:
 *   $cacheKey -> ['data' => list<recruit>, 'fetched_at' => unix ts]
 *
 *   Kept for RefreshRecruitsJob::RETENTION_SECONDS (7 days), far longer
 *   than the soft TTL (default 6 hours), so a string of CFBD outages
 *   doesn't blank the page.
 *
 * States:
 *   - fresh: served as is;
 *   - stale: served as is, and a refresh is queued;
 *   - cold (nothing cached): a refresh is queued and the error is
 *     `warming_up`, which the frontend retries until the envelope exists.
 *
 *   A short "refreshing" cache key (atomic add) makes sure concurrent
 *   requests queue one refresh, not one each. With a real queue driver the
 *   push returns at once; on Flarum's default sync driver the job runs
 *   inside the push, so the cold request is answered with the data.
 */
class RecruitingBoards
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

    /**
     * @return array{year: int, recruits: list<array<string, mixed>>, error: string|null}
     */
    public function load(): array
    {
        $apiKey = trim((string) $this->settings->get('ernestdefoe-recruiting.api_key'));
        $year = trim((string) $this->settings->get('ernestdefoe-recruiting.year'));
        $year = preg_match('/^\d{4}$/', $year) ? $year : date('Y');

        if ($apiKey === '') {
            return $this->board([], $year, 'api_key_missing');
        }

        $team = trim((string) $this->settings->get('ernestdefoe-recruiting.team'));
        $maxRecruits = max(1, min(100, (int) $this->settings->get('ernestdefoe-recruiting.max_recruits')));
        $softTtl = max(60, (int) $this->settings->get('ernestdefoe-recruiting.cache_minutes') * 60);
        $cacheKey = self::cacheKey($year, $team, $maxRecruits, (bool) $this->settings->get('ernestdefoe-recruiting.photos_enabled'));

        try {
            $cached = $this->envelope($cacheKey);

            if ($cached !== null) {
                if (time() - (int) $cached['fetched_at'] > $softTtl) {
                    $this->queueRefresh($cacheKey, $year, $team, $maxRecruits);
                }

                return $this->board($cached['data'], $year);
            }

            // Cold. A fetch that just failed is not retried on every visit;
            // the failure is keyed by the API key too, so fixing the key in
            // the admin panel takes effect at once.
            $failureKey = RefreshRecruitsJob::failureKey($cacheKey, $apiKey);
            if (! $this->cache->has($failureKey)) {
                $this->queueRefresh($cacheKey, $year, $team, $maxRecruits);
            }

            return $this->cold($cacheKey, $failureKey, $year);
        } catch (\Throwable $e) {
            $this->log->error('[recruiting] loading the board failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->board([], $year, 'unexpected_error');
        }
    }

    /**
     * One envelope per query. The photo setting is part of the key, so
     * switching headshots off or on takes effect at once.
     */
    public static function cacheKey(string $year, string $team, int $maxRecruits, bool $photos): string
    {
        return 'ernestdefoe-recruiting.'.md5("{$year}|{$team}|{$maxRecruits}|".($photos ? 'photos' : 'initials'));
    }

    /**
     * After the cold path queued its refresh: the data if the job already
     * ran (sync driver), its error code if it failed, else `warming_up`.
     */
    private function cold(string $cacheKey, string $failureKey, string $year): array
    {
        $cached = $this->envelope($cacheKey);
        if ($cached !== null) {
            return $this->board($cached['data'], $year);
        }

        $failed = $this->cache->get($failureKey);

        return $this->board([], $year, is_string($failed) && $failed !== '' ? $failed : 'warming_up');
    }

    /**
     * Queue one refresh for this query, unless one is already in flight. On
     * the sync driver the job runs right here, so a failure is logged and
     * never costs the visitor the data that is already cached.
     */
    private function queueRefresh(string $cacheKey, string $year, string $team, int $maxRecruits): void
    {
        if (! $this->cache->add($cacheKey.'.refreshing', '1', self::REFRESH_LOCK_SECONDS)) {
            return;
        }

        try {
            $this->queue->push(new RefreshRecruitsJob($cacheKey, $year, $team, $maxRecruits));
        } catch (\Throwable $e) {
            $this->log->error('[recruiting] queueing a refresh failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /** @return array{data: list<array<string, mixed>>, fetched_at: int}|null */
    private function envelope(string $cacheKey): ?array
    {
        $cached = $this->cache->get($cacheKey);

        return is_array($cached) && isset($cached['data'], $cached['fetched_at']) ? $cached : null;
    }

    private function board(array $recruits, string $year, ?string $error = null): array
    {
        return ['year' => (int) $year, 'recruits' => $recruits, 'error' => $error];
    }
}
