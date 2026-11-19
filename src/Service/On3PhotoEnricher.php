<?php

namespace Ernestdefoe\Recruiting\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * Augments recruit rows with headshot URLs scraped from On3's
 * rankings page.
 *
 * Strategy:
 *   Fetch https://www.on3.com/rivals/rankings/player/football/{year}/
 *   once. The page is server-rendered HTML with ~150 players, each
 *   with a profile href (`/rivals/{slug}-{id}/`) and an on3static.com
 *   image. We build a `name-slug → image-URL` map and cache it for
 *   24 hours; an empty map (failed scrape) is cached for an hour so the
 *   refresh retries. The fetch runs only in RefreshPhotosJob: in the
 *   queue worker, or inline in the request that queued it on Flarum's
 *   sync driver.
 */
class On3PhotoEnricher
{
    private const RANKINGS_URL = 'https://www.on3.com/rivals/rankings/player/football/';
    private const STATIC_HOST = 'https://on3static.com';
    private const CACHE_TTL = 24 * 3600;
    private const FAILURE_TTL = 3600;

    /** Per-request timeouts (seconds) for the rankings fetch. */
    private const TIMEOUT = 12;
    private const CONNECT_TIMEOUT = 5;

    /**
     * Honest User-Agent identifying this extension. We deliberately do NOT
     * spoof a browser UA: scraping with a forged identity violates On3's
     * ToS and risks the forum's IP being WAF-blocked. If On3 stops serving
     * usable HTML to this UA, operators should disable photo enrichment
     * (the `photos_enabled` setting) rather than re-introduce spoofing.
     */
    private const USER_AGENT = 'ernestdefoe-recruiting (Flarum extension; +https://github.com/ernestdefoe/recruiting)';

    /**
     * A healthy rankings page yields ~150 players. Fewer than this after a
     * successful (HTTP 200) fetch signals the page layout changed and the
     * proximity parser is silently failing — worth a warning to the log.
     */
    private const MIN_EXPECTED_PLAYERS = 10;

    /** Persisted setting keys for the admin "last scrape" status display. */
    private const LAST_SCRAPE_KEY = 'ernestdefoe-recruiting.on3_last_scrape';
    private const LAST_COUNT_KEY = 'ernestdefoe-recruiting.on3_last_count';

    public function __construct(
        private CacheRepository $cache,
        private LoggerInterface $log,
        private ClientInterface $http,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * Whether On3 photo enrichment is on. Operators can switch it off to
     * stop all outbound On3 traffic; recruits then render with star-tier
     * initials avatars.
     */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('ernestdefoe-recruiting.photos_enabled');
    }

    /**
     * The year's cached image map, or null when it isn't cached. Never
     * fetches, so it is safe anywhere.
     *
     * @return array<string, string>|null
     */
    public function cachedMap(string $year): ?array
    {
        $cached = $this->cache->get($this->mapKey($year));

        return is_array($cached) ? $cached : null;
    }

    /**
     * Fetch the rankings page and cache its image map for 24 h. Outbound
     * HTTP (up to ~17 s), so it runs only in RefreshPhotosJob. A failed
     * scrape is remembered for an hour (an empty map), so an On3 outage is
     * not retried on every refresh.
     *
     * @return array<string, string>
     */
    public function refreshMap(string $year): array
    {
        $map = $this->build($year);
        $this->cache->put($this->mapKey($year), $map, $map === [] ? self::FAILURE_TTL : self::CACHE_TTL);

        return $map;
    }

    /**
     * Attach photoUrl to every recruit by looking up its name-slug.
     *
     * @param list<array<string, mixed>> $recruits
     * @param array<string, string> $map
     * @return list<array<string, mixed>>
     */
    public function apply(array $recruits, array $map): array
    {
        return array_map(function ($r) use ($map) {
            $r['photoUrl'] = $map[$this->nameToSlug((string) ($r['name'] ?? ''))] ?? null;

            return $r;
        }, $recruits);
    }

    private function mapKey(string $year): string
    {
        return "ernestdefoe-recruiting.on3map.football.{$year}";
    }

    /**
     * Fetch + parse the rankings page. Returns empty array on any
     * network or parse failure — graceful degradation: the rest of
     * the response still renders without headshots.
     *
     * @return array<string, string>
     */
    private function build(string $year): array
    {
        try {
            $response = $this->http->request('GET', self::RANKINGS_URL.$year.'/', [
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'allow_redirects' => ['max' => 5],
                'http_errors' => false,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->log->warning('[recruiting] On3 rankings fetch failed', ['exception' => $e]);

            return [];
        }

        if ($response->getStatusCode() !== 200) {
            $this->log->warning('[recruiting] On3 rankings HTTP '.$response->getStatusCode());

            return [];
        }

        $map = $this->parse((string) $response->getBody());
        $count = count($map);

        if ($count > 0) {
            // Record a successful scrape so the admin panel can show when
            // headshots were last refreshed.
            $this->recordScrape($count);
        }

        if ($count < self::MIN_EXPECTED_PLAYERS) {
            // Fetch succeeded (HTTP 200) but the proximity parser found
            // almost nothing — On3's page layout has likely changed and is
            // silently breaking headshots. Surface it instead of failing mute.
            $this->log->warning('[recruiting] On3 rankings parsed only '.$count
                .' players (expected >= '.self::MIN_EXPECTED_PLAYERS
                .') — On3 page layout may have changed', ['year' => $year]);
        } else {
            $this->log->info('[recruiting] On3 image map built', [
                'year' => $year,
                'players' => $count,
            ]);
        }

        return $map;
    }

    /**
     * Parse the rankings HTML into a slug → CDN URL map.
     *
     * Strategy:
     *  1. Collect all on3static.com player image positions.
     *  2. Collect all /rivals/{name}-{id}/ profile-path positions.
     *  3. For each unique profile path, find the closest image
     *     (≤ 5 000 chars).
     *  4. Store as nameSlug → full CDN URL (cdn-cgi resize prefix
     *     stripped).
     *
     * @return array<string, string>
     */
    private function parse(string $html): array
    {
        $imgPattern = '~https://on3static\.com(?:/cdn-cgi/image/[^\s"\'>\]]+)?'
                    .'(/uploads/assets/\d+/\d+/\d+\.(?:jpg|jpeg|png|webp))~i';

        preg_match_all($imgPattern, $html, $imgAll, PREG_OFFSET_CAPTURE);
        preg_match_all(
            '~/rivals/([a-z0-9][a-z0-9\-]+)-(\d{4,})/~i',
            $html,
            $hrefAll,
            PREG_OFFSET_CAPTURE
        );

        if (empty($imgAll[0]) || empty($hrefAll[0])) {
            return [];
        }

        $map = [];
        $seen = [];

        foreach ($hrefAll[0] as $i => [, $hrefPos]) {
            $nameSlug = $hrefAll[1][$i][0];

            if (empty($nameSlug) || isset($seen[$nameSlug])) {
                continue;
            }
            $seen[$nameSlug] = true;

            $bestDist = PHP_INT_MAX;
            $bestPath = null;

            foreach ($imgAll[0] as $j => [, $imgPos]) {
                $dist = abs($hrefPos - $imgPos);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestPath = $imgAll[1][$j][0];
                }
            }

            if ($bestPath !== null && $bestDist < 5000) {
                $map[$nameSlug] = self::STATIC_HOST.$bestPath;
            }
        }

        return $map;
    }

    /**
     * "Jared Curtis" → "jared-curtis"
     * "C.J. Stroud"  → "cj-stroud".
     */
    private function nameToSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Persist the timestamp + player count of the last successful scrape so
     * the admin panel can show "last refreshed" and operators can tell at a
     * glance whether On3 enrichment is still working.
     */
    private function recordScrape(int $count): void
    {
        $this->settings->set(self::LAST_SCRAPE_KEY, (string) time());
        $this->settings->set(self::LAST_COUNT_KEY, (string) $count);
    }
}
