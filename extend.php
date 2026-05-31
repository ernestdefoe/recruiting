<?php

use Ernestdefoe\Recruiting\Api\Controller\RecruitingController;
use Ernestdefoe\Recruiting\RecruitingServiceProvider;
use Flarum\Extend;

return [
    // ── Container bindings ─────────────────────────────────────────────────────
    // Binds the shared GuzzleHttp\ClientInterface injected into CfbdClient
    // and On3PhotoEnricher.
    (new Extend\ServiceProvider())
        ->register(RecruitingServiceProvider::class),

    // ── Frontend ──────────────────────────────────────────────────────────────
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less')
        // Register the /recruiting path as a server-side frontend route so that
        // hard refreshes and direct URL visits serve the Flarum app shell instead
        // of returning a 404.  Mithril's client-side router then takes over and
        // renders RecruitingPage.
        ->route('/recruiting', 'ernestdefoe-recruiting'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    // ── API routes ───────────────────────────────────────────────────────────
    (new Extend\Routes('api'))
        ->get('/cfbd-recruits', 'cfbd.recruits', RecruitingController::class),

    // ── Settings ─────────────────────────────────────────────────────────────
    // Settings UI is registered via the JS Admin extender in admin.js /
    // extend.js. The widget_title setting is the only one exposed to the
    // forum frontend — RecruitingPage reads it as
    // app.forum.attribute('ernestdefoe-recruiting.widget_title') and falls
    // back to 'Top Recruits' when unset.
    (new Extend\Settings())
        ->serializeToForum(
            'ernestdefoe-recruiting.widget_title',
            'ernestdefoe-recruiting.widget_title',
            null,
            null,
        )
        ->default('ernestdefoe-recruiting.api_key',       '')
        ->default('ernestdefoe-recruiting.year',          '')
        ->default('ernestdefoe-recruiting.team',          '')
        ->default('ernestdefoe-recruiting.widget_title',  '')
        ->default('ernestdefoe-recruiting.max_recruits',  '25')
        ->default('ernestdefoe-recruiting.cache_minutes', '360')
        // On3 headshot scraping is on by default (preserves existing
        // behaviour) but operators can disable all outbound On3 traffic.
        ->default('ernestdefoe-recruiting.photos_enabled', '1'),
];
