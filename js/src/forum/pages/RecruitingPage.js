import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';

/**
 * RecruitingPage — /recruiting
 *
 * Full page showing college football recruiting rankings pulled from
 * the CFBD API via /api/cfbd-recruits.
 *
 * Features:
 *  - Responsive card grid (auto-fill, 220 px min per card)
 *  - On3 headshot; falls back to star-tier coloured initials avatar
 *  - Client-side filter: keyword search · position · commitment status
 *  - Auto-refresh every 30 minutes
 */
export default class RecruitingPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);

    // Force the two-panel (no sidebar) layout used by full-width pages.
    this.bodyClass = 'App--recruiting';

    this.recruits  = [];
    this.year      = null;
    this.loading   = true;
    this.error     = null;
    this._timer    = null;

    // Tracks photo IDs that failed to load so we render initials instead,
    // without mutating the DOM outside of Mithril's vdom.
    this._brokenPhotos = new Set();

    // Client-side filter state
    this.filterSearch   = '';
    this.filterPosition = '';
    this.filterStatus   = 'all'; // 'all' | 'committed' | 'undecided'
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    // Fetch here (not oninit) so the component is guaranteed to be mounted
    // before the first redraw triggered by a resolved fetch.
    this.fetch();
    // Recruiting data changes rarely — refresh every 30 minutes.
    this._timer = setInterval(() => this.fetch(), 30 * 60_000);
  }

  onremove(vnode) {
    super.onremove(vnode);
    clearInterval(this._timer);
  }

  // ── Data ────────────────────────────────────────────────────────────────────

  fetch() {
    this.loading = true;
    const base   = (app.forum.attribute('apiUrl') || '/api').replace(/\/$/, '');

    fetch(`${base}/cfbd-recruits`, { credentials: 'same-origin' })
      .then((r) => r.json())
      .then((data) => {
        this.recruits      = data.data  || [];
        this.year          = data.year  || null;
        this.error         = data.error || null;
        this.loading       = false;
        this._brokenPhotos = new Set(); // reset on fresh data
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        this.error   = 'fetch_failed';
        m.redraw();
      });
  }

  // ── Helpers ─────────────────────────────────────────────────────────────────

  /** Unique sorted position list derived from loaded data — no side-effects. */
  positions() {
    const seen   = new Set();
    const result = [];
    for (const r of this.recruits) {
      if (r.position && !seen.has(r.position)) {
        seen.add(r.position);
        result.push(r.position);
      }
    }
    return result;
  }

  /** Apply all active client-side filters. */
  filtered() {
    let list = this.recruits;

    if (this.filterPosition) {
      list = list.filter((r) => r.position === this.filterPosition);
    }

    if (this.filterStatus !== 'all') {
      list = list.filter((r) => r.status === this.filterStatus);
    }

    if (this.filterSearch.trim()) {
      const q = this.filterSearch.trim().toLowerCase();
      list = list.filter(
        (r) =>
          (r.name       || '').toLowerCase().includes(q) ||
          (r.highSchool || '').toLowerCase().includes(q) ||
          (r.hometown   || '').toLowerCase().includes(q) ||
          (r.school     || '').toLowerCase().includes(q)
      );
    }

    return list;
  }

  /** "★★★☆☆" string for n stars (0–5). */
  stars(n) {
    if (!n || n < 1) return null;
    const full  = Math.min(5, n);
    const empty = Math.max(0, 5 - full);
    return '★'.repeat(full) + '☆'.repeat(empty);
  }

  /** Two-letter initials from a full name. */
  initials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2)
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    return name.slice(0, 2).toUpperCase();
  }

  // ── View ────────────────────────────────────────────────────────────────────

  view() {
    return m('.GNPage', [
      this.viewHero(),
      m('.GNPage-body',
        this.loading ? this.viewLoading()
        : this.error ? this.viewError()
        : this.viewContent()
      ),
    ]);
  }

  viewHero() {
    const yearLabel = this.year ? ` — ${this.year} Class` : '';
    return m('.GNPage-hero', [
      m('.GNPage-hero-inner', [
        m('h1.GNPage-title', [m('i.fa-solid.fa-star'), ` Top Recruits${yearLabel}`]),
        m('p.GNPage-subtitle', 'FBSFB Recruiting · College Football Rankings · Powered by CFBD'),
      ]),
    ]);
  }

  viewLoading() {
    return m('.GNPage-state', [
      m('i.fa-solid.fa-spinner.fa-spin'),
      m('p', 'Loading recruits…'),
    ]);
  }

  viewError() {
    if (this.error === 'api_key_missing') {
      return m('.GNPage-state.GNPage-state--warn', [
        m('i.fa-solid.fa-key'),
        m('p', 'Set your CFBD API key in Admin → Extensions → FBSFB Recruiting.'),
      ]);
    }
    if (this.error === 'invalid_api_key') {
      return m('.GNPage-state.GNPage-state--warn', [
        m('i.fa-solid.fa-triangle-exclamation'),
        m('p', 'Invalid API key — check Admin → Extensions → FBSFB Recruiting.'),
      ]);
    }
    return m('.GNPage-state.GNPage-state--warn', [
      m('i.fa-solid.fa-circle-exclamation'),
      m('p', 'Recruiting data is temporarily unavailable. Please try again later.'),
    ]);
  }

  viewContent() {
    const recruits  = this.filtered();

    // Stats are derived from the filtered list so every number is consistent.
    const committed = recruits.filter((r) => r.status === 'committed').length;
    const avgRating = recruits.length
      ? (recruits.reduce((s, r) => s + (r.rating || 0), 0) / recruits.length).toFixed(4)
      : null;

    return [
      this.viewFilters(),

      m('.GNPage-stats', [
        m('span', `${recruits.length} Recruit${recruits.length !== 1 ? 's' : ''}`),
        avgRating ? m('span', `Avg Rating: ${avgRating}`) : null,
        m('span', `${committed} Committed`),
      ]),

      recruits.length === 0
        ? m('.GNPage-state', [m('i.fa-solid.fa-magnifying-glass'), m('p', 'No recruits match your filters.')])
        : m('.GNPage-grid', recruits.map((r) => this.viewCard(r))),
    ];
  }

  viewFilters() {
    const positions = this.positions();

    return m('.GNPage-filters', [
      m('div.GNPage-search-wrap', [
        m('i.fa-solid.fa-magnifying-glass.GNPage-search-icon'),
        m('input.GNPage-search', {
          type:        'text',
          placeholder: 'Search name, school, city…',
          value:       this.filterSearch,
          oninput:     (e) => { this.filterSearch = e.target.value; m.redraw(); },
        }),
      ]),

      m('select.GNPage-select', {
        value:    this.filterPosition,
        onchange: (e) => { this.filterPosition = e.target.value; m.redraw(); },
      }, [
        m('option', { value: '' }, 'All Positions'),
        ...positions.map((p) => m('option', { value: p }, p)),
      ]),

      m('select.GNPage-select', {
        value:    this.filterStatus,
        onchange: (e) => { this.filterStatus = e.target.value; m.redraw(); },
      }, [
        m('option', { value: 'all' },       'All Recruits'),
        m('option', { value: 'committed' }, 'Committed'),
        m('option', { value: 'undecided' }, 'Undecided'),
      ]),
    ]);
  }

  // ── Player card ─────────────────────────────────────────────────────────────

  viewCard(r) {
    const starsStr  = this.stars(r.stars);
    const inits     = this.initials(r.name);
    const physicals = [r.height, r.weight].filter(Boolean).join(' · ');
    const photoKey  = r.id || r.name;

    // Show initials if there is no photoUrl, or if the image previously failed.
    const showInitials = !r.photoUrl || this._brokenPhotos.has(photoKey);

    return m('.GNR-card', { key: photoKey }, [

      // Top bar — national rank + stars + numerical rating
      m('.GNR-top', [
        r.ranking
          ? m('.GNR-rank', `#${r.ranking}`)
          : m('.GNR-rank.GNR-rank--none', '—'),
        starsStr
          ? m('.GNR-stars', starsStr)
          : null,
        r.rating
          ? m('.GNR-ratingBadge', r.rating.toFixed(4))
          : null,
      ]),

      // Photo — On3 scrape when available; star-coloured initials as fallback.
      // onerror updates component state and triggers a Mithril redraw so the
      // broken image is replaced via the vdom rather than raw DOM mutation.
      m('.GNR-photoWrap', { 'data-stars': r.stars || 0 }, [
        showInitials
          ? m('.GNR-initials', inits)
          : m('img.GNR-photo', {
              src:     r.photoUrl,
              alt:     r.name,
              loading: 'lazy',
              onerror: () => {
                this._brokenPhotos.add(photoKey);
                m.redraw();
              },
            }),
      ]),

      // Body — name, position, measurements, school info
      m('.GNR-body', [
        m('.GNR-name', r.name),

        r.position
          ? m('span.GNR-pos', r.position)
          : null,

        physicals
          ? m('.GNR-physicals', physicals)
          : null,

        r.highSchool
          ? m('.GNR-detail', [m('i.fa-solid.fa-school'), ' ', r.highSchool])
          : null,

        r.hometown
          ? m('.GNR-detail', [
              m('i.fa-solid.fa-location-dot'),
              ' ',
              r.hometown,
              r.country ? m('span.GNR-country', ` · ${r.country}`) : null,
            ])
          : null,

        r.recruitType && r.recruitType !== 'HighSchool'
          ? m('span.GNR-typeBadge', r.recruitType.replace(/([A-Z])/g, ' $1').trim())
          : null,
      ]),

      // Footer — commitment pill
      m('.GNR-footer', [
        r.status === 'committed'
          ? m('span.GNR-commit.GNR-commit--committed', [
              m('i.fa-solid.fa-circle-check'),
              ` ${r.school || 'Committed'}`,
            ])
          : m('span.GNR-commit.GNR-commit--undecided', [
              m('i.fa-regular.fa-circle'),
              ' Undecided',
            ]),
      ]),
    ]);
  }
}
