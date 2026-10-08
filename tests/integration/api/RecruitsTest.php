<?php

namespace Ernestdefoe\Recruiting\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/cfbd-recruits: the forum-side proxy to the College Football Data
 * API, with its cache. No test reaches the network: the shared HTTP client is
 * swapped for one that answers from a queue and records what was asked.
 */
class RecruitsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    private MockHandler $responses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-recruiting');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
        ]);

        $this->setting('ernestdefoe-recruiting.year', '2027');
        // Headshots are a second outbound request; on unless a test says so.
        $this->setting('ernestdefoe-recruiting.photos_enabled', '0');

        $this->responses = new MockHandler();
    }

    private function boot(): void
    {
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->sent));

        $this->app()->getContainer()->instance(ClientInterface::class, new Client(['handler' => $stack, 'http_errors' => false]));
    }

    private function recruits(?int $actor = 2): array
    {
        $this->boot();

        $response = $this->send($this->request('GET', '/api/cfbd-recruits', $actor ? ['authenticatedAs' => $actor] : []));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function cfbd(array $players, int $status = 200): void
    {
        $this->responses->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($players)));
    }

    private function cacheKey(string $team = '', int $max = 25): string
    {
        return 'ernestdefoe-recruiting.'.md5("2027|{$team}|{$max}");
    }

    private function player(int $id, int $ranking, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'athleteId' => 1000 + $id, 'name' => "Player $id", 'position' => 'qb', 'height' => 75,
            'weight' => 210, 'city' => 'Austin', 'stateProvince' => 'TX', 'country' => 'USA', 'stars' => 5,
            'rating' => 0.99871, 'ranking' => $ranking, 'committedTo' => 'Texas', 'school' => 'Westlake',
        ];
    }

    #[Test]
    public function guests_cannot_read_recruits()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');

        $this->cfbd([$this->player(1, 1)]);

        [$status] = $this->recruits(null);

        $this->assertSame(401, $status);
        $this->assertCount(1, $this->responses, 'CFBD was never asked');
    }

    #[Test]
    public function without_a_key_it_says_so_and_calls_nobody()
    {
        $this->cfbd([$this->player(1, 1)]);

        [$status, $body] = $this->recruits();

        $this->assertSame(200, $status);
        $this->assertSame('api_key_missing', $body['error']);
        $this->assertCount(1, $this->responses, 'CFBD was never asked');
    }

    #[Test]
    public function a_cold_cache_fetches_the_class_ranked_and_capped()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');
        $this->setting('ernestdefoe-recruiting.team', 'Texas');
        $this->setting('ernestdefoe-recruiting.max_recruits', '2');
        $this->cfbd([$this->player(3, 30), $this->player(1, 1, ['committedTo' => '', 'country' => 'Canada']), $this->player(2, 7)]);

        foreach ([2, 1] as $actor) {
            [$status, $body] = $this->recruits($actor);

            $this->assertSame(200, $status);
            $this->assertSame(2027, $body['year']);
            $this->assertSame([1, 2], array_column($body['data'], 'id'), 'Sorted by ranking and capped at max_recruits');
        }

        $this->assertCount(1, $this->sent, 'The second visitor is served from the cache');

        $request = $this->sent[0]['request'];
        $this->assertSame('/recruiting/players', $request->getUri()->getPath());
        $this->assertSame('year=2027&team=Texas', $request->getUri()->getQuery());
        $this->assertSame('Bearer KEY', $request->getHeaderLine('Authorization'));

        $this->assertSame([
            'id' => 1, 'athleteId' => 1001, 'name' => 'Player 1', 'position' => 'QB', 'height' => "6'3\"",
            'weight' => '210 lbs', 'city' => 'Austin', 'state' => 'TX', 'hometown' => 'Austin, TX', 'country' => 'Canada',
            'stars' => 5, 'rating' => 0.9987, 'ranking' => 1, 'status' => 'undecided', 'school' => null,
            'highSchool' => 'Westlake', 'recruitType' => 'HighSchool', 'photoUrl' => null,
        ], $body['data'][0]);
        $this->assertSame('committed', $body['data'][1]['status']);
    }

    #[Test]
    public function a_rejected_key_is_reported_and_not_retried_on_every_visit()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'BADKEY');
        $this->cfbd(['message' => 'Unauthorized'], 401);

        [$status, $body] = $this->recruits();
        $this->assertSame(200, $status);
        $this->assertSame('invalid_api_key', $body['error']);

        [, $body] = $this->recruits();
        $this->assertSame('invalid_api_key', $body['error']);
        $this->assertCount(1, $this->sent, 'A failed fetch is remembered, not repeated per page view');
    }

    #[Test]
    public function stale_data_is_served_and_refreshed()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');
        $this->setting('ernestdefoe-recruiting.cache_minutes', '60');
        $this->cfbd([$this->player(9, 1, ['name' => 'New Name'])]);

        $this->app()->getContainer()->make(Repository::class)->put($this->cacheKey(), [
            'data' => [['id' => 9, 'name' => 'Old Name']],
            'fetched_at' => time() - 2 * 3600,
        ], 3600);

        [$status, $body] = $this->recruits();

        $this->assertSame(200, $status);
        $this->assertArrayNotHasKey('error', $body, json_encode($body));
        $this->assertSame('Old Name', $body['data'][0]['name'], 'The stale copy is served at once');

        // Flarum's default sync queue has run the refresh by now.
        $this->assertCount(1, $this->sent);
        [, $body] = $this->recruits();
        $this->assertSame('New Name', $body['data'][0]['name']);
    }

    #[Test]
    public function fresh_data_is_served_without_calling_cfbd()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');
        $this->cfbd([$this->player(9, 1)]);

        $this->app()->getContainer()->make(Repository::class)->put($this->cacheKey(), [
            'data' => [['id' => 9, 'name' => 'Cached']],
            'fetched_at' => time() - 60,
        ], 3600);

        [, $body] = $this->recruits();

        $this->assertSame('Cached', $body['data'][0]['name']);
        $this->assertCount(1, $this->responses, 'The queued CFBD response was never asked for');
    }

    #[Test]
    public function headshots_are_matched_by_name_when_photos_are_on()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');
        $this->setting('ernestdefoe-recruiting.photos_enabled', '1');
        $this->cfbd([$this->player(1, 1, ['name' => 'Jared Curtis']), $this->player(2, 2, ['name' => 'Nobody Known'])]);
        $this->responses->append(new Response(200, [], '<a href="/rivals/jared-curtis-123456/"><img src="https://on3static.com/cdn-cgi/image/width=80/uploads/assets/1/2/3.jpg"></a>'));

        [, $body] = $this->recruits();

        $this->assertSame('https://on3static.com/uploads/assets/1/2/3.jpg', $body['data'][0]['photoUrl']);
        $this->assertNull($body['data'][1]['photoUrl']);
        $this->assertSame('/rivals/rankings/player/football/2027/', $this->sent[1]['request']->getUri()->getPath());
    }

    #[Test]
    public function with_photos_off_on3_is_never_contacted()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');
        $this->cfbd([$this->player(1, 1)]);
        $this->responses->append(new Response(200, [], '<html></html>'));

        $this->recruits();

        $this->assertCount(1, $this->sent);
        $this->assertCount(1, $this->responses, 'The queued On3 page was never asked for');
        $this->assertSame('api.collegefootballdata.com', $this->sent[0]['request']->getUri()->getHost());
    }

    #[Test]
    public function the_widget_title_is_serialized_and_the_key_is_not()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'SECRETKEY');
        $this->setting('ernestdefoe-recruiting.widget_title', 'Longhorn Commits');

        $response = $this->send($this->request('GET', '/api', ['authenticatedAs' => 2]));
        $body = (string) $response->getBody();

        $this->assertSame('Longhorn Commits', json_decode($body, true)['data']['attributes']['ernestdefoe-recruiting.widget_title']);
        $this->assertStringNotContainsString('SECRETKEY', $body);
    }
}
