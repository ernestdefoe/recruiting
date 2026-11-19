<?php

namespace Ernestdefoe\Recruiting\Job;

use Ernestdefoe\Recruiting\Service\On3PhotoEnricher;
use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Fetches the year's On3 image map and attaches headshots to the cached
 * recruits. Queued by RefreshRecruitsJob when the map isn't cached, so
 * the On3 scrape (up to ~17 s) is a job of its own, run after the CFBD
 * data is cached: by the queue worker, or inline on the sync driver.
 */
class RefreshPhotosJob extends AbstractJob
{
    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        private readonly string $cacheKey,
        private readonly string $year,
    ) {
        parent::__construct();
    }

    public function handle(On3PhotoEnricher $photos, CacheRepository $cache): void
    {
        if (! $photos->enabled()) {
            return;
        }

        $map = $photos->cachedMap($this->year) ?? $photos->refreshMap($this->year);
        $envelope = $cache->get($this->cacheKey);

        if ($map === [] || ! is_array($envelope) || ! isset($envelope['data'], $envelope['fetched_at'])) {
            return;
        }

        // fetched_at is kept: photos don't make the CFBD data any fresher.
        $envelope['data'] = $photos->apply($envelope['data'], $map);
        $cache->put($this->cacheKey, $envelope, RefreshRecruitsJob::RETENTION_SECONDS);
    }
}
