# FBSFB Recruiting

A Flarum 2 extension that pulls live college football recruiting rankings from the [College Football Data API](https://collegefootballdata.com/) and displays them on a dedicated `/recruiting` page inside your Flarum forum.

![Desktop view](docs/screenshot-desktop.png)

---

## Features

- **Live data** — recruiting rankings pulled directly from the CFBD API and cached server-side
- **Player headshots** — automatically sourced from On3's football rankings page; falls back to a star-tier coloured initials avatar when no photo is available
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

All settings are found in **Admin → Extensions → FBSFB Recruiting**.

| Setting | Description | Default |
|---|---|---|
| **API Key** | Your CFBD bearer token | *(required)* |
| **Recruiting Year** | Class year to display (e.g. `2026`) | Current calendar year |
| **Team Filter** | Show only recruits committed to a specific team (e.g. `Alabama`). Leave blank for national rankings. | *(blank — national)* |
| **Max Recruits** | How many recruits to display (1–100) | `25` |
| **Cache Duration** | How long to cache CFBD responses in minutes | `360` (6 hours) |

---

## How it works

1. A forum member navigates to `/recruiting` (or clicks the **Recruiting** link in the sidebar nav).
2. The JS frontend calls the internal API route `GET /api/cfbd-recruits`.
3. The PHP controller reads your admin settings, checks Flarum's cache, and if needed proxies a request to `https://api.collegefootballdata.com/recruiting/players?year=…&team=…`.
4. Results are sorted by national ranking, transformed into a clean JSON shape, and cached for the configured duration.
5. Recruit records are enriched with On3 headshots (see below) and returned to the client.
6. Player cards are rendered with national rank, stars, headshot, physical measurements, high school, hometown, and commitment pill.
7. Client-side filters let users narrow by position, commitment status, or keyword search instantly without a second API call.

---

## Player headshots

Headshots are sourced from **[On3](https://www.on3.com/)**, which maintains photos for thousands of current and historical high-school recruits.

### How the image lookup works

On3's football rankings page (`on3.com/rivals/rankings/player/football/{year}/`) is fully server-rendered HTML containing 150+ ranked recruits, each with a profile link and headshot image URL embedded directly in the markup.

On the first API call after the cache is empty the extension:

1. Fetches the On3 rankings page for the configured class year (one HTTP request).
2. Parses the HTML to build a **name → image URL** map using positional matching between profile hrefs (`/rivals/jared-curtis-159433/`) and `on3static.com` image paths.
3. Caches the map for **24 hours** — subsequent requests read from cache with zero external HTTP calls.
4. Matches each CFBD recruit to the map by normalised name slug (e.g. `"Jared Curtis"` → `"jared-curtis"`).

### Fallback avatars

When no On3 photo is available the card shows a coloured initials avatar whose background is coded by star rating:

| Stars | Colour |
|---|---|
| ★★★★★ | Gold |
| ★★★★ | Blue |
| ★★★ | Green |
| ★★ / unrated | Slate |

---

## Player card data

Each card displays:

- **National ranking** (`#1`, `#2`, …)
- **Star rating** (★★★★★) and **numerical rating** (e.g. `0.9991`)
- **Headshot** — sourced from On3; coloured initials avatar as fallback
- **Name** and **position** (QB, WR, CB, OT, DE, …)
- **Height · Weight** (e.g. `6'3" · 215 lbs`)
- **High school** name
- **Hometown** (City, State)
- **Commitment pill** — `✔ Georgia` (green) or `○ Undecided` (grey)

---

## Data sources

| Data | Source |
|---|---|
| Rankings, ratings, recruit details | [College Football Data API](https://collegefootballdata.com/) |
| Player headshots | [On3](https://www.on3.com/) rankings page |

CFBD provides a free API key with generous rate limits. The extension caches all external responses to minimise outbound requests.

---

## License

MIT
