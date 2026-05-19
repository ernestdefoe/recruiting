<?php

namespace Ernestdefoe\Recruiting\Job;

use Ernestdefoe\Recruiting\Service\CfbdClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * Pulls a recruiting class from CFBD and writes it to the cache
 * envelope (data + fetched_at) so subsequent requests can serve
 * fresh data without blocking on the API.
 *
 * Dispatched by RecruitingController whenever cached data is past
 * its soft TTL. On Flarum's default `sync` queue driver the job
 * still runs inline (no win) — operators that configure a real
 * queue driver (database, redis, sqs) get true out-of-band refresh
 * and the request thread is freed instantly.
 *
 * Single-flight is enforced by RecruitingController via a short
 * cache lock; the job itself trusts that gate.
 */
class RefreshRecruitsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries   = 1;
    public int $timeout = 60;

    public function __construct(
        private readonly string $cacheKey,
        private readonly string $apiKey,
        private readonly string $year,
        private readonly string $team,
        private readonly int    $maxRecruits,
        private readonly int    $retentionSeconds,
    ) {}

    public function handle(
        CfbdClient      $cfbd,
        CacheRepository $cache,
        LoggerInterface $log,
    ): void {
        try {
            $data = $cfbd->fetchRecruits($this->apiKey, $this->year, $this->team, $this->maxRecruits);
        } catch (\Throwable $e) {
            // Don't overwrite the existing cache envelope with empty
            // data on a CFBD blip — the operator wants the last good
            // payload to keep serving while we recover. Just log.
            $log->warning('[recruiting] RefreshRecruitsJob: CFBD fetch failed', [
                'year'      => $this->year,
                'team'      => $this->team,
                'exception' => $e,
            ]);
            return;
        }

        // Cache envelope retention is much longer than the soft TTL
        // (controller default: 6 hours soft, 7 days hard). Stale
        // data can still be served if every refresh attempt fails
        // — better than an empty widget.
        $cache->put($this->cacheKey, [
            'data'       => $data,
            'fetched_at' => time(),
        ], $this->retentionSeconds);
    }
}
