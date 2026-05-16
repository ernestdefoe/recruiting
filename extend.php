<?php

use Ernestdefoe\Recruiting\Api\Controller\RecruitingController;
use Flarum\Extend;

return [
    // ── Frontend ──────────────────────────────────────────────────────────────
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    // ── API routes ───────────────────────────────────────────────────────────
    (new Extend\Routes('api'))
        ->get('/cfbd-recruits', 'cfbd.recruits', RecruitingController::class),

    // ── Settings defaults ────────────────────────────────────────────────────
    // Settings UI is registered via the JS Admin extender in admin.js / extend.js.
    (new Extend\Settings())
        ->default('ernestdefoe-recruiting.api_key',       '')
        ->default('ernestdefoe-recruiting.year',          '')
        ->default('ernestdefoe-recruiting.team',          '')
        ->default('ernestdefoe-recruiting.max_recruits',  '25')
        ->default('ernestdefoe-recruiting.cache_minutes', '360')
        ->default('ernestdefoe-recruiting.widget_title',  'Top Recruits')
        // Expose non-sensitive settings to the forum bootstrap payload so the
        // widget can read them without an extra HTTP round-trip.
        ->serializeToForum('ernestdefoe-recruiting.widget_title', 'ernestdefoe-recruiting.widget_title'),
];
