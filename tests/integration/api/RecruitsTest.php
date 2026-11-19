<?php

namespace Ernestdefoe\Recruiting\Tests\integration\api;

use Ernestdefoe\Recruiting\Job\RefreshPhotosJob;
use Ernestdefoe\Recruiting\Job\RefreshRecruitsJob;
use Ernestdefoe\Recruiting\Service\CfbdClient;
use Ernestdefoe\Recruiting\Service\On3PhotoEnricher;
use Ernestdefoe\Recruiting\Service\RecruitingBoards;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\Group\Group;
use Flarum\User\User;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SyncQueue;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/recruiting-boards/1: the forum-side proxy to the College Football Data
 * API, with its cache. No test reaches the network: the extension's HTTP client
 * is swapped for one that answers from a queue and records what was asked.
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

        $client = new Client(['handler' => $stack, 'http_errors' => false]);
        $this->app()->getContainer()
            ->when([CfbdClient::class, On3PhotoEnricher::class])
            ->needs(ClientInterface::class)
            ->give(fn () => $client);
    }

    /** A queue that records pushed jobs instead of running them. */
    private function holdQueue(): \ArrayObject
    {
        $pushed = new \ArrayObject();
        $this->app()->getContainer()->instance(Queue::class, new class($pushed) extends SyncQueue {
            public function __construct(private \ArrayObject $pushed)
            {
            }

            public function push($job, $data = '', $queue = null)
            {
                $this->pushed->append($job);
            }
        });

        return $pushed;
    }

    private function runJob(object $job): void
    {
        $this->app()->getContainer()->call([$job, 'handle']);
    }

    private function recruits(?int $actor = 2): array
    {
        $this->boot();

        $response = $this->send($this->request('GET', '/api/recruiting-boards/1', $actor ? ['authenticatedAs' => $actor] : []));
        $json = json_decode((string) $response->getBody(), true);
        $board = $json['data']['attributes'] ?? null;

        if ($board === null) {
            return [$response->getStatusCode(), $json];
        }

        return [$response->getStatusCode(), ['data' => $board['recruits'], 'year' => $board['year']] + ($board['error'] !== null ? ['error' => $board['error']] : [])];
    }

    private function cfbd(array $players, int $status = 200): void
    {
        $this->responses->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($players)));
    }

    private function cacheKey(bool $photos = false): string
    {
        return RecruitingBoards::cacheKey('2027', '', 25, $photos);
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
    public function guests_can_read_the_public_board()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');
        $this->cfbd([$this->player(1, 1)]);

        [$status, $body] = $this->recruits(null);

        $this->assertSame(200, $status, 'The page and its nav link are shown to guests, so the API must answer them');
        $this->assertSame([1], array_column($body['data'], 'id'));
    }

    #[Test]
    public function a_forum_closed_to_guests_does_not_serve_them_recruits()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');
        $this->cfbd([$this->player(1, 1)]);
        $this->database()->table('group_permission')->where('group_id', Group::GUEST_ID)->where('permission', 'viewForum')->delete();

        [$status] = $this->recruits(null);

        $this->assertSame(403, $status);
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
            'id' => 1, 'name' => 'Player 1', 'position' => 'QB', 'height' => "6'3\"",
            'weight' => '210 lbs', 'hometown' => 'Austin, TX', 'country' => 'Canada',
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
        $this->assertSame('Bearer KEY', $this->sent[0]['request']->getHeaderLine('Authorization'));
        [, $body] = $this->recruits();
        $this->assertSame('New Name', $body['data'][0]['name']);
    }

    #[Test]
    public function a_queued_refresh_never_stores_the_key_and_uses_the_current_one()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'OLDKEY');
        $this->cfbd([$this->player(9, 1)]);
        $this->boot();

        $payload = serialize(new RefreshRecruitsJob($this->cacheKey(), '2027', '', 25));
        $this->assertStringNotContainsString('OLDKEY', $payload, 'Queue payloads (jobs table, Redis) must not hold the API key');

        // The admin changes the key while the job waits in the queue.
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->set('ernestdefoe-recruiting.api_key', 'NEWKEY');
        $this->runJob(unserialize($payload));

        $this->assertSame('Bearer NEWKEY', $this->sent[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame(9, $this->app()->getContainer()->make(Repository::class)->get($this->cacheKey())['data'][0]['id']);
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
    public function the_http_client_is_not_bound_for_anyone_else()
    {
        $this->app();

        $this->assertFalse(
            $this->app()->getContainer()->bound(ClientInterface::class),
            'Core and other extensions resolving ClientInterface must not get ours'
        );
    }

    #[Test]
    public function a_cold_request_makes_no_outbound_call_and_photos_are_a_separate_job()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');
        $this->setting('ernestdefoe-recruiting.photos_enabled', '1');
        $this->cfbd([$this->player(1, 1, ['name' => 'Jared Curtis'])]);
        $this->responses->append(new Response(200, [], '<a href="/rivals/jared-curtis-123456/"><img src="https://on3static.com/uploads/assets/1/2/3.jpg"></a>'));
        $this->boot();
        $pushed = $this->holdQueue();

        [$status, $body] = $this->recruits();

        $this->assertSame(200, $status);
        $this->assertSame('warming_up', $body['error']);
        $this->assertCount(0, $this->sent, 'The request itself calls neither CFBD nor On3');
        $this->assertCount(1, $pushed);
        $this->assertInstanceOf(RefreshRecruitsJob::class, $pushed[0]);

        [, $body] = $this->recruits();
        $this->assertSame('warming_up', $body['error']);
        $this->assertCount(1, $pushed, 'A refresh already in flight is not queued twice');

        $this->runJob($pushed[0]);
        $this->assertCount(1, $this->sent, 'The recruits job fetches CFBD only');
        $this->assertSame('api.collegefootballdata.com', $this->sent[0]['request']->getUri()->getHost());
        $this->assertInstanceOf(RefreshPhotosJob::class, $pushed[1]);

        [$status, $body] = $this->recruits();
        $this->assertSame(200, $status);
        $this->assertNull($body['data'][0]['photoUrl'], 'Served without photos until the photo job runs');
        $fetchedAt = $this->app()->getContainer()->make(Repository::class)->get($this->cacheKey(true))['fetched_at'];

        $this->runJob($pushed[1]);
        $this->assertSame('www.on3.com', $this->sent[1]['request']->getUri()->getHost());

        [, $body] = $this->recruits();
        $this->assertSame('https://on3static.com/uploads/assets/1/2/3.jpg', $body['data'][0]['photoUrl']);
        $this->assertSame($fetchedAt, $this->app()->getContainer()->make(Repository::class)->get($this->cacheKey(true))['fetched_at']);
    }

    #[Test]
    public function a_cached_photo_map_is_used_without_another_job()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');
        $this->setting('ernestdefoe-recruiting.photos_enabled', '1');
        $this->cfbd([$this->player(1, 1, ['name' => 'Jared Curtis'])]);
        $this->boot();
        $pushed = $this->holdQueue();
        $this->app()->getContainer()->make(Repository::class)->put('ernestdefoe-recruiting.on3map.football.2027', ['jared-curtis' => 'https://on3static.com/x.jpg'], 3600);

        $this->runJob(new RefreshRecruitsJob($this->cacheKey(true), '2027', '', 25));

        $this->assertCount(1, $this->sent);
        $this->assertCount(0, $pushed);
        $this->assertSame('https://on3static.com/x.jpg', $this->app()->getContainer()->make(Repository::class)->get($this->cacheKey(true))['data'][0]['photoUrl']);
    }

    #[Test]
    public function a_failed_on3_scrape_is_not_retried_on_every_refresh()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');
        $this->setting('ernestdefoe-recruiting.photos_enabled', '1');
        $this->cfbd([$this->player(1, 1)]);
        $this->cfbd([$this->player(1, 1)]);
        $this->responses->append(new Response(503, [], 'down'));
        $this->boot();
        $pushed = $this->holdQueue();

        $this->runJob(new RefreshRecruitsJob($this->cacheKey(), '2027', '', 25));
        $this->runJob($pushed[0]);
        $this->runJob(new RefreshRecruitsJob($this->cacheKey(), '2027', '', 25));

        $this->assertCount(3, $this->sent, 'CFBD, On3 (failed), CFBD');
        $this->assertCount(1, $pushed, 'The failed scrape is remembered, so no second photo job');
    }

    #[Test]
    public function switching_photos_off_stops_serving_cached_headshots_at_once()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');
        $this->cfbd([$this->player(1, 1)]);
        $this->app()->getContainer()->make(Repository::class)->put($this->cacheKey(true), [
            'data' => [['id' => 1, 'name' => 'Player 1', 'photoUrl' => 'https://on3static.com/x.jpg']],
            'fetched_at' => time(),
        ], 3600);

        [, $body] = $this->recruits();

        $this->assertNull($body['data'][0]['photoUrl'], 'photos_enabled is 0, so the photo envelope is not used');
    }

    #[Test]
    public function there_is_one_board()
    {
        $response = $this->send($this->request('GET', '/api/recruiting-boards/2'));

        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function an_unreachable_cfbd_is_reported_as_such()
    {
        $this->setting('ernestdefoe-recruiting.api_key', 'KEY');
        $this->responses->append(new ConnectException('timed out', new Request('GET', 'https://api.collegefootballdata.com')));

        [$status, $body] = $this->recruits();

        $this->assertSame(200, $status);
        $this->assertSame('cfbd_unreachable', $body['error'], 'A connection timeout is not an unexpected_error');
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
