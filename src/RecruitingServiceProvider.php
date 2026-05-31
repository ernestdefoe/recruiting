<?php

namespace Ernestdefoe\Recruiting;

use Flarum\Foundation\AbstractServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Binds the shared outbound HTTP client.
 *
 * Both CfbdClient and On3PhotoEnricher depend on a PSR-18-shaped
 * GuzzleHttp\ClientInterface. Binding it here (rather than letting each
 * service `new Client()` itself) makes the client container-managed:
 * operators can layer middleware (logging, retry, proxy) forum-wide, and
 * tests can swap a mock via the container instead of constructor args.
 *
 * Per-request timeouts and headers are supplied at each call site, so a
 * single shared client serves both the 10 s CFBD API call and the 12 s
 * On3 fetch without conflicting defaults.
 */
class RecruitingServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(ClientInterface::class, fn () => new Client([
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]));
    }
}
