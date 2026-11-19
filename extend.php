<?php

use Ernestdefoe\Recruiting\Api\Resource\RecruitingBoardResource;
use Ernestdefoe\Recruiting\RecruitingServiceProvider;
use Flarum\Extend;

return [
    // ── Container bindings ─────────────────────────────────────────────────────
    // Gives CfbdClient and On3PhotoEnricher (and nothing else) their HTTP
    // client, through a contextual binding.
    (new Extend\ServiceProvider())
        ->register(RecruitingServiceProvider::class),

    // ── Frontend ──────────────────────────────────────────────────────────────
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The code-split /recruiting page; without this it is never published.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        // Register the /recruiting path as a server-side frontend route so that
        // hard refreshes and direct URL visits serve the Flarum app shell instead
        // of returning a 404.  Mithril's client-side router then takes over and
        // renders RecruitingPage.
        ->route('/recruiting', 'ernestdefoe-recruiting'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    // ── API ──────────────────────────────────────────────────────────────────
    new Extend\ApiResource(RecruitingBoardResource::class),

    // ── Settings ─────────────────────────────────────────────────────────────
    // Settings UI is registered with Extend.Admin in js/src/admin/extend.ts.
    // The widget_title setting is the only one exposed to the
    // forum frontend — RecruitingPage reads it as
    // app.forum.attribute('ernestdefoe-recruiting.widget_title') and falls
    // back to 'Top Recruits' when unset.
    (new Extend\Settings())
        ->serializeToForum(
            'ernestdefoe-recruiting.widget_title',
            'ernestdefoe-recruiting.widget_title',
        )
        ->default('ernestdefoe-recruiting.api_key', '')
        ->default('ernestdefoe-recruiting.year', '')
        ->default('ernestdefoe-recruiting.team', '')
        ->default('ernestdefoe-recruiting.widget_title', '')
        ->default('ernestdefoe-recruiting.max_recruits', '25')
        ->default('ernestdefoe-recruiting.cache_minutes', '360')
        // On3 headshots are on by default; operators can switch them off
        // to stop all outbound On3 traffic.
        ->default('ernestdefoe-recruiting.photos_enabled', '1'),
];
