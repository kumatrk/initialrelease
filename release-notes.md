# Simple Kuma Tracker Version 1.1.5.6

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

Fresh installs should apply forward migrations **001 through 082** (exclude `rollback_*.sql`). Existing installs: run pending migrations after upgrade (includes **081** for Meta click stats exclusion and **082** for conversion event mapping).

## License

AGPL-3.0. See LICENSE in the package.

## Links

- Website: [https://simplekuma.com](https://simplekuma.com)
- YouTube: [https://www.youtube.com/@simplekumtracking](https://www.youtube.com/@simplekumtracking)
- Source & releases: [https://github.com/kumatrk/initialrelease](https://github.com/kumatrk/initialrelease)
