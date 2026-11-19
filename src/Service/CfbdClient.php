<?php

namespace Ernestdefoe\Recruiting\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin client over the College Football Data API recruiting/players
 * endpoint. Knows how to authenticate, query, and map CFBD's JSON
 * shape into the internal recruit array the rest of the extension
 * uses.
 *
 * The ClientInterface is given to this class alone by a contextual
 * binding in RecruitingServiceProvider; timeouts are set per request.
 */
class CfbdClient
{
    private const BASE_URL = 'https://api.collegefootballdata.com';
    private const USER_AGENT = 'FBSFB/1.0 (Flarum extension)';

    /** Per-request timeouts (seconds). */
    private const TIMEOUT = 10;
    private const CONNECT_TIMEOUT = 5;

    public function __construct(private ClientInterface $http)
    {
    }

    /**
     * Fetch + transform a single recruiting class.
     *
     * @return list<array<string, mixed>>
     * @throws CfbdException with a stable code as its message:
     *   cfbd_unreachable, invalid_api_key, cfbd_error_<status>
     */
    public function fetchRecruits(string $apiKey, string $year, string $team, int $maxRecruits): array
    {
        $query = ['year' => $year];
        if ($team !== '') {
            $query['team'] = $team;
        }

        try {
            $response = $this->http->request('GET', self::BASE_URL.'/recruiting/players', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
                'query' => $query,
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            // Connection failures and timeouts included (ConnectException
            // is not a RequestException).
            throw new CfbdException('cfbd_unreachable', 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new CfbdException('invalid_api_key');
        }
        if ($status !== 200) {
            throw new CfbdException('cfbd_error_'.$status);
        }

        $raw = json_decode((string) $response->getBody(), true);
        if (! is_array($raw)) {
            return [];
        }

        usort($raw, fn ($a, $b) => ($a['ranking'] ?? 99999) <=> ($b['ranking'] ?? 99999));

        return array_values(array_map(
            fn ($r) => $this->transform($r),
            array_slice($raw, 0, $maxRecruits)
        ));
    }

    /**
     * Project a raw CFBD player object into the extension's stable
     * shape. Defensive against missing fields — CFBD has historically
     * shipped player rows with partial data and we'd rather render
     * "—" than crash the page.
     */
    private function transform(array $r): array
    {
        $heightIn = isset($r['height']) ? (int) $r['height'] : null;
        $height = $heightIn ? sprintf("%d'%d\"", intdiv($heightIn, 12), $heightIn % 12) : null;

        $city = $r['city'] ?? null;
        $state = $r['stateProvince'] ?? null;
        $hometown = implode(', ', array_filter([$city, $state])) ?: null;

        $committedTo = isset($r['committedTo']) && $r['committedTo'] !== ''
            ? (string) $r['committedTo']
            : null;

        return [
            'id' => $r['id'] ?? null,
            'name' => $r['name'] ?? 'Unknown',
            'position' => isset($r['position']) ? strtoupper((string) $r['position']) : null,
            'height' => $height,
            'weight' => isset($r['weight']) ? ((int) $r['weight']).' lbs' : null,
            'hometown' => $hometown,
            'country' => isset($r['country']) && $r['country'] !== 'USA' ? (string) $r['country'] : null,
            'stars' => isset($r['stars']) ? (int) $r['stars'] : null,
            'rating' => isset($r['rating']) ? round((float) $r['rating'], 4) : null,
            'ranking' => isset($r['ranking']) ? (int) $r['ranking'] : null,
            'status' => $committedTo ? 'committed' : 'undecided',
            'school' => $committedTo,
            'highSchool' => $r['school'] ?? null,
            'recruitType' => $r['recruitType'] ?? 'HighSchool',
            'photoUrl' => null, // filled in by On3PhotoEnricher
        ];
    }
}
