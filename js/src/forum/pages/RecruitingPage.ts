import app from 'flarum/forum/app';
import Page, { IPageAttrs } from 'flarum/common/components/Page';
import type Mithril from 'mithril';

/** A single recruit row as returned by GET /api/cfbd-recruits. */
interface Recruit {
  id: number | string | null;
  athleteId: number | null;
  name: string;
  position: string | null;
  height: string | null;
  weight: string | null;
  city: string | null;
  state: string | null;
  hometown: string | null;
  country: string | null;
  stars: number | null;
  rating: number | null;
  ranking: number | null;
  status: 'committed' | 'undecided';
  school: string | null;
  highSchool: string | null;
  recruitType: string | null;
  photoUrl: string | null;
}

/** Envelope shape of the /api/cfbd-recruits response. */
interface RecruitsResponse {
  data?: Recruit[];
  year?: number | null;
  error?: string | null;
}

const PAGE_PREFIX = 'ernestdefoe-recruiting.forum.page.';

/**
 * Translate a forum.page.* key. Thin prefixer over Flarum's translator —
 * all keys live in locale/en.yml, so a miss returns the key (Flarum's
 * default), which is loud enough to catch in review.
 */
function trans(suffix: string): string {
  return app.translator.trans(PAGE_PREFIX + suffix) as string;
}

/** Max consecutive 202 "warming_up" retries before showing an error. */
const MAX_COLD_RETRIES = 8;

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
  recruits: Recruit[] = [];
  year: number | null = null;
  loading: boolean = true;
  error: string | null = null;

  /** Client-side filter state. */
  filterSearch: string = '';
  filterPosition: string = '';
  filterStatus: 'all' | 'committed' | 'undecided' = 'all';

  /** Photo keys that failed to load — render initials instead. */
  private brokenPhotos: Set<string | number> = new Set();

  private timer: ReturnType<typeof setInterval> | null = null;
  private coldRetryTimer: ReturnType<typeof setTimeout> | null = null;
  private coldRetries: number = 0;

  oninit(vnode: Mithril.Vnode<IPageAttrs, this>) {
    super.oninit(vnode);

    // Force the two-panel (no sidebar) layout used by full-width pages.
    this.bodyClass = 'App--recruiting';
  }

  oncreate(vnode: Mithril.VnodeDOM<IPageAttrs, this>) {
    super.oncreate(vnode);
    // Fetch here (not oninit) so the component is guaranteed to be mounted
    // before the first redraw triggered by a resolved fetch.
    this.fetch();
    // Recruiting data changes rarely — refresh every 30 minutes.
    this.timer = setInterval(() => this.fetch(), 30 * 60_000);
  }

  onremove(vnode: Mithril.VnodeDOM<IPageAttrs, this>) {
    super.onremove(vnode);
    if (this.timer !== null) clearInterval(this.timer);
    if (this.coldRetryTimer !== null) clearTimeout(this.coldRetryTimer);
  }

  // ── Data ────────────────────────────────────────────────────────────────────

  fetch() {
    this.loading = true;

    // Build the API URL from the forum base URL so it works regardless of a
    // subdirectory install. (`apiUrl` is not a real forum attribute in
    // Flarum 2 — the old code silently fell back to '/api'.)
    const base = (app.forum.attribute<string>('baseUrl') || '').replace(/\/$/, '');

    app
      .request<RecruitsResponse>({ method: 'GET', url: `${base}/api/cfbd-recruits` })
      .then((data) => {
        // Cold cache is being warmed by another request — retry shortly
        // rather than rendering an error, up to a bounded number of times.
        if (data.error === 'warming_up' && this.coldRetries < MAX_COLD_RETRIES) {
          this.coldRetries += 1;
          this.loading = true;
          this.error = null;
          this.coldRetryTimer = setTimeout(() => this.fetch(), 3000);
          m.redraw();
          return;
        }

        this.coldRetries = 0;
        this.recruits = data.data || [];
        this.year = data.year || null;
        this.error = data.error || null;
        this.loading = false;
        this.brokenPhotos = new Set(); // reset on fresh data
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        this.error = 'fetch_failed';
        m.redraw();
      });
  }

  // ── Helpers ─────────────────────────────────────────────────────────────────

  /** Unique sorted position list derived from loaded data — no side-effects. */
  positions(): string[] {
    const seen = new Set<string>();
    const result: string[] = [];
    for (const r of this.recruits) {
      if (r.position && !seen.has(r.position)) {
        seen.add(r.position);
        result.push(r.position);
      }
    }
    return result;
  }

  /** Apply all active client-side filters. */
  filtered(): Recruit[] {
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
          (r.name || '').toLowerCase().includes(q) ||
          (r.highSchool || '').toLowerCase().includes(q) ||
          (r.hometown || '').toLowerCase().includes(q) ||
          (r.school || '').toLowerCase().includes(q)
      );
    }

    return list;
  }

  /** "★★★☆☆" string for n stars (0–5). */
  stars(n: number | null): string | null {
    if (!n || n < 1) return null;
    const full = Math.min(5, n);
    const empty = Math.max(0, 5 - full);
    return '★'.repeat(full) + '☆'.repeat(empty);
  }

  /** Two-letter initials from a full name. */
  initials(name: string | null): string {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    return name.slice(0, 2).toUpperCase();
  }

  // ── View ────────────────────────────────────────────────────────────────────

  view(): Mithril.Children {
    return m('.GNPage', [
      this.viewHero(),
      m(
        '.GNPage-body',
        this.loading ? this.viewLoading() : this.error ? this.viewError() : this.viewContent()
      ),
    ]);
  }

  viewHero(): Mithril.Children {
    const titleSetting = app.forum.attribute<string | undefined>('ernestdefoe-recruiting.widget_title');
    const baseTitle =
      typeof titleSetting === 'string' && titleSetting.trim() !== '' ? titleSetting.trim() : trans('title');
    const yearLabel = this.year ? ` — ${this.year} ${trans('class_suffix')}` : '';
    return m('.GNPage-hero', [
      m('.GNPage-hero-inner', [
        m('h1.GNPage-title', [m('i.fa-solid.fa-star'), ` ${baseTitle}${yearLabel}`]),
        m('p.GNPage-subtitle', trans('subtitle')),
      ]),
    ]);
  }

  viewLoading(): Mithril.Children {
    return m('.GNPage-state', [m('i.fa-solid.fa-spinner.fa-spin'), m('p', trans('loading'))]);
  }

  viewError(): Mithril.Children {
    if (this.error === 'api_key_missing') {
      return m('.GNPage-state.GNPage-state--warn', [m('i.fa-solid.fa-key'), m('p', trans('configure'))]);
    }
    if (this.error === 'invalid_api_key') {
      return m('.GNPage-state.GNPage-state--warn', [
        m('i.fa-solid.fa-triangle-exclamation'),
        m('p', trans('invalid_key')),
      ]);
    }
    return m('.GNPage-state.GNPage-state--warn', [
      m('i.fa-solid.fa-circle-exclamation'),
      m('p', trans('unavailable')),
    ]);
  }

  viewContent(): Mithril.Children {
    const recruits = this.filtered();

    const committed = recruits.filter((r) => r.status === 'committed').length;
    const avgRating = recruits.length
      ? (recruits.reduce((s, r) => s + (r.rating || 0), 0) / recruits.length).toFixed(4)
      : null;

    return [
      this.viewFilters(),

      m('.GNPage-stats', [
        m('span', `${recruits.length} ${trans(recruits.length === 1 ? 'recruit_singular' : 'recruit_plural')}`),
        avgRating ? m('span', `${trans('avg_rating')}: ${avgRating}`) : null,
        m('span', `${committed} ${trans('committed_count')}`),
      ]),

      recruits.length === 0
        ? m('.GNPage-state', [m('i.fa-solid.fa-magnifying-glass'), m('p', trans('empty'))])
        : m(
            '.GNPage-grid',
            recruits.map((r) => this.viewCard(r))
          ),
    ];
  }

  viewFilters(): Mithril.Children {
    const positions = this.positions();

    return m('.GNPage-filters', [
      m('div.GNPage-search-wrap', [
        m('i.fa-solid.fa-magnifying-glass.GNPage-search-icon'),
        m('input.GNPage-search', {
          type: 'text',
          placeholder: trans('search_placeholder'),
          value: this.filterSearch,
          oninput: (e: InputEvent) => {
            this.filterSearch = (e.target as HTMLInputElement).value;
            m.redraw();
          },
        }),
      ]),

      m(
        'select.GNPage-select',
        {
          value: this.filterPosition,
          onchange: (e: Event) => {
            this.filterPosition = (e.target as HTMLSelectElement).value;
            m.redraw();
          },
        },
        [
          m('option', { value: '' }, trans('all_positions')),
          ...positions.map((p) => m('option', { value: p }, p)),
        ]
      ),

      m(
        'select.GNPage-select',
        {
          value: this.filterStatus,
          onchange: (e: Event) => {
            this.filterStatus = (e.target as HTMLSelectElement).value as 'all' | 'committed' | 'undecided';
            m.redraw();
          },
        },
        [
          m('option', { value: 'all' }, trans('all_recruits')),
          m('option', { value: 'committed' }, trans('committed_only')),
          m('option', { value: 'undecided' }, trans('undecided_only')),
        ]
      ),
    ]);
  }

  // ── Player card ─────────────────────────────────────────────────────────────

  viewCard(r: Recruit): Mithril.Children {
    const starsStr = this.stars(r.stars);
    const inits = this.initials(r.name);
    const physicals = [r.height, r.weight].filter(Boolean).join(' · ');
    const photoKey = r.id || r.name;

    // Show initials if there is no photoUrl, or if the image previously failed.
    const showInitials = !r.photoUrl || this.brokenPhotos.has(photoKey);

    return m('.GNR-card', { key: photoKey }, [
      // Top bar — national rank + stars + numerical rating
      m('.GNR-top', [
        r.ranking ? m('.GNR-rank', `#${r.ranking}`) : m('.GNR-rank.GNR-rank--none', '—'),
        starsStr ? m('.GNR-stars', starsStr) : null,
        r.rating ? m('.GNR-ratingBadge', r.rating.toFixed(4)) : null,
      ]),

      // Photo — On3 scrape when available; star-coloured initials as fallback.
      // onerror updates component state and triggers a Mithril redraw so the
      // broken image is replaced via the vdom rather than raw DOM mutation.
      m('.GNR-photoWrap', { 'data-stars': r.stars || 0 }, [
        showInitials
          ? m('.GNR-initials', inits)
          : m('img.GNR-photo', {
              src: r.photoUrl,
              alt: r.name,
              loading: 'lazy',
              onerror: () => {
                this.brokenPhotos.add(photoKey);
                m.redraw();
              },
            }),
      ]),

      // Body — name, position, measurements, school info
      m('.GNR-body', [
        m('.GNR-name', r.name),

        r.position ? m('span.GNR-pos', r.position) : null,

        physicals ? m('.GNR-physicals', physicals) : null,

        r.highSchool ? m('.GNR-detail', [m('i.fa-solid.fa-school'), ' ', r.highSchool]) : null,

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
          : m('span.GNR-commit.GNR-commit--undecided', [m('i.fa-regular.fa-circle'), ' Undecided']),
      ]),
    ]);
  }
}
