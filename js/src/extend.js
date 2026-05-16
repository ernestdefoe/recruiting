import { Admin } from 'flarum/common/extenders';

/**
 * Admin extender — registers settings fields on the extension's settings
 * page in the Flarum admin panel.  Each .setting() call takes a function
 * that returns the field config object (Flarum 2 canonical pattern).
 */
export default [
  new Admin()

    // ── Required ────────────────────────────────────────────────────────────
    .setting(() => ({
      setting:     'ernestdefoe-recruiting.api_key',
      label:       'College Football Data API Key',
      help:        'Free API key from collegefootballdata.com — required to fetch recruiting data.',
      type:        'text',
      placeholder: 'Paste your CFBD bearer token here',
    }))

    // ── Recruiting class year ────────────────────────────────────────────────
    .setting(() => ({
      setting:     'ernestdefoe-recruiting.year',
      label:       'Recruiting Year',
      help:        'Which recruiting class to display. Leave blank to always show the current calendar year.',
      type:        'text',
      placeholder: new Date().getFullYear().toString(),
    }))

    // ── Team filter ──────────────────────────────────────────────────────────
    .setting(() => ({
      setting:     'ernestdefoe-recruiting.team',
      label:       'Team Filter',
      help:        'Show recruits committed to (or being recruited by) a specific team. Leave blank to display national top recruits.',
      type:        'text',
      placeholder: 'e.g. Alabama',
    }))

    // ── Widget display ───────────────────────────────────────────────────────
    .setting(() => ({
      setting:     'ernestdefoe-recruiting.widget_title',
      label:       'Widget Title',
      help:        'Heading shown at the top of the recruiting widget.',
      type:        'text',
      placeholder: 'Top Recruits',
    }))

    .setting(() => ({
      setting:     'ernestdefoe-recruiting.max_recruits',
      label:       'Max Recruits to Display',
      help:        'Maximum number of recruits shown in the widget (1–100).',
      type:        'number',
      placeholder: '25',
      min:         1,
      max:         100,
    }))

    // ── Caching ──────────────────────────────────────────────────────────────
    .setting(() => ({
      setting:     'ernestdefoe-recruiting.cache_minutes',
      label:       'Cache Duration (minutes)',
      help:        'How long to cache CFBD API responses. Recruiting data changes infrequently — 360 (6 hours) is a sensible default.',
      type:        'number',
      placeholder: '360',
      min:         1,
    })),
];
