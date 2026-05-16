# GN Recruiting

A Flarum 2 extension that pulls live college football recruiting rankings from the [College Football Data API](https://collegefootballdata.com/) and displays them on a dedicated `/recruiting` page inside your Flarum forum.

![Desktop view](docs/screenshot-desktop.png)

---

## Features

- **Live data** — recruiting rankings pulled directly from the CFBD API and cached server-side
- **Player headshots** — automatically fetched from ESPN's CDN via the CFBD `athleteId` field; falls back to a styled initials avatar when no photo exists
- **Full player details** — national rank, star rating, numerical rating, position, height, weight, high school, hometown, and commitment status
- **Filters** — search by name / school / city, filter by position, filter by committed vs. undecided
- **Stats bar** — total recruits displayed, average rating, committed count
- **Responsive grid** — adapts from 4–5 columns on desktop down to 2 on mobile

| Mobile view |
|---|
| ![Mobile view](docs/screenshot-mobile.png) |

---

## Requirements

- Flarum 2.x
- PHP 8.3+
- A free CFBD API key from [collegefootballdata.com](https://collegefootballdata.com/)
- `guzzlehttp/guzzle` ^7.0 (pulled in automatically via Composer)

---

## Installation

```bash
composer require ernestdefoe/recruiting
php flarum migrate
php flarum cache:clear
```

Then enable the extension in **Admin → Extensions**.

---

## Configuration

All settings are found in **Admin → Extensions → GN Recruiting**.

| Setting | Description | Default |
|---|---|---|
| **API Key** | Your CFBD bearer token | *(required)* |
| **Recruiting Year** | Class year to display (e.g. `2025`) | Current calendar year |
| **Team Filter** | Show only recruits committed to a specific team (e.g. `Alabama`). Leave blank for national rankings. | *(blank — national)* |
| **Page Title** | Heading shown at the top of the `/recruiting` page | `Top Recruits` |
| **Max Recruits** | How many recruits to display (1–100) | `25` |
| **Cache Duration** | How long to cache CFBD responses in minutes | `360` (6 hours) |

---

## How it works

1. A forum member navigates to `/recruiting` (or clicks the **Recruiting** link in the sidebar nav).
2. The JS frontend calls the internal API route `GET /api/cfbd-recruits`.
3. The PHP controller reads your admin settings, checks Flarum's cache, and if needed proxies a request to `https://api.collegefootballdata.com/recruiting/players?year=…&team=…`.
4. Results are cached for the configured duration, transformed into a clean JSON shape, and returned to the client.
5. Player cards are rendered with national rank, stars, ESPN headshot (via `athleteId`), physical measurements, high school, hometown, and commitment pill.
6. Client-side filters let users narrow by position, commitment status, or keyword search instantly without a second API call.

---

## Player card data

Each card displays:

- **National ranking** (`#1`, `#2`, …)
- **Star rating** (★★★★★) and **numerical rating** (e.g. `0.9991`)
- **Headshot** — ESPN CDN URL `a.espncdn.com/i/headshots/college-football/players/full/{athleteId}.png`; coloured initials avatar as fallback
- **Name** and **position** (QB, WR, CB, OT, DE, …)
- **Height · Weight** (e.g. `6'3" · 215 lbs`)
- **High school** name
- **Hometown** (City, State)
- **Commitment pill** — `✔ Georgia` (green) or `○ Undecided` (grey)

---

## Data source

Recruiting data is provided by the [College Football Data API](https://collegefootballdata.com/). A free account gives you a bearer token with generous rate limits. The extension caches responses to minimise API calls.

---

## License

MIT
