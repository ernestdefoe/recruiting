<?php

namespace Ernestdefoe\Recruiting\Job;

use Ernestdefoe\Recruiting\Service\CfbdClient;
use Ernestdefoe\Recruiting\Service\CfbdException;
use Ernestdefoe\Recruiting\Service\On3PhotoEnricher;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;

/**
 * Pulls a recruiting class from CFBD and writes the cache envelope
 * (data + fetched_at) that RecruitingBoards serves.
 *
 * Headshots come only from an already-cached On3 map here. When the map
 * isn't cached, the envelope is written without photos and
 * RefreshPhotosJob is queued to fetch the map and attach them, so the
 * On3 scrape is never part of this fetch.
 *
 * The CFBD API key is read from settings in handle(), never passed in:
 * job properties are serialized into the queue (jobs table, Redis), and
 * the key must not be stored there in plain text.
 */
class RefreshRecruitsJob extends AbstractJob
{
    /** Hard cache retention. Stale data is still better than no data. */
    public const RETENTION_SECONDS = 7 * 24 * 3600;

    /** How long a failed fetch is remembered (and not retried). */
    public const FAILURE_SECONDS = 300;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        private readonly string $cacheKey,
        private readonly string $year,
        private readonly string $team,
        private readonly int $maxRecruits,
    ) {
        parent::__construct();
    }

    /** Where a failed fetch's error code is kept, per query and per key. */
    public static function failureKey(string $cacheKey, string $apiKey): string
    {
        return $cacheKey.'.failed.'.md5($apiKey);
    }

    public function handle(
        CfbdClient $cfbd,
        On3PhotoEnricher $photos,
        CacheRepository $cache,
        LoggerInterface $log,
        SettingsRepositoryInterface $settings,
        Queue $queue,
    ): void {
        $apiKey = trim((string) $settings->get('ernestdefoe-recruiting.api_key'));

        // The key was removed after this job was queued: nothing to fetch with.
        if ($apiKey === '') {
            return;
        }

        try {
            $data = $cfbd->fetchRecruits($apiKey, $this->year, $this->team, $this->maxRecruits);
        } catch (CfbdException $e) {
            $log->warning('[recruiting] CFBD fetch failed: '.$e->getMessage(), ['year' => $this->year, 'team' => $this->team]);

            // The last good envelope keeps being served. The error code is
            // what a cold page shows, and RecruitingBoards' refresh gate is
            // held so an outage is retried every few minutes, not per view.
            $cache->put(self::failureKey($this->cacheKey, $apiKey), $e->getMessage(), self::FAILURE_SECONDS);
            $cache->put($this->cacheKey.'.refreshing', '1', self::FAILURE_SECONDS);

            return;
        }

        $map = $photos->enabled() ? $photos->cachedMap($this->year) : [];

        $cache->put($this->cacheKey, [
            'data' => $map ? $photos->apply($data, $map) : $data,
            'fetched_at' => time(),
        ], self::RETENTION_SECONDS);
        $cache->forget(self::failureKey($this->cacheKey, $apiKey));

        if ($map === null) {
            $queue->push(new RefreshPhotosJob($this->cacheKey, $this->year));
        }
    }
}
