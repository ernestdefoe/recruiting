<?php

namespace Ernestdefoe\Recruiting;

use Ernestdefoe\Recruiting\Service\CfbdClient;
use Ernestdefoe\Recruiting\Service\On3PhotoEnricher;
use Flarum\Foundation\AbstractServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Gives this extension's two outbound services their HTTP client.
 *
 * The binding is contextual: only CfbdClient and On3PhotoEnricher receive
 * this client. Nothing else that resolves GuzzleHttp\ClientInterface from
 * the container (core, other extensions) is affected. Timeouts and headers
 * are set on each request at the call site.
 */
class RecruitingServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container
            ->when([CfbdClient::class, On3PhotoEnricher::class])
            ->needs(ClientInterface::class)
            ->give(fn () => new Client(['http_errors' => false]));
    }
}
