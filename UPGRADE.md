# Upgrading an existing Simple Kuma install (agent runbook)

Use this document when applying **v1.1.5.2** (or later production zip) over a live install **without** losing campaigns, database data, or legacy `/km/` tracking links.

Designed for operators, shell scripts, and AI agents (Hermes, Codex, etc.) with SSH or file access to the server.

**Machine-readable:** `upgrade-manifest.json` in the zip root (workflow steps, preserved paths, migrations, success criteria).

---

## For AI agents (copy this brief)

**Goal:** Overlay v1.1.5.2 files onto an existing install. **Never** overwrite `config/config.php` or delete `storage/`. **Never** run `install.php` on production.

**Inputs needed from operator:**
1. Live install path (contains `config/config.php`)
2. Path to `simplekuma-v1.1.5.2.zip` or extracted folder
3. Approval to run DB backup + upgrade

**Commands (in order):**
```bash
cd LIVE_INSTALL_ROOT
cp config/config.php config/config.php.bak-$(date +%Y%m%d)
php scripts/apply-release-upgrade.php --source=/path/to/simplekuma-v1.1.5.2.zip
php scripts/apply-release-upgrade.php --source=/path/to/simplekuma-v1.1.5.2.zip --apply
php scripts/apply-release-upgrade.php --migrations
php scripts/verify-production-security.php
```

**Success criteria:** Admin loads, campaigns visible, `/km/{key}` still redirects, `version.php` shows 1.1.5.2, migration 061 applied.

**Do not change** `BASE_URL` or ad URLs unless operator explicitly requests Safe Browsing remediation.

---

## What this upgrade does

| Preserved | Updated |
|-----------|---------|
| `config/config.php` (DB, `BASE_URL`, secrets) | Application PHP (`src/`, `views/`, `public/` code) |
| All database tables & campaign rows | `version.php` → new version |
| Existing `/km/{key}` ad URLs (still work) | Pending SQL migrations (e.g. **061**) |
| `storage/logs`, `storage/cache`, Google Ads configs | Safe Browsing hardening (routes, API modal, `go.php`, etc.) |

**Does not** require re-running the web installer on production.

---

## What agents must NEVER do

1. **Delete or overwrite** `config/config.php`
2. **Run** `public/install.php` on a production host that is already installed
3. **`rm -rf`** the install directory or `storage/`
4. **Change** `BASE_URL` / tracking domains without explicit operator approval
5. **Assume** upgrading alone clears Google Safe Browsing — operator may still need a tracking subdomain + review

---

## Prerequisites

- SSH or SFTP access to the server
- Path to live install (contains `config/config.php`, `public/index.php`)
- `simplekuma-v1.1.5.2.zip` (or extracted folder)
- PHP CLI on the server (`php` in PATH)
- **Database backup** before `--migrations`

---

## Quick path (recommended)

Run from the **existing install root** (directory that contains `config/` and `scripts/`).

### 1. Backup

```bash
cd /path/to/simplekuma
mysqldump -u USER -p DATABASE > backup-$(date +%Y%m%d)-pre-1.1.5.sql
cp config/config.php config/config.php.bak-$(date +%Y%m%d)
```

### 2. Extract the release zip (do not replace config)

```bash
mkdir -p /tmp/sk-v115
unzip -q /path/to/simplekuma-v1.1.5.2.zip -d /tmp/sk-v1152
```

### 3. Dry-run (no writes)

```bash
php scripts/apply-release-upgrade.php \
  --source=/tmp/sk-v115 \
  --target=/path/to/simplekuma
```

(Omitting `--apply` previews files that would be copied.)

Review output: file count, version `1.1.5.1` → `1.1.5.2` (or your current → target).

### 4. Apply files

```bash
php scripts/apply-release-upgrade.php \
  --source=/tmp/sk-v115 \
  --target=/path/to/simplekuma \
  --apply
```

This copies release files **over** the install and **skips** `config/config.php`, storage logs/cache, and (on production) `public/install.php`.

### 5. Run pending migrations

```bash
php scripts/apply-release-upgrade.php --migrations
```

Or combined with file copy:

```bash
php scripts/apply-release-upgrade.php \
  --source=/tmp/sk-v115 \
  --target=/path/to/simplekuma \
  --apply \
  --migrations
```

Expected new migration on 1.1.4 → 1.1.5:

- `061_rename_cloaking_mode_to_referrer_mode.sql` — renames column `cloaking_mode` → `referrer_mode` (data unchanged)

### 6. Verify

```bash
php scripts/verify-production-security.php
```

Manual checks:

- [ ] Admin login works (`index.php?page=campaign-list`)
- [ ] Campaign list loads; tracking link modal opens (API)
- [ ] Legacy click URL still works: `https://YOUR-TRACKING-DOMAIN/km/{campaign_key}`
- [ ] New `go.php` exists: `https://YOUR-TRACKING-DOMAIN/go.php?k={campaign_key}` (after optional config below)
- [ ] Sidebar / About shows **v1.1.5.2**

### 7. JSON output (for agents)

```bash
php scripts/apply-release-upgrade.php \
  --source=/tmp/sk-v115 \
  --target=/path/to/simplekuma \
  --apply --migrations --json
```

---

## One-liner using zip as source

```bash
php scripts/apply-release-upgrade.php \
  --source=/path/to/simplekuma-v1.1.5.2.zip \
  --target=/path/to/live/install \
  --apply --migrations
```

The script extracts the zip to a temp directory automatically.

---

## Manual upgrade (if script unavailable)

Copy **from extracted zip → live install**, overlay only:

| Copy | Do not copy |
|------|-------------|
| `src/` | `config/config.php` |
| `views/` | `storage/logs/*` |
| `vendor/` | `storage/cache/*` |
| `database/migrations/` | `storage/google_ads_configs/*` |
| `public/` (code + assets) | `.env` |
| Root: `.htaccess`, `app.php`, `index.php`, `version.php` | |

Then run migrations via installer migrations step **on localhost only**, or:

```bash
php -r "
require 'vendor/autoload.php';
require 'config/config.php';
\$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
\$r = new SimpleKuma\Database\MigrationRunner(\$db);
\$r->run() or die(implode(PHP_EOL, \$r->getErrors()));
echo 'Applied: ' . implode(', ', \$r->getAppliedMigrations());
"
```

---

## Optional: new click URL format (`go.php?k=`)

**Not required** for upgrade. Existing `/km/` links keep working.

To generate **new** links in CPV-style format, append to `config/config.php` (only if not already present):

```php
define('CLICK_URL_STYLE', 'query');
define('CLICK_ENTRY_SCRIPT', 'go.php');
define('CLICK_PATH_PREFIX', 'go');
```

Without these lines, upgraded installs keep generating `/km/{key}` URLs (backward compatible).

---

## Safe Browsing (why operators upgrade)

v1.1.5 adds defense in depth for false “Dangerous site” flags:

- Admin route `campaign-list` (redirect from legacy `?page=campaigns`)
- Campaign list no longer embeds tracking tokens in HTML (API modal)
- `noindex` + `robots.txt`
- `go.php` entry for new installs / optional config
- `cloak.php` returns 410 Gone

**Operator should also:** use a **dedicated tracking subdomain** for click URLs (not the admin domain).

---

## Rollback

1. Restore DB: `mysql ... < backup-YYYYMMDD-pre-1.1.5.sql`
2. Restore `config/config.php` from `.bak`
3. Restore code tree from pre-upgrade tarball if you made one

The upgrade script does not delete files; rollback is restore-from-backup.

---

## Troubleshooting

| Issue | Action |
|-------|--------|
| Migration 061 fails “unknown column cloaking_mode” | Column may already be `referrer_mode`; check `SHOW COLUMNS FROM campaigns` |
| `config.php hash changed` error | Restore config from backup; never let zip overwrite it |
| Campaigns missing | Upgrade did not touch DB — check DB credentials in config |
| `/km/` 404 after upgrade | Check `.htaccess` / `public/.htaccess` were copied; Apache `mod_rewrite` on |
| Modal “could not load tracking link” | Ensure `public/api-campaign-tracking-link.php` exists; user logged in |

---

## Version reference

- **Target release:** 1.1.5.2
- **Upgrade tool:** `scripts/apply-release-upgrade.php` (shipped in production zip)
- **In-app auto-update UI:** not finished — use this runbook instead
