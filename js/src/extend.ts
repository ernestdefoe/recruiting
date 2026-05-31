import app from 'flarum/admin/app';
import Admin from 'flarum/common/extenders/Admin';

/**
 * Human-readable status line for the last successful On3 scrape, read from
 * the persisted settings the On3PhotoEnricher writes on each refresh. Lets
 * operators see at a glance whether headshot enrichment is still working.
 */
function lastScrapeNote(): string {
  const settings = app.data.settings || {};
  const ts = settings['ernestdefoe-recruiting.on3_last_scrape'];
  const count = settings['ernestdefoe-recruiting.on3_last_count'];

  if (!ts) {
    return 'On3 headshots have not been fetched yet.';
  }

  const when = new Date(Number(ts) * 1000).toLocaleString();
  return `Last successful On3 scrape: ${when}${count ? ` (${count} players)` : ''}.`;
}

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

    // ── Widget title ─────────────────────────────────────────────────────────
    .setting(() => ({
      setting:     'ernestdefoe-recruiting.widget_title',
      label:       'Page / Widget Title',
      help:        'Heading shown above the recruiting widget and on the /recruiting page. Leave blank to use "Top Recruits".',
      type:        'text',
      placeholder: 'Top Recruits',
    }))

    // ── Page display ─────────────────────────────────────────────────────────
    .setting(() => ({
      setting:     'ernestdefoe-recruiting.max_recruits',
      label:       'Max Recruits to Display',
      help:        'Maximum number of recruits shown on the /recruiting page (1–100). Lower values reduce API response size and page load time.',
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
    }))

    // ── On3 headshots ────────────────────────────────────────────────────────
    .setting(() => ({
      setting: 'ernestdefoe-recruiting.photos_enabled',
      label:   'Enable On3 player headshots',
      help:
        "Fetches player photos by scraping On3's public rankings page — one " +
        'outbound request per recruiting class, cached for 24 hours. Disable to ' +
        'stop all outbound On3 traffic; recruits then display star-tier initials ' +
        'avatars instead. ' +
        lastScrapeNote(),
      type:    'boolean',
    })),
];
