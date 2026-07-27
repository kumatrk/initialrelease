# Simple KUMA

Self-hosted affiliate & media-buyer tracking for people who want full control of their click, cost, and conversion data — without renting a SaaS tracker forever.

Built with **PHP + MySQL**, designed to run on shared hosting or a VPS, with a straightforward admin UI (including dark mode).

| | |
|--|--|
| **Website** | [https://simplekuma.com](https://simplekuma.com) |
| **YouTube** | [https://www.youtube.com/@simplekumtracking](https://www.youtube.com/@simplekumtracking) — setup guides, updates, walkthroughs |
| **Releases** | [GitHub Releases](https://github.com/kumatrk/initialrelease/releases) (install from the production zip) |
| **License** | [AGPL-3.0](LICENSE) |
| **Current version** | See [version.php](version.php) · changelog in [release-notes.md](release-notes.md) |

> **Install from the Release zip**, not by cloning alone. Point your web server document root at `public/`. GeoIP databases ship in the zip so location works out of the box.

---

## Why Simple Kuma

- **Own your stack** — clicks, costs, and conversions stay on your server
- **Built for buyers** — campaigns, landers, offers, traffic sources, postbacks, and drill-down stats in one place
- **Modern integrations** — Meta / Facebook cost sync, Google Ads cost + conversion delivery, REST API for automation
- **Fast reporting** — pre-aggregated daily and token-level summaries so dashboards stay usable as volume grows
- **No mystery SaaS lock-in** — AGPL open source you can audit, host, and extend

---

## Features

### Campaigns & traffic

- Multi-step **campaign create wizard** (basics → traffic → flow → advanced → review)
- **Traffic sources** with token mapping (Facebook, Google/YouTube, TikTok, native, and custom)
- **Landing pages** and **offers** with weighted / rotation-friendly flows
- Tracking endpoints: `/km/...`, `go.php`, `track.php`, postbacks, pixels
- **Redirectless** and cloaking / referrer-mode options where configured
- **Fallback offers**, campaign status filters, min postback payout rules
- **Click lookup** to inspect a single click / conversion path

### Stats & reporting

- Dashboard KPIs and campaign performance (lazy-loaded tables, optional chart)
- **Campaign Stats** with drill-downs by date, country, device, ISP, lander, offer, and traffic-source tokens (zone, subid, ad name, etc.)
- **`lp_clicks`** alongside visits for landing-page engagement
- Saved stats views and layout preferences (sidebar collapse, chart visibility)
- Pre-aggregated tables (`clicks_daily_summary`, token daily aggregates) for speed at scale
- Optional **hidden IP** exclusions for cleaner reporting views

### Costs & ad platforms

- **Facebook / Meta** marketing integrations — ad account cost sync and API call tracking
- **Google Ads API cost sync** (Settings → API Cost Updates) — hourly cron, encrypted credentials at rest
- Google conversion delivery: **CSV / Data Manager** URL and optional **API upload**
- Supports **gclid**, **wbraid**, and **gbraid** for YouTube / Privacy Sandbox traffic
- Unified spend in dashboard and campaign stats next to revenue / ROI-style metrics

### Kuma API (REST v1)

Automate tracker ops without clicking through the UI:

- Bearer **API keys** (create/manage under Settings → Kuma API)
- CRUD for networks, offers, landing pages, campaigns
- Campaign summary stats and **grouped** breakdowns (`group_by=date|country|browser|…|zoneid|…`)
- Click / conversion reporting endpoints
- In-app API reference + OpenAPI-oriented docs for agents and scripts

Example shape:

```http
GET /api/v1/stats/campaigns/{id}?group_by=country
Authorization: Bearer YOUR_API_KEY
```

### Postbacks & conversions

- Network postback URLs with logging and replay helpers
- Conversion tracking tied back to the original click / tokens
- Fire / inspect postbacks from the admin tools when debugging

### Geo & device

- Bundled **GeoIP** databases (public data with attribution) for country / city / ISP-style enrichment
- Device detection (browser, OS, device type) via DeviceDetector
- Installer can refresh GeoIP downloads if you ever need to re-fetch

### Admin UX & security

- Clean campaign / network / offer / lander management UI
- **Light / dark theme** (per user, persisted)
- Collapsible desktop sidebar; mobile-friendly layouts
- CSRF protection, login rate limiting, secure remember-me tokens
- Web installer + one-click tagged GitHub updates from **Settings → Updates**
- CLI upgrade fallback (`UPGRADE.md`, `apply-release-upgrade.php`)
- Data retention / data-management tools for cleaning old click logs

---

## Quick start (production zip)

1. Download the latest **`simplekuma-v*.zip`** from [Releases](https://github.com/kumatrk/initialrelease/releases).
2. Extract on your server and set the **document root to `public/`**.
3. Create an empty MySQL / MariaDB database.
4. Open `https://your-domain/install.php` and complete the wizard (requirements → DB → admin → migrations).
5. On production (non-localhost), the installer locks and removes `install.php` automatically.

### Apache

Enable `mod_rewrite`, then point the vhost / folder at `public/` (or use the included `.htaccess` routing).

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Permissions (Linux)

```bash
chmod 755 config storage storage/logs storage/cache
```

### Typical production crons

```cron
# Facebook / Meta cost updater (adjust path & schedule)
0 * * * * php /path/to/scripts/fb_cost_updater.php >> /path/to/storage/logs/fb_cost_updater.log 2>&1

# Google Ads cost sync
0 * * * * php /path/to/scripts/google_ads_cost_updater.php >> /path/to/storage/logs/google_ads_cost_updater.log 2>&1

# Optional: Google conversion API retry
*/15 * * * * php /path/to/scripts/google_ads_conversion_uploader.php >> /path/to/storage/logs/google_ads_conversion_uploader.log 2>&1
```

### Upgrading

Preferred: open **Kuma admin → Settings → Updates**, click **Check for updates**, then
**Update Now**. Kuma downloads the repository tree for the newest `v{version}` tag, preserves
configuration and stored data, overlays application files, and applies pending migrations.

Do **not** overwrite `config/config.php` or re-run `install.php` on a live install. If the host
blocks the web updater, use the [UPGRADE.md](UPGRADE.md) CLI fallback:

```bash
php scripts/apply-release-upgrade.php --source=/path/to/simplekuma-vX.Y.Z.zip
php scripts/apply-release-upgrade.php --source=/path/to/simplekuma-vX.Y.Z.zip --apply
php scripts/apply-release-upgrade.php --migrations
```

---

## System requirements

- PHP **8.2+**
- MySQL **8.0+** or MariaDB **10.3+** (JSON generated columns)
- Apache or Nginx
- Extensions: `mysqli`, `pdo_mysql`, `json`, `mbstring`, `curl`, `openssl`, `fileinfo`, `filter`

---

## Repository layout

```
simplekuma/
├── public/           # Web root (install.php, tracking endpoints, assets, API)
├── src/              # Application code (campaigns, stats, Auth, API, GeoIP, …)
├── views/            # Admin UI templates
├── database/migrations/
├── scripts/          # Production crons & upgrade helpers
├── config/           # config.php created by installer (not in git)
├── geoip/            # GeoIP DBs in the Release zip (omitted from git — size limits)
├── storage/          # logs, cache, GeoLite DB in zip
├── vendor/           # Composer dependencies (bundled in zip)
├── version.php
├── release-notes.md
└── LICENSE
```

This GitHub tree is **zip-parity** with the production package (same allowlists). Large GeoIP binaries ship in the Release zip but are not stored in git (GitHub’s 100MB file limit); the installer can download them if missing.

---

## Learn more

- Product site: [simplekuma.com](https://simplekuma.com)
- Video guides: [YouTube @simplekumtracking](https://www.youtube.com/@simplekumtracking)
- Version history: [release-notes.md](release-notes.md)
- Issues: use GitHub Issues on this repo

---

## License

[GNU Affero General Public License v3.0](LICENSE) — free to use, modify, and self-host; network use requires sharing corresponding source under AGPL.
