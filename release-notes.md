# Simple Kuma Tracker Version 1.1.5.5

## Changes in 1.1.5.5

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

Fresh installs should apply forward migrations **001 through 060** (exclude `rollback_*.sql`).

## License

AGPL-3.0. See LICENSE in the package.

## Links

- Website: [https://simplekuma.com](https://simplekuma.com)
- YouTube: [https://www.youtube.com/@simplekumtracking](https://www.youtube.com/@simplekumtracking)
- Source & releases: [https://github.com/kumatrk/initialrelease](https://github.com/kumatrk/initialrelease)
