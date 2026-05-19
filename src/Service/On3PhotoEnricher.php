<?php

namespace Ernestdefoe\Recruiting\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
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
 *   24 hours; an empty map (failed scrape) is NOT cached so the next
 *   request retries.
 *
 * Carved out of the original RecruitingController so the scrape +
 * parse + match steps can evolve independently (and so the
 * controller stops being responsible for HTML regex).
 */
class On3PhotoEnricher
{
    private const RANKINGS_URL = 'https://www.on3.com/rivals/rankings/player/football/';
    private const STATIC_HOST  = 'https://on3static.com';
    private const CACHE_TTL    = 24 * 3600;

    /** Browser-like UA so On3 serves full server-rendered HTML. */
    private const SCRAPE_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/124.0.0.0 Safari/537.36';

    public function __construct(
        private CacheRepository $cache,
        private LoggerInterface $log,
        private ?ClientInterface $http = null,
    ) {
        $this->http ??= new Client([
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
    }

    /**
     * Attach photoUrl to every recruit by looking up the name-slug
     * in the cached On3 image map.
     *
     * @param list<array<string, mixed>> $recruits
     * @return list<array<string, mixed>>
     */
    public function enrich(array $recruits, string $year): array
    {
        $imageMap = $this->imageMap($year);
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
     * Fetch the year's image map, cached for 24 h. Empty maps are
     * NOT cached — a failed scrape retries on the next call.
     *
     * @return array<string, string>
     */
    private function imageMap(string $year): array
    {
        $cacheKey = "ernestdefoe-recruiting.on3map.football.{$year}";

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $map = $this->build($year);
        if (! empty($map)) {
            $this->cache->put($cacheKey, $map, self::CACHE_TTL);
        }
        return $map;
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
            $response = $this->http->request('GET', self::RANKINGS_URL . $year . '/');
        } catch (\Throwable $e) {
            $this->log->warning('[recruiting] On3 rankings fetch failed', ['exception' => $e]);
            return [];
        }

        if ($response->getStatusCode() !== 200) {
            $this->log->warning('[recruiting] On3 rankings HTTP ' . $response->getStatusCode());
            return [];
        }

        $map = $this->parse((string) $response->getBody());

        $this->log->info('[recruiting] On3 image map built', [
            'year'    => $year,
            'players' => count($map),
        ]);

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
                    . '(/uploads/assets/\d+/\d+/\d+\.(?:jpg|jpeg|png|webp))~i';

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

        $map  = [];
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
                $map[$nameSlug] = self::STATIC_HOST . $bestPath;
            }
        }

        return $map;
    }

    /**
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
