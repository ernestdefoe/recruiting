import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';

/**
 * RecruitingWidget
 *
 * Displays top college football recruits pulled from the CFBD API via
 * the /api/cfbd-recruits proxy endpoint.  Refreshes every 30 minutes.
 *
 * Each recruit card shows:
 *   • National ranking badge (#1, #2, …)
 *   • Position badge (QB, WR, …)
 *   • Name + hometown
 *   • Star rating (★★★★★) and numerical rating
 *   • Commitment pill (→ Team  |  Undecided)
 */
export default class RecruitingWidget extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.recruits = [];
    this.title    = app.forum.attribute('ernestdefoe-recruiting.widget_title') || 'Top Recruits';
    this.year     = null;
    this.loading  = true;
    this.error    = null;
    this._timer   = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.fetch();
    // Recruiting data changes infrequently — refresh every 30 minutes.
    this._timer = setInterval(() => this.fetch(), 30 * 60_000);
  }

  onremove(vnode) {
    super.onremove(vnode);
    clearInterval(this._timer);
  }

  fetch() {
    const base = (app.forum.attribute('apiUrl') || '/api').replace(/\/$/, '');

    fetch(`${base}/cfbd-recruits`, { credentials: 'same-origin' })
      .then((r) => r.json())
      .then((data) => {
        this.recruits = data.data  || [];
        this.year     = data.year  || null;
        this.error    = data.error || null;
        // Allow the API to override the title (honours admin's widget_title setting).
        if (data.title) this.title = data.title;
        this.loading  = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        this.error   = 'fetch_failed';
        m.redraw();
      });
  }

  // Render n filled stars + (5-n) empty stars.
  stars(n) {
    if (!n || n < 1) return null;
    return '★'.repeat(Math.min(5, n)) + '☆'.repeat(Math.max(0, 5 - n));
  }

  view() {
    const header = [
      m('i.fas.fa-star'),
      ' ',
      this.title,
      this.year ? m('span.GN-recruitingWidget-year', ` '${String(this.year).slice(-2)}`) : null,
    ];

    return m('.GN-widget.GN-recruitingWidget', [
      m('.GN-widget-header', header),
      m('.GN-widget-body', this.renderBody()),
    ]);
  }

  renderBody() {
    if (this.loading) {
      return m('.GN-widget-loading', m('i.fas.fa-spinner.fa-spin'));
    }

    if (this.error === 'api_key_missing') {
      return m('.GN-widget-empty', [
        m('i.fas.fa-key', { style: 'margin-right:6px' }),
        'Set your CFBD API key in Admin → Extensions → FBSFB Recruiting.',
      ]);
    }

    if (this.error === 'invalid_api_key') {
      return m('.GN-widget-empty', 'Invalid API key — check Admin settings.');
    }

    if (this.error) {
      return m('.GN-widget-empty', 'Recruiting data unavailable.');
    }

    if (!this.recruits.length) {
      return m('.GN-widget-empty', 'No recruits found for this year / team.');
    }

    return this.recruits.map((r) => this.viewRecruit(r));
  }

  viewRecruit(r) {
    const committed   = r.status === 'committed';
    const statusClass = committed
      ? 'GN-recruit-commit--committed'
      : 'GN-recruit-commit--undecided';
    const statusLabel = committed
      ? (r.school ? `→ ${r.school}` : 'Committed')
      : 'Undecided';

    const starsStr = this.stars(r.stars);
    const meta     = [r.height, r.hometown].filter(Boolean).join(' · ');

    return m('.GN-recruit', { key: r.id || r.name }, [

      // Left badges: ranking number + position
      m('.GN-recruit-badges', [
        r.ranking
          ? m('.GN-recruit-rank', `#${r.ranking}`)
          : null,
        r.position
          ? m('.GN-recruit-pos', r.position)
          : null,
      ]),

      // Centre: name, meta line, stars / rating
      m('.GN-recruit-info', [
        m('.GN-recruit-name', r.name),
        meta
          ? m('.GN-recruit-meta', meta)
          : null,
        starsStr
          ? m('.GN-recruit-stars', [
              starsStr,
              r.rating
                ? m('span.GN-recruit-rating', ` ${r.rating.toFixed(4)}`)
                : null,
            ])
          : null,
      ]),

      // Right: commitment pill
      m('span.GN-recruit-commit', { class: statusClass }, statusLabel),
    ]);
  }
}
