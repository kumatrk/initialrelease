# Simple Kuma Tracker Version 1.1.5.12

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

Fresh installs should apply forward migrations **001 through 083** (exclude `rollback_*.sql`). Existing installs: run pending migrations after upgrade (includes **083** for Cloudflare edge campaign flags).

## License

AGPL-3.0. See LICENSE in the package.

## Links

- Website: [https://simplekuma.com](https://simplekuma.com)
- YouTube: [https://www.youtube.com/@simplekumtracking](https://www.youtube.com/@simplekumtracking)
- Source & releases: [https://github.com/kumatrk/initialrelease](https://github.com/kumatrk/initialrelease)
