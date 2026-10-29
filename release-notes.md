# Simple Kuma Tracker — Unreleased

> Catalog-only hold: **TikTok Cost 1.2.0** / **Ringba 1.2.0** remain unpublished on `kumatrk/honeycomb-addons` until explicitly resumed. Live catalog still shows older Ringba (e.g. 1.1.1) where present.

---

# Simple Kuma Tracker Version 1.1.5.31

## Changes in 1.1.5.31

- **Click redirect speed (privacy ranges pack):** `AnonymousRangeLookup` no longer loads the ~15MB `ranges.bin` into PHP memory on each click. Lookups open the file once per worker and binary-search with `fseek` (header + small probes only). Hot-path freshness uses the bin mtime — no `*.cidr` glob per click.
- **GeoIP on the click path:** `GeoResolver` is process-scoped (one open city DB chain per FPM worker) and constructs providers lazily so a DB-IP hit does not also open IP2Location/IPinfo. Redirect rules and `storeClick` reuse the same request’s geo/device enrichment instead of looking up twice.

---

# Simple Kuma Tracker Version 1.1.5.30

## Changes in 1.1.5.30

- **NewsBreak Add Integration form** no longer renders as an empty card. The Settings partial called a missing `Csrf::token()` helper and aborted mid-page; it now uses the shared CSRF field so Name / Access Token / timezone / Save show correctly.

---

# Simple Kuma Tracker Version 1.1.5.29

## Changes in 1.1.5.29

### NewsBreak built-in traffic source 
- Create from Template → NewsBreak includes click macros (`__CALLBACK_PARAM__`, campaign/flight/creative, OS) and a conversion postback to `business.newsbreak.com/tracking/attribute` (edit `event_type` to match Event Management).
- Settings → API Cost Updates → NewsBreak API Integrations: Access Token (encrypted), report timezone, optional ad account filter, Test connection.
- Hourly cost sync via `getIntegratedReport` (`DATE`+`HOUR`+`CAMPAIGN`, cost in cents → dollars) into `newsbreak_campaign_hourly_costs`, overlaid on Campaign Stats / dashboard / campaign list without scanning clicks for unfiltered totals.
- Wired into `kuma-traffic-api-cron.php`. Migrations **108–109**. After unpacking: **Settings → Updates → Update database**, add the Access Token, ensure campaign URLs pass `__CAMPAIGN_ID__` and `__CALLBACK_PARAM__`.

### Notifications and Account layout 
- Settings → Account is one card. Profile and Password sit side by side. Login page privacy is one row: the gate switch, and Setup for the URL parameter, token, and decoy address. Notifications follows on the same card.
- Password reset links go to one channel, chosen in a Password reset dropdown: email, Discord, Slack, Telegram, or ntfy. Default is Email. Grizzly Guard alerts go to every checked row. Setup on a row opens that channel’s webhook, token, or SMTP fields. Webhook URLs and the Telegram token are stored encrypted. A blank password or token keeps the saved secret.
- SMTP is optional. It is used when you turn it on, or when the installer had to add it because PHP `mail()` is missing. A username requires a password. If SMTP is turned on and the send fails, Kuma does not fall back to `mail()` and does not report the message as sent. The SMTP conversation is not written to the error log.
- Forgot-password still tells the visitor only that a link was sent if an account exists. The link goes through the reset channel above.
- The installer checks PHP `mail()`. When it is available, setup continues and nothing extra is stored. When it is disabled, setup continues only after SMTP connects or a Discord, Slack, or ntfy test is accepted. Telegram is added later under Account. A webhook that never connects is reported as not reached.
- Grizzly Guard alerts go to every checked Account channel: email, Discord, Slack, Telegram, ntfy, and Browser. The email or webhook on the Grizzly Guard tab is one extra channel and uses the same mail setup. The same address is not sent twice. The settings menu lists Grizzly Guard as text, the same as the other sections. Turning the account switch on plays the armed state in the browser. It is saved only when you save the tab.
- The Grizzly Guard tab uses the same compact sections as Account. Backup domains sit beside the extra alert and listing keys.

### User guide on simplekuma.com (simplekuma.com deploy is separate)
- Live guide source is `kumawebsite` (`content/user-guide/`), not the older static tree under `simplekuma/user-guide/`.
- Added or refreshed: Account notifications and Browser / Install app, new Grizzly Guard settings page, Meta delivery columns and Pause/Activate on Campaign Stats, Recent API failures on API Cost Updates, campaign Advanced bots / inactive redirect / Guard, RollerAds postback notes, login reset-channel wording, and matching troubleshooting.

### Admin PWA and browser alerts 
- The logged-in admin shell can be installed as an app (manifest, icons, service worker). Chrome and Edge show **Install app** in the top bar when the browser offers it. The button hides after install or when already running standalone.
- Account → Notifications has a **Browser** row for Grizzly Guard alerts only (not password resets). Setup → Enable on this device asks for notification permission and stores the subscription. Disable this device removes it. Alerts go to every subscribed browser when Browser is checked, the same way Discord or Slack do.
- VAPID keys are created once and stored encrypted. Subscriptions live in `push_subscriptions` (migration **107**). Unsubscribe is limited to the signed-in user. The service worker shows the notification and opens the dashboard on click. Production needs HTTPS. On iPhone, install the app first, then enable. SMS is not in this release. Landing-page element rotation stays deferred.
- After unpacking, use **Settings → Updates → Update database**. Composer must include `minishlink/web-push` (ships in the zip vendor tree).

### RollerAds traffic source 
- Create from Template still loads the RollerAds click tokens. Cost stays the `cost` parameter on the click, filled with `{cost}` (RollerAds’ own BeMob example). Older token lists say `{costPerClick}` for click spend; if a live campaign shows blank cost, switch that one placeholder. RollerAds does not publish a spend API.
- The template now includes their conversion postback: `https://trk.roller.ad/conversion/aid/YOUR_ACCOUNT_NUMBER/YOUR_TRACKING_KEY` with their click id, the payout, and goal 1. Replace the two placeholders with the URL from RollerAds → Campaigns → Tracking. Kuma does not send the call until those are replaced. Migration **106** fills that URL on an existing RollerAds source that does not already have one. After unpacking, use **Settings → Updates → Update database**.

### API failure log 
- Settings → API Cost Updates shows Recent API failures on the usage card. Each row is the time, which API, what it was doing, the HTTP code, and the vendor message, with a Copy button for a report.
- Meta (cost sync, pause and activate, page view, conversion API), Google Ads, Honeycomb catalog and addon calls, traffic-source postbacks, Safe Browsing, and VirusTotal write a row only when the call fails. Tokens and account ids are stripped. The same failure is kept once every 15 minutes. Rows older than 14 days are dropped. The list is `api_failure_log` (migration **105**). After unpacking, use **Settings → Updates → Update database**.

### Pause and activate Meta ads 
- On Campaign Stats, a mapped Meta campaign shows the current status on Ad and Ad set rows, with Pause or Activate beside the name. The button changes that whole ad or ad set. A status such as Disapproved is shown as text only.
- The report does not call Meta to draw the table. Status is stored in `meta_entity_status` (migration **104**). The hourly cost update reads ad and ad set status for mapped campaigns. Pause and Activate send the change, then store the status Meta returns. Each change is written to `meta_entity_actions`.
- After unpacking, use **Settings → Updates → Update database**. The token needs ads management. Creating ads stays out.

### Meta delivery columns 
- Campaign Stats can add Meta impressions, Meta reach, Meta clicks, Meta CTR, Meta CPC, Meta CPM, and Meta spend. They start off. Customize Columns is where you turn one on. They are not on the dashboard and they are not in the default column set.
- The numbers are Meta's, for a mapped ad or ad set. Other breakdown rows stay blank. Meta clicks are Meta's clicks, not Kuma visitors. CTR, CPC, and CPM are calculated from the stored impressions, clicks, and spend. Reach is shown for a single day. A range of days leaves reach blank because Meta's reach is not a sum.
- The report does not call Meta and does not scan clicks. The hourly cost update stores the extra fields from the Insights response it already makes, plus one ad-level request, into `meta_delivery_daily` (migration **103**). After unpacking, use **Settings → Updates → Update database**. A column that is off does not read that table. Meta status is separate and lives on Pause and Activate.

### Anomaly alerts 
- Settings → Grizzly Guard has an Anomaly alerts board. Conversion drop, revenue under cost, bot spike, zone spike, postback failures, tracker errors, and no conversions each start off. They run only while the account switch is on, and a repeat of the same alert waits six hours.
- Click, cost, and conversion checks sum `clicks_daily_summary` the same way campaign totals do. Zone spikes read `clicks_stats_by_token_daily`. Postback failures count failed rows from the last hour. Tracker errors only fire when the server error log shows a click that could not be saved. None of these scan the clicks table.
- Those alerts use every checked Account channel (including Browser), plus the extra address on the Grizzly Guard tab. The settings menu lists Grizzly Guard as text.

### Campaign Stats breakdown columns and postback headings 
- Expanding a breakdown row now hides the same columns as the header. Nested rows were still drawing every metric, so the table ran off to the right and a hidden eCPM (revenue per thousand visitors) showed up as a large dollar amount. eCPM is still `(revenue / visitors) × 1000` when that column is turned on.
- Postback URLs headings are **Multiple payouts on the same click** and **Email opt-ins**. They no longer name other trackers.

### Cheap-click ROI and click-path schema checks 
- `clicks_daily_summary.roi` was `DECIMAL(10,4)`, so a cheap click (about $0.0003) plus a normal payout overflowed it. The conversion stayed in `conversions` and the token stats, but the daily summary kept conversions at 0. The hourly rebuild then failed for that whole date and rolled it back. Migration **101** widens `roi` to `DECIMAL(20,4)`. The live update, the hourly rebuild, and the backfill store NULL when a percent still cannot fit, so one row cannot fail the day. After unpacking, use **Settings → Updates → Update database**.
- Click insert, daily-summary checks, the stats-exclusion flag, and postback CAPI column checks no longer run `SHOW COLUMNS` or `information_schema` on every request. A hit is remembered for the process, and in APCu when that extension is loaded. A missing column is rechecked after a minute.
- The README and the installer requirement line say to set `innodb_buffer_pool_size` well above the 128MB default on a dedicated database server (about half of RAM). The hourly rebuild is still a full rewrite of yesterday and today.

### Grizzly Guard 
- Settings has a Grizzly Guard tab. The account switch starts off. Optional Google Safe Browsing and VirusTotal keys are stored encrypted. Alerts go to every checked Account channel. The email or webhook on this tab is one extra address. Backup domains 1, 2, and 3 are an ordered list of verified tracking domains. The tab lists what Guard last did.
- Each campaign, in the editor and the create wizard, can choose Off, My backups, or Full Grizzly Guard. The existing fallback offer is the backup offer. A backup landing page and a plain last-resort URL are optional. Offer tokens are not added to that URL. The campaign does not pick a backup domain.
- The checker is `scripts/grizzly-guard-cron.php`, about every 5 minutes, and Settings can run the same check now. It records whether a domain is up or down. It does not change the campaign’s saved tracking domain. A visit that still arrives on a down domain is sent to the next healthy domain in the list, counted once, and the alert says the ad URL was not changed. Full mode may use another verified domain only after that list is exhausted. Edge campaigns are not hopped.
- A down offer is pulled so weight stays on offers that are up, and it is put back when the URL is healthy again. My backups does that only when the fallback offer, backup landing page, or last-resort URL can take the visit. When every landing page in the path is down, the visit goes straight to an offer that is up and the visitor log shows Emergency bypassed. Deep Funnel offer and landing-page rotation is left alone. A Deep Funnel campaign can still hop domains when its mode is on.
- A 403 or a rate limit is not treated as down. Migration **100** adds the campaign fields, the action log, and the hold rows. After unpacking, use **Settings → Updates → Update database**.

### Report columns and dashboard periods 
- The dashboard and Campaign Stats can show, hide, and reorder their metric columns. The choice is saved on the user (migration **099**). Campaign, traffic source, and the action buttons stay put.
- Campaign Stats can add LP clicks, CPC, CPM, eCPM, and EPC. Those rates are calculated from the numbers already on the row. CPC and EPC use clicks. CPM and eCPM use visitors. The dashboard can add LP + direct, CPC, CPM, and eCPM the same way. Nothing new is queried for a column.
- The dashboard has Day, Week, and Month totals for the selected range, read from `clicks_daily_summary`. Week starts on Monday. Cost in that table is tracked click cost. The Cost card above it is still ad-network spend for the whole range.
- Totals by period starts collapsed, the same way Performance Trends does. Show totals opens it, Hide totals closes it, and the choice is saved on the user (migration **102**). The period query is skipped while it is hidden.
- When the selected range does not line up with UTC dates, the period rows still come from the daily summary, and a note says the cards can include hours outside those dates. There is no extra scan of the clicks table.

### Bot and proxy routing 
- Checked on the campaign editor, the create wizard, and a live tracking link. Test clicks were removed. The campaign was left on Allow.
- Each campaign can choose what happens to known bots and to proxy, datacenter, hosting, or VPN traffic (connection type Corporate). Default is **Allow**, so existing campaigns keep sending that traffic to the offer or landing page.
- **Record only** stores the click and returns an empty page. **Safe URL** stores the click and redirects to an http(s) address with no offer tokens appended. Suspected bots are included only when Settings already counts them as bots.
- The same choice applies on the redirect, on a redirectless landing page (the page script sends a Safe URL visitor away; a later offer click is stopped), and on a Deep Funnel hop.
- Edge redirect campaigns stay on Allow. The Cloudflare worker does not classify bots yet.
- Migration **098** adds `bot_route_mode` and `bot_route_url`. After unpacking, use **Settings → Updates → Update database**.

### Proxy and datacenter ranges 
- Tor exit nodes, the FireHOL anonymous netset, and cloud ranges (AWS, Google Cloud, Azure, DigitalOcean, OVH) can be downloaded with `php scripts/download-anonymous-ranges.php` into `geoip/ranges/anonymous.cidr`. The downloader also writes a packed cache, `geoip/ranges/ranges.bin`. Refresh that command when the lists should be updated. The text file and the cache are not part of git.
- On the click write path, an address in that file is stored as connection type Corporate, unless the traffic source already sent cellular. Allow, Record only, and Safe URL are unchanged. A normal browser is still counted in stats.
- Dashboard and campaign stats do not open the range file. They keep reading the stored click and the daily summaries.
- The redirect does not query the clicks table for this check. Each PHP worker reads the packed cache once (about 15 MB). Later clicks in that worker search the copy already in memory (about 0.2 ms). If the cache is missing, the click keeps the ASN label and the redirect still happens. The large text file is not parsed on the click.
- Paid IP APIs, a JavaScript challenge, and per-click rate limits are not part of this.

### Themes: five new presets, compact side menu, themed login art 
- Header theme dropdown adds **Midnight**, **Timber**, **Executive**, **Onyx**, and **Ember** after Original, Clean, and Dark. Original, Clean, and Dark are unchanged. The saved id for Original stays `light`, so existing users do not flip themes on upgrade.
- Midnight and Onyx are dark (ice blue and champagne). Timber, Executive, and Ember are light (terracotta, navy, and burnt orange). Executive gold is a secondary highlight, not the main button. Profit stays green and cost stays red on every theme.
- Every theme’s side menu uses the small bear-head logo, with the menu pulled up underneath it (same spacing as Clean). Original uses the brown bear. Dark uses the red-outlined bear on a transparent background.
- The login page uses a matching desktop and mobile poster for each of the five new themes. Original, Clean, and Dark keep their existing posters.

### Production fixes from customer notes + GitHub issue 12 
- **Device detection cache.** Matomo regex parses are cached on disk under `storage/cache/devicedetector/` (per user-agent JSON plus OPcache PHP regex files). Repeat clicks skip the ~450ms cold parse. Classifications and result fields are unchanged. Cache files are gitignored and are not part of the zip.
- **Edge click ingest on nginx.** `/api/edge-click` is handed to `api/edge-click.php` before admin login, including when nginx `try_files` falls through to `index.php`. Deploy probes origin ingest and stays unhealthy until that round-trip is HTTP 2xx. The Worker accepts only 2xx (redirects to the login page are failures). Settings → Edge includes the nginx snippet:
  `location = /api/edge-click { rewrite ^ /api/edge-click.php last; }`
  Operators who already deployed Edge must click **Deploy / Update Worker** again after this upgrade so Cloudflare picks up the current Worker. A PHP upgrade alone does not replace a Worker already on Cloudflare.
- **Permission walk on every admin page.** The tree normalizer no longer opens unreadable `storage/` or `.git/` directories (for example a `0700` root backup folder). `tree_permissions_normalized` is set only after a successful walk.
- **Breakdown speed for built-in columns.** Browser, OS, country, device, and the other built-in click columns use `idx_campaign_ts` (campaign + time). Date, hour, week, landing, and offer stay on the covering index. Summary-first KPIs, charts, and Meta/Google cost overlay are unchanged.
- **More than one tracking domain on Edge.** Settings → Edge stores a list of Cloudflare zone + route pairs (the old single zone/pattern still works and is kept in sync with the first route). Disable removes every saved route. Domain verify can pass when DNS is Cloudflare-proxied if `https://that-host/api/domain-check.php` returns this install's proof token. The Worker returns 404 for unknown paths on the tracking host (including `/` and `/login.php`) so the admin login is not what ads see. Click paths, postbacks, pixels, `/lp/…`, and the domain-check URL still reach origin. Click recording and cost capture are unchanged.
- **Online upgrade error "Data too long for column 'error_log'" (issue 12).** A long failure (about 1,880 permission lines, ~228 KB, when files were owned by root) overflowed `update_logs.error_log` (`TEXT`) and the database error replaced the real cause. Migration **097** widens that column to `MEDIUMTEXT`. The updater stores at most 60 KB, shows the first lines of the real failure in Settings plus a count of the rest, and a failed log write cannot hide the original error. The full path list is not copied into the PHP error log. After unpacking, use **Settings → Updates → Update database** so 097 is applied.
- **Internal errors and logs are not returned to visitors.**
  - Click URLs (`km.php`) respond "Service unavailable" on a database connection failure. The driver message stays in the server log.
  - Redirectless `track.php` returns a generic tracking failure. Exception text, SQL errors, and file paths stay in the server log unless `TRACK_DEBUG` is on.
  - Honeycomb addon HTTP errors return "Addon request failed" instead of the raw exception.
  - Cost-sync logs go to `storage/logs` only. If that directory cannot be written, they go to the PHP error log. They are not written under `public/logs`. Apache denies `/logs/` and `*.log`. Nginx needs `location ^~ /logs/ { deny all; }` in the live server config (`.htaccess` does not apply on nginx). See `docker/nginx.conf.example` and `WEB_SERVER_SETUP.md`.

### Core: force offer by slot (standalone, no Honeycomb)
- LP CTAs can target a specific offer in the campaign rotation: `kTrack(2)`, `data-k-offer="2"`, or `/lp/click.php?click_id=…&o=2` (optional `offer_id=` for catalog id)
- Plain `kTrack()` still weighted-rotates; DTO/LP/Split behavior otherwise unchanged
- Documented in campaign CTA guide

### Deep Funnels (Honeycomb utility + thin core)
- New `flow_type=DeepFunnel` (shown when **Kuma Deep Funnels** is enabled): up to 5 levels × 5 LPs in one campaign via `rotation_json.deep_funnel`
- Opaque `link_key` per LP and offer, and `level_key` per level. Public hops use `/lp/hop.php?k=` (`kTrackHop`). A specific offer uses `/lp/click.php?k=` (`kTrackKey`). Plain `kTrack()` still rotates offers. Slot numbers stay as editor and stats labels only.
- Builder sits under Offer Rotation, in the same place as regular landing-page rotation (that blue box hides on Deep Funnel campaigns). The create wizard uses the same order and includes the level list, but not the code generator.
- Campaign editor code generator is two dropdowns: the page the button is on, then the destination. Destinations are grouped under non-selectable **Level** headings (specific LP, or rotate the LPs on that level) plus an **Offers** group (specific offer, or rotate offers). Each level has **Equal weights**.
- Copied HTML is one button and key-only (no landing-page or offer names). Another button on the same page is the same start page with a different destination, copied again. The note under the box (not copied) names the route, says `chomp.js` from the normal redirectless block must already be on the page, and says to save the campaign before pasting so the keys persist.
- Panel colors follow the active theme (accent, surfaces, text). Original stays green because its accent is green.
- Full path logged on the click (`extra_json.funnel_path` / `funnel_path_key`) and pre-agg tables `clicks_funnel_step_daily` + `clicks_funnel_path_daily` (migration **096**). The Funnel stats tab reads only those tables.
- Campaign Stats **Funnel** tab (Deep Funnel campaigns only): level drop-off, step chips, path list with node filter
- Addon source: `honeycomb-addons/addons/deep-funnels` **1.0.12** (Import from the Honeycomb catalog)
- Overview / Breakdown / Chart for normal campaigns remain on the existing summary-first path

### Campaign editor: regular landing-page rows
- Landing-page rotation rows use the same column layout as offer rows (dropdown and weight fill their columns).
- Adding a landing page splits the weights into numbers instead of leaving a blank **Weight %** field. Create wizard Equal Weights and the add button use the same row class.

### Conversion Log: inbound postback extras + click details view
- Inbound `postback.php` / `pixel.php` / `ringba-postback.php` store `sub1`–`sub10` and other non-reserved query params on the conversion (`source_json`)
- Conversion Log columns/filters for those extras (CSV export included); documented on Postback URLs
- **With click details** tab: same filters, each conversion joined to its click (device, cost, tracking tokens) for search/sort — conversion-scoped only (not Campaign Stats dimensions)
- Settings → Retention help text rewritten in plain language (no internal summary/path jargon)
- Local QA: `scripts/seed-postback-extras-conversions.php`
### Campaign editor UX
- Flow Type on the campaign editor is radio cards (Direct to Offer, Landing Page → Offer, Split Test, Deep Funnel) instead of a dropdown. Values are unchanged (`DTO` / `LP` / `Split` / `DeepFunnel`), so existing campaigns keep the same routing after upgrade.
- Split traffic % panel sits under Flow Type and uses theme tokens.
- Grizzly Guard and other optional blocks use a clearer collapsed accordion with a chevron.
- Honeycomb page cron helper: "Alternative: Honeycomb-only job" uses the same accordion chevron treatment; cron commands wrap on narrow screens.
- Mobile Flow Type cards stay single-column with readable titles (no letter-stack / radio overlap).

---

# Simple Kuma Tracker Version 1.1.5.28

## Changes in 1.1.5.28

### Bug fix: Facebook Fetch Ad Accounts (`business_management` + opaque errors)
- Fetch no longer requests Graph field `business` (that field needs `business_management`; standard `ads_read` + `ads_management` tokens failed with Meta error 100)
- Fields used: `id,name,account_id,currency,timezone_name`
- AJAX/UI returns curl errors and Graph `error.message` / `code` instead of a generic failure; sanitized lines go to PHP `error_log` (token never logged)
- Token help text notes required permissions: `ads_read` + `ads_management`
- Cost cron unchanged — Insights already uses spend / adset / campaign ids only

### Facebook Marketing proxy fortification
- Fetch/create/update sync use `ProxyHandler::getProxyConfig()` (same encrypted-password shape as cost cron)
- `ProxyHandler` accepts plaintext `proxy_pass` for validate-before-save / Test Proxy
- Save validates the access token **through the proxy** when enabled (blank token on edit reuses stored token)
- Settings: **Test Proxy → Facebook Graph** verifies egress to `graph.facebook.com`
- InsightsClient proxy logging uses `proxy_host` / `proxy_port` / `proxy_type` correctly

### Bug fix: UTF-8 / non-ASCII in campaign redirect-rule form fields
- Admin HTML sends `Content-Type: text/html; charset=utf-8`; `public/.htaccess` sets `AddDefaultCharset UTF-8`
- `DbTimezone::init()` applies `DB_CHARSET` + UTC on campaign UI, stats API, REST v1, and click/track paths
- Redirect-rule inputs use `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`
- If a rule was already saved garbled, re-enter the correct value after upgrading

### Bug fix: LP CTA clicks missing on token breakdown rows
- `DailySummaryUpdater::upsertLpClickUpdate` also increments `lp_clicks` on `clicks_stats_by_token_daily` / `clicks_stats_by_token_hourly` (forward-only; historical rows unchanged)

### Performance: scoped conversions joins on breakdown / group_by
- Tracker-dimension breakdowns no longer scan all conversions account-wide; clicks-first join scoped to campaign + date range
- Fixes slow `group_by=browser|os|language|country|…` (and native Breakdown UI) on large conversion histories

### Core: Honeycomb generic addon HTTP API + frontend assets
- `AddonHttpHandler` + `http_api` capability; `public/api/honeycomb-addon.php` / `honeycomb-asset.php`
- Kernel HTTP handlers + frontend asset registration; campaign editor/wizard load via `honeycomb-frontend-assets` partial
- Legacy shims: `honeycomb-tiktok.php` / `honeycomb-ringba.php` → generic dispatcher
- **Catalog hold:** do not publish TikTok Cost 1.2.0 or Ringba 1.2.0 to `kumatrk/honeycomb-addons` until that work is explicitly resumed (core is ready; catalog addons are not live yet)

### Packaging / upgrade notes
- `version.php` → **1.1.5.28**; `upgrade-manifest.json` + `UPGRADE.md` aligned
- Migrations footer remains **001–095** (no new migration in this patch)

---
# Simple Kuma Tracker Version 1.1.5.27

## Changes in 1.1.5.27

### Hotfix: campaign editor layout (cached CSS)
- Sectioned campaign edit/create form styles moved to dedicated `campaign-form.css` and linked from the global layout
- All layout CSS and page-specific stylesheets now use `filemtime` cache-busting (`sk_css_href`) so CDN/browser caches cannot keep a pre-refactor `main.css` after update
- Packager requires `campaign-form.css`, form partials, and cache-bust wiring so this cannot ship unstyled again

# Simple Kuma Tracker Version 1.1.5.26

## Changes in 1.1.5.26

### Campaign wizard â†” editor parity (Ringba + Edge)
- Shared `public/assets/js/campaign-ringba.js` so Ringba API campaign picker / Refresh works on create wizard and classic editor
- Ringba LP copy panel available on wizard Traffic step (same partial as Tracking on edit)
- Create wizard: **Edge redirect** checkbox; review summary includes Honeycomb bindings, Edge, and Meta CAPI
- Meta CAPI options in wizard show pixel id (same as editor)

### Honeycomb / packaging hygiene
- Packager requires migrations **094** / **095**, Ringba public endpoints, and shared Ringba assets
- Empty secured `honeycomb/addons/` (+ `.htaccess`) included in the production zip
- `upgrade-manifest.json` aligned to this release; release-notes Migrations footer through **095**

### Ringba Honeycomb addon (catalog)
- Ringba v1.1.1 published to `kumatrk/honeycomb-addons` â€” Import from **System â†’ Honeycomb** (or local install script)

# Simple Kuma Tracker Version 1.1.5.25

## Changes in 1.1.5.25

### Honeycomb: secure addon update checks (like core)
- Installed Honeycomb addons are compared to the official catalog (`kumatrk/honeycomb-addons`); outdated addons show an admin banner and alerts on **System â†’ Honeycomb**
- Per-addon **Update** uses the same verified path as Import: allowlisted HTTPS, mandatory catalog SHA-256, safe zip extract, slug/`min_kuma` checks â€” no user-supplied zip URLs, no silent auto-apply, no remote PHP on check
- Updates preserve enabled/disabled status and leave credentials / campaign bindings in place; downgrades are rejected
- Background check API: `public/api-check-honeycomb-updates.php` (session-auth, cache-first â€” same pattern as core `api-check-updates.php`)
- Packaging: new public API + `AddonUpdateChecker` included in the production zip allowlist / required set

### Campaigns: redirect when paused or archived
- New campaign setting **When paused or archived** (editor Campaign section + create wizard Basics, with review summary)
- **Off** â€” tracking link still returns not found (previous behavior)
- **Redirect to another campaign** â€” 302 to that campaignâ€™s tracking link; inbound query tokens are forwarded; a normal click is recorded on the **target** campaign (not the paused/archived one)
- **Redirect to a custom URL** â€” 302 to an http(s) URL (no Kuma click)
- Target campaign must be **active**; self-targeting is blocked; deleted campaigns are unchanged (no soft-delete in this release)
- Edge Redirect falls through to origin for inactive campaigns, so origin applies the redirect
- Migration: `095_add_campaign_inactive_redirect.sql`

### Settings: remove duplicate Honeycomb nav item
- Settings sidebar no longer lists **Honeycomb** (it only linked to the main **System â†’ Honeycomb** page)

### Honeycomb: Ringba utility addon (pay-per-call)
- New addon source: `honeycomb-addons/addons/ringba` (v1.1.1) â€” Import from **System â†’ Honeycomb** catalog (`kumatrk/honeycomb-addons`), or `php scripts/install-local-honeycomb-addon.php honeycomb-addons/addons/ringba`
- **Type `utility`:** works with any traffic source (Whop, Meta, Propeller, etc.) â€” not a traffic-source cost sync
- Campaign **Honeycomb** section: enable Ringba, paste or pick call-tracking / campaign id (`CAâ€¦` â€” used as `//b-js.ringba.com/CAâ€¦.js`), optional number-to-replace
- **Ringba tag name for Kumaâ€™s click id** (default `click_id`): label Ringba uses to store **Kumaâ€™s** click id on the call for payout pixels â€” explicitly not Propeller/Meta/ad-network click ids (copy clarified so users donâ€™t mix them up)
- Optional **Ringba API** credentials (account ID + API access token, `Authorization: Token â€¦`) with connection test on save; campaign dropdown + Refresh list via `public/api/honeycomb-ringba.php` (`status` / `campaigns` / `tags`); paste mode still works without API
- Campaign **Tracking** (editor) and create wizard Traffic step: `#ringba-lp-codes-panel` copy script (`_rgba_tags` for Kumaâ€™s click id + Ringba number-pool JS) when enabled; shared `public/assets/js/campaign-ringba.js` powers API campaign picker on both surfaces
- Honeycomb Options: setup checklist + Connected / Converted / Payout pixel URLs â†’ `postback.php` (`et=call_connected` / `call_converted` / `call_payout`)
- Alias endpoint: `public/ringba-postback.php` (accepts clickid / call_id / call_revenue aliases â†’ same `ConversionTracker` pipeline)
- Core: `CampaignFieldsProvider::alwaysVisible()` + campaign-editor JS so utility panels are not gated by traffic-source `provider_key`
- Packaging: `public/ringba-postback.php` and `public/api/honeycomb-ringba.php` added to production zip public PHP allowlist

# Simple Kuma Tracker Version 1.1.5.24

## Changes in 1.1.5.24

### Campaign editor: sectioned Main Settings
- Classic campaign edit/add **Main Settings** reorganized into collapsible sections (expanded by default): **Campaign**, **Tracking**, **Traffic Source Integration**, **Honeycomb**, **Postbacks & Conversion Delivery**
- Neutral section chrome (no rainbow wizard steps); mobile stacks grids cleanly
- Create wizard traffic step shares the same Honeycomb field renderer

### Meta / Facebook integration layout
- **Traffic Source Integration** splits Meta into peer columns: **Conversion reporting** (CAPI) and **Cost tracking** (ad account)
- Meta campaign picker + Refresh sit full-width under cost tracking (dependent step, not crammed into one column)

### Honeycomb: real `campaign_fields` hook
- New `CampaignFieldsProvider` + `HoneycombKernel::addCampaignFieldsProvider()` so addons register campaign-editor UI
- Core `GenericBindingCampaignFields` covers cost sync + conversion export; Whop / Taboola bootstraps register providers
- Manifest `provides: campaign_fields` now gates the Honeycomb section; bindings still save via `campaign_addon_bindings` / `honeycomb_binding[{slug}]`

# Simple Kuma Tracker Version 1.1.5.23

## Changes in 1.1.5.23

### Campaign Stats: Hour and Week breakdown dimensions
- New Core dimensions **Hour** and **Week** in Breakdown (same **+ Add dimension** flow as Date â€” no new tab)
- Nest with other dims (e.g. Offer â†’ Hour, Ad â†’ Hour) via presets
- **Week** = ISO Monday-start calendar week (label = week-start date), fast path from `clicks_daily_summary`
- **Day of week** = Mondayâ€¦Sunday (aggregate across the range), fast path from `clicks_daily_summary`
- **Hour** L0 uses the lean covering-index path (same family as Chart hourly)
- New pre-agg table `clicks_stats_by_token_hourly` (migration `094`) for token â†” hour nests â€” on-write with clicks/conversions, never age-purged
- Rebuild: `scripts/rebuild-token-daily-summaries.php` now rebuilds daily + hourly token tables together

# Simple Kuma Tracker Version 1.1.5.22

## Changes in 1.1.5.22

### Honeycomb: installable traffic & conversion addons
- New **System â†’ Honeycomb** page: browse the fixed catalog (`kumatrk/honeycomb-addons`), search/filter by type, import verified zips, enable/disable without uninstalling, and remove addons
- Addons live outside the Kuma zip under `honeycomb/addons/` on each server â€” core updates and addon updates stay independent
- Safe install path: HTTPS GitHub host allowlist, required SHA-256, zip path/symlink/size limits, extension allowlist, blocked server config files
- Campaign edit / create wizard: Honeycomb bindings driven by addon `provides` â€” conversion export and remote cost IDs can appear together
- Hourly spend for Honeycomb networks lands in `honeycomb_campaign_hourly_costs` and overlays campaign KPI / chart totals via `HoneycombCostAggregator` (**summary-first** â€” no per-click cost joins)
- Recommended one cron for Facebook cost, Google Ads cost, and Honeycomb: `scripts/kuma-traffic-api-cron.php` (Honeycomb-only fallback: `scripts/honeycomb-cron.php`)
- Migrations: `090_honeycomb_kernel`, `092_honeycomb_runtime`, `093_honeycomb_conversion_exports`
- **Taboola Cost API** and **Whop Ads** ship from the Honeycomb catalog (not inside this zip) â€” import from Honeycomb after upgrade

### Whop Ads (via Honeycomb catalog)
- Template traffic source with Whop click tokens (`wacid` / `wasid` / `waid` / Meta UTMs)
- Single landing page when the campaign uses a Whop traffic source (Whop Pixel + Kuma CTA handoff)
- Conversion export to Whop Events API (default `lead`); click capture of `_wuid` + original landing URL
- Campaign-level spend sync from Whop Ad Reports (`ad_campaign:stats:read`) when a Whop ad campaign ID is bound
- Cost badge: Honeycomb `integrated_api` sources show Live API cost (not â€œvariables onlyâ€)

### Taboola Cost API (via Honeycomb catalog)
- Backstage client-credentials spend sync into the same Honeycomb hourly cost table and stats overlay

### Server Status: VPS CPU / RAM / disk monitor
- New **System â†’ Server Status** page: live disk meters, best-effort CPU load pressure + RAM (Linux `/proc`), MySQL click-table sizes, and Run archive & retention now
- In-app warning banner when CPU / RAM / disk cross thresholds (configurable on the page; no email)
- Retention cron records host metrics alongside disk; raw purge still preserves report summaries
- Theme-aware UI (readable in dark mode)

### Tracker: ISP, connection type, and browser language
- New click columns: `isp`, `connection_type`, `language` (migration `091_add_clicks_isp_connection_language.sql`)
- Campaign Stats tracker breakdowns: **ISP**, **Connection Type**, **Browser Language**
- **ISP** from redistributable **DB-IP ASN Lite** (`geoip/DBIP-ASN-Lite.mmdb`, CC BY 4.0) â€” packaged in the customer zip
- Do **not** ship MaxMind GeoLite2-ASN in the zip (license); private drop-in still works if present
- **Connection type**: traffic-source tokens first, else ASN org heuristic â†’ Cellular / Broadband / Corporate / Unknown
- **Browser language**: primary Accept-Language tag on origin; edge ingest keeps worker `language`
- Refresh ASN DB: `php scripts/download-geoip-databases.php --dbip-asn` (also in `--all`)
- Historical clicks stay N/A until new traffic; Settings â†’ GeoIP shows ASN/ISP status

### Offer / LP rotation weights (edge UX)
- Origin links apply new weights **immediately** after save
- Edge (Cloudflare KV) can take **up to about a minute** after sync to use new weights worldwide
- Campaign UI copy on Edge box + offer/LP rotation explains this

### Packaging
- Customer zip includes Honeycomb kernel + empty secured `honeycomb/` runtime (addons are catalog-installed, not bundled)
- Required migrations include Honeycomb **090 / 092 / 093** and ISP **091**
- Production crons allowlisted: `kuma-traffic-api-cron.php`, `honeycomb-cron.php`
- **Installer:** migration **091** (ISP / connection / language) is idempotent in PHP â€” re-running after a partial apply no longer fails with `Duplicate column name 'isp'`
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
- Email is shown only if it was already on the click or postback â€” no new email column or lead CRM
- Campaign Stats columns and Hermes queries are unchanged

### Updates tab: release notes readable in dark mode
- Whatâ€™s New notes use theme tokens instead of a white box with remapped light text

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
- `GET /api/v1/stats/campaigns/{id}?group_by=â€¦` now uses the same `CampaignStatsV2Service` breakdown path as Campaign Stats UI
- Unfiltered token/geo/device breakdowns prefer pre-aggregates / lean cover indexes instead of raw `clicks` scans

### Fix: LP offer update fatal (`incrementSummaryClickRow` argument count)
- Restored correct `clickInc` / `lpInc` / `directInc` / `cost` arguments after the bot-summary signature change
- Fixes fatal errors on `lp/click.php` â†’ offer rotation (can break LP / popunder offer attribution)

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
- Enhanced contrast, distinct pill styling, and hover/active states for traffic source postback tokens in Settings â†’ Integrations and Offer edit dialogs so tokens are clearly legible and clickable.

## Changes in 1.1.5.18

### Attribution Window: Unlimited Option
- Settings â†’ Privacy now supports **Unlimited** attribution window (`0` days) in addition to presets (7, 14, 30, 60, 90, 180, 365 days)
- Postbacks and pixels will accept conversions indefinitely when set to Unlimited without age cutoff

### Data Lifecycle & Hot Clicks Archiving
- Moves old clicks from the hot `clicks` table into `clicks_archive` (`archive_after_days`, default: 365 days)
- Scheduled via `scripts/run-data-retention-cron.php` or on-demand in Settings â†’ Privacy (â€œRun archive & retention nowâ€)
- **Summary-first protection**: Historical pre-aggregated reporting tables (`clicks_daily_summary`, `clicks_stats_by_token_daily`) are preserved intact so Hermes fast-path campaign KPI/breakdown queries remain instant and unaffected by raw click archiving or purging

### Affiliate Networks: Clean Postback UI
- Removed legacy S2S postback template input from Add/Edit Network and table views
- Outbound postbacks are configured under **Settings â†’ Integrations** (Custom Postbacks), and inbound postback URLs under **Postback URLs**

## Changes in 1.1.5.17

### Fix: Campaign save blocked by browser â€œ0.01â€ validation (locale language)
- Default CPC and minimum postback payout use `step="any"` so optional money fields are not blocked by HTML5 step mismatch
- Browser validation bubbles use the browser UI language (e.g. Chinese) â€” that was not a Kuma translation bug
- Stored values are normalized for display to avoid float junk tripping the input

### Fix: Campaign save 502 when Edge Redirect sync hangs
- Edge KV sync after campaign/slug save is deferred and coalesced (one sync per campaign per request)
- On PHP-FPM, the HTTP response is finished before Cloudflare is contacted (`fastcgi_finish_request`)
- Hook Cloudflare API calls use a short timeout (8s) so nginx is far less likely to return 502 Bad Gateway on Save

## Changes in 1.1.5.16

### Fix: Nginx tracking links no longer open the login page
- Pretty click URLs (`/km/â€¦`, `/go/â€¦`, `/c/â€¦`) that fall through to the admin front controller now hand off to `km.php` before auth (common bare Nginx `try_files` setup)
- Shipped `docker/nginx.conf.example` with the recommended click + API rewrites
- Installer complete screen and docs call out Nginx rewrite requirements (Apache `.htaccess` unchanged)

## Changes in 1.1.5.15

### Fix: Restore tracking domain Bypass Verification
- Settings â†’ Domains again shows **Bypass Verification** when automated DNS/SSL checks fail or stay pending
- Manually approved domains get status **Verified (Manual)** and can be selected on campaigns and postbacks
- Confirms at your own risk before enabling a domain that did not pass automated verification

## Changes in 1.1.5.14

### Fix: Create campaign with custom traffic sources
- User-added sources (e.g. Tacolo) are selectable for campaigns even when live cost API is not integrated yet
- Manual / URL cost still works; Bing remains the only â€œ(Coming soon)â€ holdout
- Campaign create wizard: avoid silent Create failures (`novalidate`, hidden `save_campaign`, clearer validation feedback); clear Facebook fields when leaving Facebook

### Fix: Facebook Marketing API resync with hidden token
- â€œLeave blank to keep existing tokenâ€ now applies to Fetch & Save / resync (JS + AJAX + post-update sync use the stored token)

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
- Meta spend uses hourly/map overlay instead of per-click cost joins on unfiltered reports (prevents 20s timeouts around ~50kâ€“100k clicks)
- Campaign Stats breakdown (Ad Set â†’ Landing â†’ Region), KPI summary, and charts no longer time out when rapidly changing date ranges
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

### About page â€” contributors & open source
- Settings â†’ About highlights **The Kuma Club** for major code and idea contributors
- Open-source credits section for libraries we ship (including Crawler-Detect and Matomo DeviceDetector) with links to their repos

### Bot detection
- Click ingest detects known crawlers/bots (Matomo DeviceDetector + JayBizzle Crawler-Detect)
- Settings toggles to enable detection and optionally exclude known/suspected bots from stats
- Known bots are stored for audit but can be omitted from reports without slowing redirects

### Cloudflare Edge Redirect (Phase 1)
- Optional Cloudflare Worker redirects eligible campaigns at the edge with async click ingest back to origin
- Settings â†’ Edge Redirect: deploy Worker, sync campaign KV snapshots, health check, rotate ingest secret, disable (removes route + clears KV)
- Per-campaign **Edge redirect** toggle; migration **083** (`edge_enabled` + sync metadata)
- Phase 1 stays on origin for redirectless, cloaking/referrer modes, offer caps, and advanced URL `{tokens}`
- Worker mirrors origin param allowlists (`pass_to_lp` / `pass_to_offer`), slug attribution, and HMAC-secured ingest

## Changes in 1.1.5.8

### About page + Kuma Club
- Settings â†’ About redesigned with a theme-aware hero banner (light and dark artwork)
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
- Settings â†’ Integrations copies the `.csv` URL and documents that Username/Password fields can be any values (auth is the `key` query param)

## Changes in 1.1.5.5

### Meta CAPI custom conversion event mapping
- Inbound postbacks/pixels accept optional funnel keys (`et`, with aliases `event_type` / `event`) stored as `conversions.event_key` (migration **082**)
- Settings â†’ Meta CAPI integrations: map inbound keys (e.g. `register`, `ftd`, `rebill`) to Meta standard or custom event names; default event still used when `et` is missing/unmapped
- Multi-step funnels on one click: distinct Meta `event_id` / `order_id` so FTD + rebill do not collide
- Optional non-blocking **Send PageView on click** for linked CAPI integrations
- Custom postbacks gain `{event_key}` token for traffic-source S2S URLs
- Postback URLs page documents multi-event funnel examples

### Login privacy gate (custom login token)
- Optional login-page gate: require a secret query token (default `?mv=â€¦`, param name configurable) before the login form is shown
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
- **Settings â†’ Kuma API:** In-app API key management and documentation.
- Meta adset/ad name target breakdown fixes (generated columns migration 053 + unified view sync).
- Patch release (version display and packaging aligned to 1.1.4).

## Changes in 1.1.0

- Campaign create wizard (multi-step flow)
- Facebook Meta campaign linking and sync
- Click lookup, campaign status filters, min postback payout (migration 058)
- Data retention cleanup CLI (`scripts/run-data-retention-cron.php`)
- Secure remember-me tokens (migration 059)
- Auth hardening: CSRF on login/settings, login rate limiting, API permission checks
- Performance: JSON generated columns and composite indexes (migrations 050â€“051)

## Changes in 1.0.1

- **Installer fix:** Fixed database migration 047 (remove fbclid from traffic sources) which failed on fresh installs with "Unknown column 'tokens_json' in 'JSON_TABLE'".
- Additional improvements and fixes included in this release.

## Installation

1. Download the latest production zip from your release assets.
2. Extract to your web server (document root should be the `public/` folder).
3. Run the web installer and complete all steps (requirements, database, config, migrations, admin).
4. On production, the installer locks and removes `install.php` automatically when not on localhost.

## Migrations

Fresh installs should apply forward migrations **001 through 095** (exclude `rollback_*.sql`). Existing installs: run pending migrations after upgrade (includes **090â€“093** Honeycomb, **091** ISP/connection/language, **094** token-hourly stats, **095** inactive campaign redirect).

## License

AGPL-3.0. See LICENSE in the package.

## Links

- Website: [https://simplekuma.com](https://simplekuma.com)
- YouTube: [https://www.youtube.com/@simplekumtracking](https://www.youtube.com/@simplekumtracking)
- Source & releases: [https://github.com/kumatrk/initialrelease](https://github.com/kumatrk/initialrelease)
