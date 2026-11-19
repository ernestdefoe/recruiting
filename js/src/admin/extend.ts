import app from 'flarum/admin/app';
import Admin from 'flarum/common/extenders/Admin';
import extractText from 'flarum/common/utils/extractText';

const KEY = 'ernestdefoe-recruiting.admin.settings.';
const t = (k: string, params: Record<string, unknown> = {}): string => extractText(app.translator.trans(KEY + k, params));

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
    return t('on3_never');
  }

  const when = new Date(Number(ts) * 1000).toLocaleString();
  return count ? t('on3_last_with_count', { when, count }) : t('on3_last', { when });
}

/**
 * Admin extender — registers settings fields on the extension's settings
 * page in the Flarum admin panel. Each .setting() call takes a function
 * that returns the field config object (Flarum 2 canonical pattern). All
 * user-facing text is resolved through the translator (locale/en.yml).
 */
export default [
  new Admin()
    .setting(() => ({
      setting: 'ernestdefoe-recruiting.api_key',
      label: t('api_key_label'),
      help: t('api_key_help'),
      type: 'text',
      placeholder: t('api_key_placeholder'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-recruiting.year',
      label: t('year_label'),
      help: t('year_help'),
      type: 'text',
      placeholder: new Date().getFullYear().toString(),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-recruiting.team',
      label: t('team_label'),
      help: t('team_help'),
      type: 'text',
      placeholder: t('team_placeholder'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-recruiting.widget_title',
      label: t('widget_title_label'),
      help: t('widget_title_help'),
      type: 'text',
      placeholder: t('widget_title_placeholder'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-recruiting.max_recruits',
      label: t('max_recruits_label'),
      help: t('max_recruits_help'),
      type: 'number',
      placeholder: '25',
      min: 1,
      max: 100,
    }))
    .setting(() => ({
      setting: 'ernestdefoe-recruiting.cache_minutes',
      label: t('cache_minutes_label'),
      help: t('cache_minutes_help'),
      type: 'number',
      placeholder: '360',
      min: 1,
    }))
    .setting(() => ({
      setting: 'ernestdefoe-recruiting.photos_enabled',
      label: t('photos_enabled_label'),
      help: t('photos_enabled_help') + ' ' + lastScrapeNote(),
      type: 'boolean',
    })),
];
