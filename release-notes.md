# Simple Kuma Tracker Version 1.1.5.22

## Changes in 1.1.5.22

### Honeycomb: installable traffic & conversion addons
- New **System → Honeycomb** page: browse the fixed catalog (`kumatrk/honeycomb-addons`), search/filter by type, import verified zips, enable/disable without uninstalling, and remove addons
- Addons live outside the Kuma zip under `honeycomb/addons/` on each server — core updates and addon updates stay independent
- Safe install path: HTTPS GitHub host allowlist, required SHA-256, zip path/symlink/size limits, extension allowlist, blocked server config files
- Campaign edit / create wizard: Honeycomb bindings driven by addon `provides` — conversion export and remote cost IDs can appear together
- Hourly spend for Honeycomb networks lands in `honeycomb_campaign_hourly_costs` and overlays campaign KPI / chart totals via `HoneycombCostAggregator` (**summary-first** — no per-click cost joins)
- Recommended one cron for Facebook cost, Google Ads cost, and Honeycomb: `scripts/kuma-traffic-api-cron.php` (Honeycomb-only fallback: `scripts/honeycomb-cron.php`)
- Migrations: `090_honeycomb_kernel`, `092_honeycomb_runtime`, `093_honeycomb_conversion_exports`
- **Taboola Cost API** and **Whop Ads** ship from the Honeycomb catalog (not inside this zip) — import from Honeycomb after upgrade

### Whop Ads (via Honeycomb catalog)
- Template traffic source with Whop click tokens (`wacid` / `wasid` / `waid` / Meta UTMs)
- Single landing page when the campaign uses a Whop traffic source (Whop Pixel + Kuma CTA handoff)
- Conversion export to Whop Events API (default `lead`); click capture of `_wuid` + original landing URL
- Campaign-level spend sync from Whop Ad Reports (`ad_campaign:stats:read`) when a Whop ad campaign ID is bound
- Cost badge: Honeycomb `integrated_api` sources show Live API cost (not “variables only”)

### Taboola Cost API (via Honeycomb catalog)
- Backstage client-credentials spend sync into the same Honeycomb hourly cost table and stats overlay

### Server Status: VPS CPU / RAM / disk monitor
- New **System → Server Status** page: live disk meters, best-effort CPU load pressure + RAM (Linux `/proc`), MySQL click-table sizes, and Run archive & retention now
- In-app warning banner when CPU / RAM / disk cross thresholds (configurable on the page; no email)
- Retention cron records host metrics alongside disk; raw purge still preserves report summaries
- Theme-aware UI (readable in dark mode)

### Tracker: ISP, connection type, and browser language
- New click columns: `isp`, `connection_type`, `language` (migration `091_add_clicks_isp_connection_language.sql`)
- Campaign Stats tracker breakdowns: **ISP**, **Connection Type**, **Browser Language**
- **ISP** from redistributable **DB-IP ASN Lite** (`geoip/DBIP-ASN-Lite.mmdb`, CC BY 4.0) — packaged in the customer zip
- Do **not** ship MaxMind GeoLite2-ASN in the zip (license); private drop-in still works if present
- **Connection type**: traffic-source tokens first, else ASN org heuristic → Cellular / Broadband / Corporate / Unknown
- **Browser language**: primary Accept-Language tag on origin; edge ingest keeps worker `language`
- Refresh ASN DB: `php scripts/download-geoip-databases.php --dbip-asn` (also in `--all`)
- Historical clicks stay N/A until new traffic; Settings → GeoIP shows ASN/ISP status

### Offer / LP rotation weights (edge UX)
- Origin links apply new weights **immediately** after save
- Edge (Cloudflare KV) can take **up to about a minute** after sync to use new weights worldwide
- Campaign UI copy on Edge box + offer/LP rotation explains this

### Packaging
- Customer zip includes Honeycomb kernel + empty secured `honeycomb/` runtime (addons are catalog-installed, not bundled)
- Required migrations include Honeycomb **090 / 092 / 093** and ISP **091**
- Production crons allowlisted: `kuma-traffic-api-cron.php`, `honeycomb-cron.php`
- **Installer:** migration **091** (ISP / connection / language) is idempotent in PHP — re-running after a partial apply no longer fails with `Duplicate column name 'isp'`
- Custom postbacks skip empty network click ids (e.g. PropellerAds `visitor_id`) and do not retry HTTP 4xx

## Changes in 1.1.5.21

### Fix: Token daily revenue no longer stored as an integer
- Token-level daily summaries bind revenue as a decimal so conversion cents are not truncated
- New conversions keep correct revenue going forward
- Do **not** run a full-history token-daily rebuild on existing installs

### Fix: Edge / redirectless cost uses the traffic source cost key
- Clicks honor `cost_param_key` (e.g. TrafficStars `price`) instead of a leftover CPM/`cost` field
- Redirectless tracking uses the same resolver as Edge ingest
- **Redeploy the Cloudflare Worker** after this upgrade if you use Edge Redirect (the updater does not push Workers)

### Fix: Daily summary and billing cost no longer multiplies across conversions
- Cron, backfill, and billing campaign rows count conversions 1:1 so `SUM(clicks.cost)` is not fan-out by multiple events on the same click

### Fix: Token daily rebuild leaves purged history alone
- Rebuilding a UTC day skips DELETE when raw + archive clicks are empty, so pre-aggregated token history survives retention

### Security: Leftover world-writable files healed on login and update
- Live installs normalize tree permissions after auth, in-app update, and apply-release-upgrade
- Skips `config/config.php`, `storage/`, and `.git/`; no-op on Windows

### Token pickers insert `{ts_tokenN}`
- Custom postbacks and offer URL pickers insert `{ts_tokenN}` (saved templates and URLs are unchanged)

### Tracking links: no duplicate cost token
- Campaign tracking links skip appending `cost=` when the traffic source already uses `cost_param_key` (PropellerAds and similar)

### Performance: REST campaign list no longer raw-scans clicks
- `GET /api/v1/stats/campaigns` uses list stats with Meta/Google cost overlay off
- The Campaigns UI still overlays hourly FB/GA costs

### Visitor Log: Last month no longer times out
- Count and page load no longer join every conversion over a large date range
- Uses a lean id-first page + cover indexes; Conversion Log is unchanged

### Email Opt-ins page and Click Lookup card
- New **Email Opt-ins** page (sidebar under Conversion Log): visitors, opt-ins, opt-in %, cost / opt-in, daily chart, campaign table, event list
- Click Lookup shows a dedicated Email opt-in card; purchase conversions stay in their own section
- Email is shown only if it was already on the click or postback — no new email column or lead CRM
- Campaign Stats columns and Hermes queries are unchanged

### Updates tab: release notes readable in dark mode
- What’s New notes use theme tokens instead of a white box with remapped light text

## Changes in 1.1.5.20

### Security: Updater no longer leaves files world-writable
- After each in-app update extract/apply, application files are normalized to `0644` / directories to `0755`
- Prevents PHP-FPM permissive umask from leaving PHP sources as `0666` (local privilege-escalation risk)
- `config/config.php` remains intentionally restricted (`0600`) and is skipped by the normalizer

### GeoIP: Legacy GeoLite2 no longer disables the fallback chain
- A leftover `storage/GeoLite2-City.mmdb` is only offered to providers whose format matches
- IP2Location / IPinfo still discover their own databases under `geoip/`
- Stops per-request init failures and error-log flooding when mixed databases are present

### Edge Redirect: Real ingest health check + nginx guidance
- Health check performs a signed round-trip to origin `/api/edge-click` and requires a 2xx response
- Worker treats non-2xx (including 302 to login) as ingest failure instead of silent success
- Nginx rewrite for `/api/edge-click` documented in `WEB_SERVER_SETUP.md` and settings UI copy

### Hot-path logging: Debug spam gated behind `APP_DEBUG`
- Redirector redirect-rules dumps and other verbose traces no longer flood `error.log` on every click
- Redirectless tracker, Facebook cost aggregator, and GeoIP providers use the same gated logger

### Performance: API `group_by` uses Hermes summary-first path
- `GET /api/v1/stats/campaigns/{id}?group_by=…` now uses the same `CampaignStatsV2Service` breakdown path as Campaign Stats UI
- Unfiltered token/geo/device breakdowns prefer pre-aggregates / lean cover indexes instead of raw `clicks` scans

### Fix: LP offer update fatal (`incrementSummaryClickRow` argument count)
- Restored correct `clickInc` / `lpInc` / `directInc` / `cost` arguments after the bot-summary signature change
- Fixes fatal errors on `lp/click.php` → offer rotation (can break LP / popunder offer attribution)

## Changes in 1.1.5.19

### Bot Traffic Visibility & Fast-Path Reporting
- Added `bot_clicks` pre-aggregation to `clicks_daily_summary` and `clicks_stats_by_token_daily` summary tables (Migration 089).
- Bot traffic counts and bot percentage (`Bot %`) are now available across totals, daily charts, and all multi-dimension breakdowns (e.g. by traffic source token, publisher zone, country, offer).
- Protected Hermes fast-path aggregation: reporting queries remain instant (summary-first) without requiring raw click scans or custom cost joins.
- Added Bot Clicks and Bot % toggles to the customizable column picker in Campaign Stats.

### Campaign, Offer & Landing Page Tagging & Client-Side Search
- Added flexible tagging support for Campaigns, Offers, and Landing Pages (comma-separated tags).
- Supported tags in Campaign Creation Wizard and Campaign Editor with review summary parity.
- Added fast client-side search across Campaigns, Offers, and Landing Pages with real-time filtering by tag, name, `#ID`, traffic source, group, and flow type.
- Displayed numeric Campaign IDs (`#ID`) prominently on campaign lists and mobile cards for quick navigation.

### Safe Generated Columns & Hot-Path Redirect Fail-Safe
- Migration 089 replaces strict integer casts on `clicks.ad_id` and `clicks.adset_id` with safe regex numeric validation, preventing MySQL `Truncated incorrect INTEGER value` errors when non-numeric tracking tokens or unresolved macros (e.g. `{{ad.id}}`) are received.
- Hot-path redirector (`km.php`) fail-safely logs click recording errors without interrupting redirection, ensuring visitors are never dropped and internal SQL errors are not displayed to visitors.

### UI & Dark Mode Readability Improvements
- Improved contrast and dark mode styling for Edge Redirect (Cloudflare Worker) status panels in the Campaign Editor.
- Enhanced contrast, distinct pill styling, and hover/active states for traffic source postback tokens in Settings → Integrations and Offer edit dialogs so tokens are clearly legible and clickable.

## Changes in 1.1.5.18

### Attribution Window: Unlimited Option
- Settings → Privacy now supports **Unlimited** attribution window (`0` days) in addition to presets (7, 14, 30, 60, 90, 180, 365 days)
- Postbacks and pixels will accept conversions indefinitely when set to Unlimited without age cutoff

### Data Lifecycle & Hot Clicks Archiving
- Moves old clicks from the hot `clicks` table into `clicks_archive` (`archive_after_days`, default: 365 days)
- Scheduled via `scripts/run-data-retention-cron.php` or on-demand in Settings → Privacy (“Run archive & retention now”)
- **Summary-first protection**: Historical pre-aggregated reporting tables (`clicks_daily_summary`, `clicks_stats_by_token_daily`) are preserved intact so Hermes fast-path campaign KPI/breakdown queries remain instant and unaffected by raw click archiving or purging

### Affiliate Networks: Clean Postback UI
- Removed legacy S2S postback template input from Add/Edit Network and table views
- Outbound postbacks are configured under **Settings → Integrations** (Custom Postbacks), and inbound postback URLs under **Postback URLs**

## Changes in 1.1.5.17

### Fix: Campaign save blocked by browser “0.01” validation (locale language)
- Default CPC and minimum postback payout use `step="any"` so optional money fields are not blocked by HTML5 step mismatch
- Browser validation bubbles use the browser UI language (e.g. Chinese) — that was not a Kuma translation bug
- Stored values are normalized for display to avoid float junk tripping the input

### Fix: Campaign save 502 when Edge Redirect sync hangs
- Edge KV sync after campaign/slug save is deferred and coalesced (one sync per campaign per request)
- On PHP-FPM, the HTTP response is finished before Cloudflare is contacted (`fastcgi_finish_request`)
- Hook Cloudflare API calls use a short timeout (8s) so nginx is far less likely to return 502 Bad Gateway on Save

## Changes in 1.1.5.16

### Fix: Nginx tracking links no longer open the login page
- Pretty click URLs (`/km/…`, `/go/…`, `/c/…`) that fall through to the admin front controller now hand off to `km.php` before auth (common bare Nginx `try_files` setup)
- Shipped `docker/nginx.conf.example` with the recommended click + API rewrites
- Installer complete screen and docs call out Nginx rewrite requirements (Apache `.htaccess` unchanged)

## Changes in 1.1.5.15

### Fix: Restore tracking domain Bypass Verification
- Settings → Domains again shows **Bypass Verification** when automated DNS/SSL checks fail or stay pending
- Manually approved domains get status **Verified (Manual)** and can be selected on campaigns and postbacks
- Confirms at your own risk before enabling a domain that did not pass automated verification

## Changes in 1.1.5.14

### Fix: Create campaign with custom traffic sources
- User-added sources (e.g. Tacolo) are selectable for campaigns even when live cost API is not integrated yet
- Manual / URL cost still works; Bing remains the only “(Coming soon)” holdout
- Campaign create wizard: avoid silent Create failures (`novalidate`, hidden `save_campaign`, clearer validation feedback); clear Facebook fields when leaving Facebook

### Fix: Facebook Marketing API resync with hidden token
- “Leave blank to keep existing token” now applies to Fetch & Save / resync (JS + AJAX + post-update sync use the stored token)

## Changes in 1.1.5.13

### Email opt-ins (BeMob-style)
- Fire opt-ins with `et=optin` (also `lead`, `email`, `subscribe`, `opt-in`) plus a unique `txid`
- Opt-ins count separately in Campaign Stats (**Opt-ins** KPI/column) and do **not** inflate Conversions, CR, or revenue
- Migration **088** adds `optins` to daily/token summary tables (summary-first / Hermes fast path preserved)
- Conversion Log: teal **Opt-in** badge, row highlight, and Event type filter (All / Opt-ins only / Conversions only); CSV includes Event

### Multiple conversions per click (Propush-style)
- Campaign setting **Allow multiple conversions** for multi-earn postbacks on the same `click_id`
- Migration **087**; still dedupes identical `txid` + event (and `event_id`) so retries stay safe
- Available in campaign edit, create wizard, API, and clone

### Docker (optional)
- `Dockerfile` + `docker-compose.yml` for app + MySQL (zip/Apache install remains primary)
- Compose DB host is `mysql`; use HTTPS for secure session cookies in production

## Changes in 1.1.5.12

### Fix: Campaign Stats / dashboard speed at large click volume
- Restored Hermes-style **summary-first** reporting: daily/token summary tables when eligible, covering-index lean scans as fallback
- Meta spend uses hourly/map overlay instead of per-click cost joins on unfiltered reports (prevents 20s timeouts around ~50k–100k clicks)
- Campaign Stats breakdown (Ad Set → Landing → Region), KPI summary, and charts no longer time out when rapidly changing date ranges
- Abort/stale request handling improved so abandoned date switches do not flash false error banners over good KPIs
- Migration **086**: covering index on `clicks (click_id, exclude_from_stats, ts, campaign_id)` for fast conversion attribution joins

## Changes in 1.1.5.11

### Fix: Actions column showing raw form HTML
- CSRF tokens were accidentally placed inside delete/add form `action` attributes on Traffic Sources, Networks, Offers, and Landing Pages
- That broke the markup so `style` / `onsubmit` attribute text appeared next to the trash icon
- Tokens now sit inside the form body (same pattern as Campaigns); Actions buttons render cleanly again

## Changes in 1.1.5.10

### Security hardening (auth, CSRF, secrets, packaging)
- Password reset: hash-only tokens, generic responses, rate limits, remember-me revoke; sessions invalidate via `auth_epoch` (migrations **084**, **085**)
- Settings mutations require `settings.edit`; entity manage permissions + CSRF on campaigns/offers/networks/LPs/traffic sources
- Dev-tool PHP endpoints gated + Apache deny list expanded; production zip excludes debug/manual fire helpers (`login-preview`, `fire-postback-for-conversion`, diagnose/smoke scripts)
- Meta CAPI / Marketing access tokens no longer echoed in edit forms; blank keeps existing; postback logs/API redact secrets
- Google conversion CSV keys compared with `hash_equals`; update-check and cron-log views require settings permissions
- Signing secrets refuse weak static fallbacks when `APP_KEY` is missing in production

## Changes in 1.1.5.9

### Settings UI overhaul
- Settings navigation redesigned for faster tab discovery and clearer mobile layout
- New settings layout/CSS so related options (tracking, bots, edge, about, updates) are easier to find

### About page — contributors & open source
- Settings → About highlights **The Kuma Club** for major code and idea contributors
- Open-source credits section for libraries we ship (including Crawler-Detect and Matomo DeviceDetector) with links to their repos

### Bot detection
- Click ingest detects known crawlers/bots (Matomo DeviceDetector + JayBizzle Crawler-Detect)
- Settings toggles to enable detection and optionally exclude known/suspected bots from stats
- Known bots are stored for audit but can be omitted from reports without slowing redirects

### Cloudflare Edge Redirect (Phase 1)
- Optional Cloudflare Worker redirects eligible campaigns at the edge with async click ingest back to origin
- Settings → Edge Redirect: deploy Worker, sync campaign KV snapshots, health check, rotate ingest secret, disable (removes route + clears KV)
- Per-campaign **Edge redirect** toggle; migration **083** (`edge_enabled` + sync metadata)
- Phase 1 stays on origin for redirectless, cloaking/referrer modes, offer caps, and advanced URL `{tokens}`
- Worker mirrors origin param allowlists (`pass_to_lp` / `pass_to_offer`), slug attribution, and HMAC-secured ingest

## Changes in 1.1.5.8

### About page + Kuma Club
- Settings → About redesigned with a theme-aware hero banner (light and dark artwork)
- Creator stays on the left; **The Kuma Club** on the right recognizes major code and idea contributors
- First Kuma Club member: **L1Ght** (linked AffLift profile, avatar, Patched In: July 29, 2026)
- Mid section compacted into a single values panel with GIF cards; Steve Jobs inspiration video retained below

## Changes in 1.1.5.7

### Permanent download + one-click update target
- Public download is now a single evergreen GitHub Release: **Simple Kuma Download** (`latest` tag)
- Stable links that never change between versions:
  - Page: `https://github.com/kumatrk/initialrelease/releases/latest`
  - Zip: `https://github.com/kumatrk/initialrelease/releases/latest/download/simplekuma-download.zip`
- One-click updater watches that same `latest` release; "is there a newer build?" is decided by comparing local `version.php` to remote `version.php` on the `latest` tag
- Historical `v{version}` tags are still created for git history; forum/YouTube links should use the permanent URLs above

## Changes in 1.1.5.6

### Google Ads Data Manager CSV URL
- Conversion import URL now uses `/api/google-conversions.csv` so Google Data Manager accepts the HTTPS source (path must end in `.csv`)
- Apache rewrites `.csv` to the existing `google-conversions.php` endpoint; query params (`key`, `camp`, etc.) unchanged
- Settings → Integrations copies the `.csv` URL and documents that Username/Password fields can be any values (auth is the `key` query param)

## Changes in 1.1.5.5

### Meta CAPI custom conversion event mapping
- Inbound postbacks/pixels accept optional funnel keys (`et`, with aliases `event_type` / `event`) stored as `conversions.event_key` (migration **082**)
- Settings → Meta CAPI integrations: map inbound keys (e.g. `register`, `ftd`, `rebill`) to Meta standard or custom event names; default event still used when `et` is missing/unmapped
- Multi-step funnels on one click: distinct Meta `event_id` / `order_id` so FTD + rebill do not collide
- Optional non-blocking **Send PageView on click** for linked CAPI integrations
- Custom postbacks gain `{event_key}` token for traffic-source S2S URLs
- Postback URLs page documents multi-event funnel examples

### Login privacy gate (custom login token)
- Optional login-page gate: require a secret query token (default `?mv=…`, param name configurable) before the login form is shown
- Unauthorized visitors are redirected to a decoy URL (custom if set, otherwise Google)
- Short-lived signed cookie after a successful token visit; token stored hashed in settings (never plaintext at rest)
- Settings UI to enable/disable, set/rotate token, choose param name, and set decoy redirect

### Also in 1.1.5.5
- Dashboard / campaign list: Meta approval and crawler clicks are tagged at ingest (`exclude_from_stats`) and omitted from fast reporting without slowing stats (migration 081)
- Converting clicks that were previously flagged as bunk are automatically promoted to REAL and restored in daily/token summaries
- Visitor Log and Click Lookup honor the persisted flag; converted-but-tokenless clicks show as Included as REAL (converted)
- Crawler detection now includes `meta-externalads/1.1` alongside `facebookexternalhit/1.1`
- Campaign Stats: traffic-source tokens that share names with tracker fields (e.g. RollerAds `device` / `os` / `browser`) now appear as drill-down dimensions without overriding tracker columns
- Campaign Stats: compact dismissible error banner; raw MySQL/`only_full_group_by` messages are no longer shown in the UI
- Campaign Stats: hourly chart `GROUP BY` aligned for MySQL `ONLY_FULL_GROUP_BY`
- Public GitHub source now mirrors the production zip (same allowlists; no debug/dev/PII)
- Packaging includes `LICENSE` (AGPL-3.0) and `release-notes.md` in the shippable tree
- Stats view IP exclusions and related reporting updates (migration 080)
- Version bump for packaging/export
- Production zip includes public-domain GeoIP databases (with attribution) so location works out of the box

## Changes in 1.1.5.2

- UI: desktop sidebar icon-rail collapse + dashboard chart hide preference (persisted per user)
- Campaign list column layout and dark-mode action button styling
- Mobile settings tip: inline `code` tokens no longer force full-width blocks
- Version bump for packaging/export

## Changes in 1.1.5

- **API grouped stats:** `GET /api/v1/stats/campaigns/{id}?group_by=...` returns paginated per-dimension breakdowns (date, country, browser, os, isp, landing, offer, and traffic-source tokens such as zoneid/subid). `meta.totals` matches the campaign summary for the same range.
- **`lp_clicks` metric:** Summary and grouped stats rows now include landing-page click counts (`lp_clicks`) alongside visits (`clicks`).
- **API docs:** Kuma API reference updated for grouped stats parameters and response shapes.

## Changes in 1.1.4

- **REST API v1 (Kuma API):** Bearer-key auth, OpenAPI spec, CRUD for networks/offers/landing pages/campaigns, stats and click/conversion reporting (`/api/v1/*`, migration 060).
- **Settings → Kuma API:** In-app API key management and documentation.
- Meta adset/ad name target breakdown fixes (generated columns migration 053 + unified view sync).
- Patch release (version display and packaging aligned to 1.1.4).

## Changes in 1.1.0

- Campaign create wizard (multi-step flow)
- Facebook Meta campaign linking and sync
- Click lookup, campaign status filters, min postback payout (migration 058)
- Data retention cleanup CLI (`scripts/run-data-retention-cron.php`)
- Secure remember-me tokens (migration 059)
- Auth hardening: CSRF on login/settings, login rate limiting, API permission checks
- Performance: JSON generated columns and composite indexes (migrations 050–051)

## Changes in 1.0.1

- **Installer fix:** Fixed database migration 047 (remove fbclid from traffic sources) which failed on fresh installs with "Unknown column 'tokens_json' in 'JSON_TABLE'".
- Additional improvements and fixes included in this release.

## Installation

1. Download the latest production zip from your release assets.
2. Extract to your web server (document root should be the `public/` folder).
3. Run the web installer and complete all steps (requirements, database, config, migrations, admin).
4. On production, the installer locks and removes `install.php` automatically when not on localhost.

## Migrations

Fresh installs should apply forward migrations **001 through 086** (exclude `rollback_*.sql`). Existing installs: run pending migrations after upgrade (includes **086** for the clicks covering stats index).

## License

AGPL-3.0. See LICENSE in the package.

## Links

- Website: [https://simplekuma.com](https://simplekuma.com)
- YouTube: [https://www.youtube.com/@simplekumtracking](https://www.youtube.com/@simplekumtracking)
- Source & releases: [https://github.com/kumatrk/initialrelease](https://github.com/kumatrk/initialrelease)
