<?php
/**
 * Knowledgebase articles data — shared between index.php and article.php.
 *
 * External articles have 'url' key (open in new tab).
 * Local articles have 'slug' and 'content' keys (render inline).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$articles = [
    // ── How to use Optee (pinned, local) ──────────────────────
    [
        'title'    => 'How to Install Optee on WordPress',
        'slug'     => 'install-optee',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>Optee is a WordPress plugin. You need a WordPress installation on your own hosting to run it.</p>

<h3>Requirements</h3>
<ul>
<li>WordPress 6.0 or higher</li>
<li>PHP 8.0 or higher</li>
<li>A cron job (the setup wizard helps you configure this)</li>
</ul>

<p><strong>Important:</strong> Install Optee on a fresh WordPress installation, not on an existing site. Optee sets its own theme and homepage, which will override your current setup.</p>

<h3>Step 1: Upload the Plugin</h3>
<ol>
<li>Download the Optee plugin .zip file</li>
<li>In your WordPress admin, go to <strong>Plugins > Add New > Upload Plugin</strong></li>
<li>Select the .zip file and click <strong>Install Now</strong></li>
<li>Click <strong>Activate</strong></li>
</ol>

<h3>Step 2: Setup Wizard</h3>
<p>After activation, the setup wizard launches automatically. It walks you through 6 steps:</p>
<ol>
<li><strong>License Activation</strong> - Enter your license key</li>
<li><strong>System Check</strong> - Verifies your server meets all requirements, with auto-fix buttons if anything is missing</li>
<li><strong>Timezone</strong> - Syncs your WordPress timezone with your browser</li>
<li><strong>Cron Setup</strong> - Configure the automation cron job (cPanel auto-setup, manual, or email your host)</li>
<li><strong>Email Verification</strong> - Confirms your server can send notification emails</li>
<li><strong>Summary</strong> - Review and go to the dashboard</li>
</ol>

<h3>Step 3: Install Integrations</h3>
<p>Optee comes as a core plugin only. Integrations (traffic sources, trackers, etc.) are installed separately. Go to any integration page (e.g., Traffic Sources) and click the <strong>Install</strong> button on the card for the platform you use. It downloads and activates automatically.</p>
',
    ],
    [
        'title'    => 'How to Install Integration Plugins',
        'slug'     => 'install-integrations',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>Optee uses separate plugins for each integration. This way you only install what you actually use.</p>

<h3>Installing an Integration</h3>
<ol>
<li>Go to the integration page from the sidebar (e.g., <strong>Traffic Sources</strong>, <strong>Trackers</strong>, <strong>Affiliate Networks</strong>, etc.)</li>
<li>Find the platform you want and click the <strong>Install</strong> button on its card</li>
<li>Optee downloads and activates the plugin automatically</li>
<li>Once installed, the card changes to show a <strong>Configure</strong> button</li>
</ol>

<h3>Configuring an Integration</h3>
<ol>
<li>Click <strong>Configure</strong> on the integration card</li>
<li>Enter your API credentials (API key, token, or username/password depending on the platform)</li>
<li>Click <strong>Test Connection</strong> to verify everything works</li>
<li>Click <strong>Save</strong> to store the credentials</li>
</ol>

<p>Your API credentials are encrypted using OpenSSL with your WordPress security salts before being stored in the database.</p>

<h3>Where to Find Your API Credentials</h3>
<p>Each integration has a <strong>Get it here</strong> link next to the credential field that takes you directly to the relevant page on the platform where you can find or generate your API key.</p>
',
    ],
    [
        'title'    => 'How to Create Your First Campaign',
        'slug'     => 'create-campaign',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>A campaign in Optee links your traffic source campaign to your tracker campaign, with optional connections to an affiliate network and antifraud tool.</p>

<h3>Creating a Campaign</h3>
<ol>
<li>Go to <strong>Campaigns</strong> and click <strong>+ New Campaign</strong></li>
<li>Enter a campaign name</li>
<li><strong>Traffic Source</strong> - Select your traffic source from the dropdown, then pick the specific campaign from the searchable dropdown (it loads your campaigns from the API)</li>
<li><strong>Tracker</strong> - Select your tracker and pick the campaign. Then select the <strong>Zone (Source) field</strong> which tells Optee which custom field in your tracker contains the zone/source ID from the traffic source</li>
<li><strong>Other Modules</strong> (optional):
    <ul>
    <li><strong>Affiliate Network</strong> + <strong>Offer ID</strong> - Enables offer status monitoring and auto pause/resume rules</li>
    <li><strong>Is Smartlink</strong> - Check this if you are using a smartlink (disables offer-specific rules)</li>
    <li><strong>Antifraud Tool</strong> - Adds fraud metrics (bot %, valid %, proxy %) to your campaign stats table</li>
    </ul>
</li>
<li>Click <strong>Create Campaign</strong></li>
</ol>

<h3>Funnel-Based Trackers (FunnelFlux)</h3>
<p>Some trackers, like FunnelFlux Pro, aggregate many traffic sources and campaigns into a single funnel. For these, extra optional fields appear under the Tracker section:</p>
<ul>
<li><strong>Traffic Source</strong> - Scope the stats to one of the funnels traffic sources</li>
<li><strong>Campaign ID(s)</strong> - Narrow further to specific traffic-source campaign IDs (comma-separated). This is useful when several of your campaigns point at the same funnel and you want each Optee campaign to show only its own numbers. Detected campaign IDs appear as click-to-add chips when the funnel has data</li>
</ul>

<h3>Tips</h3>
<ul>
<li>The searchable dropdowns pull campaigns directly from each platform API, so you do not need to enter campaign IDs manually</li>
<li>You can edit a campaign later to add or change connected modules</li>
<li>The campaign appears on your Dashboard immediately after creation</li>
</ul>
',
    ],
    [
        'title'    => 'How to Read Campaign Statistics',
        'slug'     => 'campaign-statistics',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>The campaign statistics page shows data from all your connected modules in a single table with color-coded column groups.</p>

<h3>Column Groups</h3>
<ul>
<li><strong>Traffic Source</strong> (blue) - Impressions, clicks, cost, CPM, CPC, CPA, CTR</li>
<li><strong>Antifraud</strong> (orange) - Visits, valid hits, valid %, traffic loss %, proxy %, junk %, bot %, cost</li>
<li><strong>Tracker</strong> (purple) - Visits, clicks, conversions, revenue, cost, profit, ROI, CTR, CV, CPM, CPA</li>
<li><strong>Monetization</strong> (green) - Impressions, eCPM, earnings</li>
</ul>

<h3>Drilldowns</h3>
<p>Use the drilldown tabs at the top to group data by:</p>
<ul>
<li><strong>Default / Zones</strong> - Zone-level breakdown (most useful for optimization)</li>
<li><strong>Days</strong> - Daily performance</li>
<li><strong>Countries, Browsers, OS, ISP</strong> - Traffic quality analysis</li>
<li><strong>Landings, Offers</strong> - Compare lander and offer performance</li>
<li><strong>Conversions</strong> - Individual conversion log</li>
</ul>

<h3>Campaign Actions</h3>
<p>Click the cog icon next to the campaign title to open the Campaign Actions popup. From there you can pause/resume traffic, edit the campaign, set monetization sources, add rules, and more.</p>

<h3>Date Range</h3>
<p>Use the date range selector in the top right to change the reporting period. Options include Today, Yesterday, Last 3/7/14/30/90 days, or a <strong>custom date range</strong> you pick yourself.</p>

<h3>View Toggles</h3>
<p>The stats toolbar has toggles to focus the table on what matters:</p>
<ul>
<li><strong>Hide blocked</strong> - Hide zones you have already blocked</li>
<li><strong>Hide empty rows</strong> - Hide zones with no activity in the period</li>
<li><strong>Hide unused</strong> - Show only the columns you have ticked (configured in Column settings)</li>
</ul>

<h3>Traffic Source Balance</h3>
<p>Where the traffic source API supports it, a <strong>Balance</strong> column shows your current account balance alongside the campaign stats.</p>

<h3>Custom Columns and Reports</h3>
<p>You can add your own calculated columns with formulas and heatmaps - see <em>How to Build Custom Columns</em>. For a printable per-day optimization timeline of a single campaign, see <em>How to Use the Campaign Report</em>.</p>
',
    ],
    [
        'title'    => 'How to Create Automation Rules',
        'slug'     => 'create-rules',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>Rules automate campaign optimization. They check conditions against your campaign data and execute actions when conditions are met.</p>

<h3>Creating a Rule</h3>
<ol>
<li>Go to <strong>Rules</strong> and click <strong>+ New Rule</strong>, or click <strong>Add Rule</strong> from a campaign group</li>
<li>Select a campaign</li>
<li>Enter a rule name (e.g., "Block zones with high cost and no conversions")</li>
<li>Choose an <strong>Action</strong>: Block Zone(s), Unblock Zone(s), Pause Traffic, or Resume Traffic</li>
<li>Add one or more <strong>Conditions</strong> (all must be true):
    <ul>
    <li>Select the data source (Traffic Source, Tracker, or Affiliate Network)</li>
    <li>Select the metric (cost, conversions, ROI, etc.) or offer status</li>
    <li>Select an operator (greater than, less than, equal to, etc.)</li>
    <li>Enter the threshold value</li>
    </ul>
</li>
<li>Set <strong>Run Every</strong> (how often the rule checks: 5 min, 1 hour, etc.)</li>
<li>Set <strong>Rule Status</strong> to Active or Paused</li>
<li>Optionally configure <strong>If Execution is Successful</strong>:
    <ul>
    <li><strong>Pause this rule</strong> - The rule deactivates after a successful execution</li>
    <li><strong>Pause this campaign (local + traffic source)</strong> - Pauses the campaign both in Optee and on the traffic source</li>
    </ul>
</li>
<li>Optionally <strong>Save as Template</strong> to reuse this rule configuration on other campaigns</li>
<li>Click <strong>Create Rule</strong></li>
</ol>

<h3>Common Rule Examples</h3>
<ul>
<li><strong>Block unprofitable zones:</strong> IF Traffic Source cost > $5 AND Tracker conversions == 0 then Block Zones</li>
<li><strong>Pause bleeding campaigns:</strong> IF Tracker ROI < -50% then Pause Traffic</li>
<li><strong>Auto pause/resume by offer status:</strong> IF Affiliate Network Offer Status is Paused then Pause Traffic</li>
</ul>

<h3>Preview and Run Now</h3>
<p>Use <strong>Preview</strong> to see which zones would be affected without executing the action. Use <strong>Run Now</strong> to execute the rule immediately.</p>

<h3>Negative Thresholds</h3>
<p>Thresholds accept negative numbers, so you can write conditions like <strong>ROI &lt; -50%</strong> or <strong>Profit &lt; -10</strong> to catch losing campaigns.</p>

<h3>Snooze, Duplicate, and Templates</h3>
<ul>
<li><strong>Snooze</strong> - Temporarily pause a rule for a set time without deleting it; it resumes automatically. A red <strong>Off</strong> badge shows when a rule is paused or snoozed</li>
<li><strong>Duplicate</strong> - Copy an existing rule to reuse its conditions on another campaign, then adjust</li>
<li><strong>Save as Template</strong> - Save a rule configuration as a template and apply it to other campaigns in one click</li>
</ul>
',
    ],
    [
        'title'    => 'How to Use Offer Status Rules',
        'slug'     => 'offer-status-rules',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>Offer status rules automatically pause or resume traffic based on the live status of your offer on the affiliate network. This prevents wasted ad spend when an offer goes down.</p>

<h3>Requirements</h3>
<ul>
<li>The campaign must have an <strong>Affiliate Network</strong> connected</li>
<li>The campaign must have an <strong>Offer ID</strong> set</li>
<li>The campaign must <strong>not</strong> be a smartlink</li>
</ul>

<h3>How to Set It Up</h3>
<ol>
<li>Create a new rule for the campaign</li>
<li>In the condition builder, select <strong>Affiliate Network</strong> as the source</li>
<li>The condition changes to a simple status check: <strong>Offer Status is Running</strong> or <strong>Offer Status is Paused</strong></li>
<li>Select the action: <strong>Pause Traffic</strong> or <strong>Resume Traffic</strong></li>
</ol>

<h3>Recommended Setup</h3>
<p>Create two rules per campaign:</p>
<ul>
<li><strong>Rule 1:</strong> IF Offer Status is Paused then Pause Traffic (run every 5 min)</li>
<li><strong>Rule 2:</strong> IF Offer Status is Running then Resume Traffic (run every 5 min)</li>
</ul>
<p>This way, traffic stops automatically when the offer goes down and resumes when it comes back.</p>

<h3>How It Works</h3>
<p>When the rule runs, Optee calls the affiliate network API to check the current offer status. It compares this against your condition and executes the action if matched. The offer status, name, and result are shown in the rule execution log.</p>
',
    ],
    [
        'title'    => 'How to Use the Command Palette',
        'slug'     => 'command-palette',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>The command palette is a spotlight-style search you can open from anywhere in the app by pressing <strong>Ctrl+K</strong> (or Cmd+K on Mac).</p>

<h3>What You Can Do</h3>
<ul>
<li><strong>Quick Actions</strong> - New Campaign, New Rule, Run Automation, Update Earnings, Update Balances, Check Updates, Check Support Notifications</li>
<li><strong>Navigate to Pages</strong> - Type any page name (Dashboard, Rules, Settings, etc.) to jump there</li>
<li><strong>Search Campaigns</strong> - Type a campaign name to go directly to its stats page</li>
<li><strong>Configure Integrations</strong> - Type a platform name to jump to its configure page</li>
<li><strong>Knowledgebase</strong> - Search articles by title</li>
</ul>

<h3>How to Use It</h3>
<ol>
<li>Press <strong>Ctrl+K</strong> to open</li>
<li>Start typing to filter results</li>
<li>Use arrow keys to navigate, Enter to select</li>
<li>Press Escape to close</li>
</ol>

<p>The palette searches across all categories simultaneously. Multi-word searches work (e.g., typing "prop config" will match "PropellerAds" in the Configure section).</p>
',
    ],
    [
        'title'    => 'How to Set Up the Cron Job',
        'slug'     => 'cron-setup',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>Optee needs a cron job to run automation rules, fetch balances and earnings, scan VirusTotal URLs, and check for updates. The cron runs every minute by default.</p>

<h3>Option 1: cPanel Auto-Setup</h3>
<p>If your hosting uses cPanel, the setup wizard can create the cron job automatically. Just enter your cPanel username and password.</p>

<h3>Option 2: Manual Setup</h3>
<p>Add this line to your crontab (via cPanel Cron Jobs, SSH, or your hosting control panel):</p>
<p><code>* * * * * wget -q --no-check-certificate -O /dev/null "YOUR_CRON_URL" >/dev/null 2>&1</code></p>
<p>You can find your exact cron URL in <strong>Settings > System Status</strong> under Cron Configuration.</p>

<h3>Option 3: DirectAdmin</h3>
<p>If your hosting uses DirectAdmin instead of cPanel, the wizard has a dedicated tab that walks you through adding the cron job in the DirectAdmin panel.</p>

<h3>Option 4: Free External Cron (cron-job.org)</h3>
<p>No cron on your host? The wizard includes a <strong>Free Cron Job</strong> tab that shows how to set up a free scheduled call to your cron URL using cron-job.org - handy on shared hosting that does not let you add cron jobs.</p>

<h3>Option 5: Email Your Host</h3>
<p>The setup wizard provides a ready-made email template you can copy and send to your hosting provider support team.</p>

<h3>Verifying the Cron</h3>
<p>After setting up the cron, check <strong>Settings > System Status</strong>. You should see recent timestamps for "Last Run Started" and "Last Run Completed."</p>

<h3>Running Manually</h3>
<p>You can trigger automation manually anytime from the user dropdown menu (click your name in the top right) by selecting <strong>Run Automation</strong>, or use the command palette (Ctrl+K) and select "Run Automation."</p>
',
    ],
    [
        'title'    => 'How to Monitor Domains with VirusTotal',
        'slug'     => 'virustotal-monitoring',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>The VirusTotal integration lets you monitor your domains, landing pages, and offer URLs for security threats. This helps you catch domain blocks before your traffic source suspends your campaign.</p>

<h3>Setup</h3>
<ol>
<li>Install and configure the <strong>VirusTotal</strong> integration (you need a free VirusTotal API key)</li>
<li>Go to the <strong>VirusTotal</strong> page from the sidebar</li>
</ol>

<h3>Adding URLs to Monitor</h3>
<ol>
<li>Click <strong>+ Add New URL</strong></li>
<li>Enter a label (e.g., "My Landing Page") and the full URL</li>
<li>The URL will be scanned on the next cron run</li>
</ol>

<h3>Reading the Results</h3>
<p>Each URL shows a daily score like <strong>10/95</strong>, meaning 10 out of 95 security vendors flagged it. Click on any score cell to expand the vendor detail row, which shows exactly which vendors flagged it and their verdict (malicious, suspicious, phishing, clean, unrated).</p>

<h3>Score Change Notifications</h3>
<p>When a URL score changes compared to the previous scan, Optee sends you a notification and an email alert. The notification includes the score change (e.g., "+1") and lists the vendors that changed status.</p>

<h3>Scan Interval</h3>
<p>You can configure how often URLs are scanned (default: every 30 minutes). Note that lower intervals consume more of your VirusTotal API quota.</p>
',
    ],
    [
        'title'    => 'How to Track Balances and Earnings',
        'slug'     => 'balances-earnings',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<h3>Balances</h3>
<p>The Balances page shows daily balance snapshots from your connected platforms. Balances are fetched automatically by the cron job.</p>
<ul>
<li>Use the date range selector to view 7, 14, 30, 60, or 90 days</li>
<li>Click <strong>Reload</strong> to fetch the latest balances immediately</li>
<li>For platforms without API balance support, click <strong>configure here</strong> to enable manual balance tracking and enter values by clicking on cells</li>
<li>A manually entered balance <strong>carries forward</strong> to following days until you enter a new value, so you do not have to retype it every day</li>
</ul>

<h3>Earnings</h3>
<p>The Earnings page shows daily revenue and cost from your integrations, grouped by year. Revenue comes from monetization platforms and affiliate networks. Cost comes from traffic sources.</p>
<ul>
<li>The table shows Total Revenue, Total Cost, Profit, and ROI columns followed by individual platform columns</li>
<li>Select a year from the dropdown to view historical data</li>
<li>Click <strong>Reload</strong> to fetch the latest earnings from all modules</li>
</ul>

<h3>Manual Update via Command Palette</h3>
<p>You can manually trigger a balances or earnings fetch at any time using the <strong>Command Palette</strong>. Press <strong>Ctrl+K</strong> (or <strong>Cmd+K</strong> on Mac) to open it, then type <strong>Update Balances</strong> or <strong>Update Earnings</strong> and select the command. This runs the fetch immediately without waiting for the next cron cycle.</p>

<p>Both pages also update automatically during each cron run. One integration is fetched per cron cycle to avoid API rate limits and timeouts.</p>
',
    ],
    [
        'title'    => 'Understanding the Cache System',
        'slug'     => 'cache-system',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>Every time you load the Dashboard or Campaign Statistics, Optee makes API calls to your tracker, traffic source, and other connected platforms. Some of these APIs have rate limits or can be slow, so Optee caches the results.</p>

<h3>How It Works</h3>
<ul>
<li>The default cache duration (TTL) is <strong>5 minutes</strong></li>
<li>If you reload the page within that window, you get instant results without hitting the APIs again</li>
<li>After the TTL expires, the next page load fetches fresh data</li>
<li><strong>Rules always use live data</strong> and are never affected by the cache</li>
</ul>

<h3>Cache Indicator</h3>
<p>The Dashboard shows a cache indicator badge next to the "Overview" text:</p>
<ul>
<li><strong>Live data</strong> (green) - Data was fetched fresh from the APIs</li>
<li><strong>Partially cached</strong> / <strong>Cached data</strong> (yellow) - Some or all data came from cache</li>
</ul>
<p>Click the badge to see per-module cache status and options.</p>

<h3>Configuration</h3>
<p>You can change the cache TTL in <strong>Settings > Profile > Cache Settings</strong>. Set to 0 to disable caching entirely (not recommended for platforms with rate limits).</p>
<p>You can also check <strong>Always load live data</strong> in the cache popover to bypass the cache on every page load.</p>
',
    ],
    [
        'title'    => 'How to Use the Campaign Actions Menu',
        'slug'     => 'campaign-actions',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>The Campaign Actions menu gives you quick access to all campaign operations without leaving the statistics page.</p>

<h3>Opening the Menu</h3>
<p>On the Campaign Statistics page, click the <strong>cog icon</strong> next to the campaign title. A popup opens with a searchable grid of actions.</p>

<h3>Available Actions</h3>
<ul>
<li><strong>Edit on traffic source</strong> - Opens the campaign editor on the traffic source platform (new tab)</li>
<li><strong>View statistics on traffic source</strong> - Opens the stats page on the traffic source platform (new tab)</li>
<li><strong>Edit local campaign</strong> - Edit the campaign settings in Optee</li>
<li><strong>Copy tracker campaign ID</strong> - Copies the tracker campaign ID to clipboard</li>
<li><strong>Update campaign title</strong> - Rename the campaign</li>
<li><strong>Stop traffic</strong> - Pause the campaign on the traffic source via API</li>
<li><strong>Resume traffic</strong> - Start the campaign on the traffic source via API</li>
<li><strong>Pause local campaign</strong> - Pause the campaign locally in Optee</li>
<li><strong>Resume local campaign</strong> - Resume the local campaign</li>
<li><strong>Remove from dashboard</strong> - Archive the campaign (rules continue running if campaign is active)</li>
<li><strong>Set Monetization Source</strong> - Link Monetag, ProPush, Partners.House, RollerAds Publisher, or Monetizer zones to this campaign</li>
<li><strong>Add Rule</strong> - Create a new automation rule for this campaign</li>
<li><strong>View Rules</strong> - See all rules for this campaign</li>
<li><strong>Column settings</strong> - Configure which columns are visible in the stats table</li>
<li><strong>Campaign Report</strong> - Open the printable per-day optimization timeline for this campaign</li>
<li><strong>Notes</strong> - Keep private notes on the campaign (ideas, changes you made, things to watch)</li>
</ul>

<p>You can search within the popup by typing in the search field at the top.</p>
',
    ],
    [
        'title'    => 'How to Build Custom Columns',
        'slug'     => 'custom-columns',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>Custom Columns let you add your own calculated metrics to the campaign statistics table using a formula built from the data your connected modules already return (cost, revenue, conversions, valid %, earnings, and so on).</p>

<h3>Creating a Custom Column</h3>
<ol>
<li>On the Campaign Statistics page, open <strong>Column settings</strong> (from the Campaign Actions cog, or the columns button on the stats toolbar)</li>
<li>Click <strong>+ Add Custom Column</strong></li>
<li>Enter a <strong>Column name</strong></li>
<li>Enter a <strong>Formula</strong> referencing base metrics in square brackets, for example <code>[Tracker] Revenue - [Traffic Source] Cost</code>. You can use + - * / and parentheses</li>
<li>Choose a <strong>Format</strong>: Number, Currency, or Percentage</li>
<li>Optionally set <strong>Higher is better</strong> or <strong>Lower is better</strong> to colour the column with a green heatmap gradient (best values brightest), which makes scanning for winners and losers instant</li>
<li>Save</li>
</ol>

<h3>Per-Campaign Formula Overrides</h3>
<p>A custom column is global by default (it appears on every campaign). If one campaign needs a different calculation, edit the column while viewing that campaign and choose to save it as a <strong>per-campaign override</strong> — only that campaign uses the new formula, everything else keeps the global one. You can <strong>reset</strong> an override back to the global formula at any time.</p>

<h3>Managing Columns</h3>
<ul>
<li><strong>Show/hide</strong> - Tick the Show box next to any column (built-in or custom) to control what appears in the table</li>
<li><strong>Reorder</strong> - Drag rows in the Column settings modal to change column order</li>
<li><strong>Hide unused</strong> - Toggle this to list only the columns you have ticked, which makes reordering a large list easier</li>
</ul>
',
    ],
    [
        'title'    => 'How to Use Alerts',
        'slug'     => 'alerts',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>Alerts notify you when something needs your attention. Unlike Rules (which take an action such as blocking a zone or pausing traffic), an Alert only <strong>notifies</strong> you - by in-app notification and email - so you can decide what to do.</p>

<h3>Alert Types</h3>
<ul>
<li><strong>Balance threshold</strong> - Get notified when a platform balance drops below (or rises above) a value you set. Checked every time balances refresh, so you never run a traffic source dry unexpectedly</li>
<li><strong>Campaign performance</strong> - Get notified when a campaign metric crosses a threshold (for example ROI below -30%, or spend above a limit). Checked once per day at a time you choose</li>
</ul>

<h3>Creating an Alert</h3>
<ol>
<li>Go to <strong>Alerts</strong> in the sidebar and click <strong>New Alert</strong></li>
<li>Pick the alert type (balance or campaign)</li>
<li>Choose the platform or campaign, the metric, an operator, and the threshold value</li>
<li>Save</li>
</ol>

<h3>Good to Know</h3>
<ul>
<li>Each alert notifies at most <strong>once per day</strong>, so you are not spammed if a condition stays true</li>
<li>Hover an alert row and click <strong>Logs</strong> to jump straight to its history in the Logs page</li>
<li>Alerts never change your campaigns - if you want automatic action, use a <strong>Rule</strong> instead</li>
</ul>
',
    ],
    [
        'title'    => 'How to Use the Campaign Report',
        'slug'     => 'campaign-report',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>The Campaign Report is a clean, printable optimization timeline for a single campaign. It is designed to be read at a glance or exported to PDF for your records.</p>

<h3>Opening the Report</h3>
<ol>
<li>Open a campaign on the Campaign Statistics page</li>
<li>Click the cog icon to open <strong>Campaign Actions</strong></li>
<li>Click the <strong>Campaign Report</strong> tile</li>
</ol>

<h3>What It Shows</h3>
<ul>
<li>A <strong>per-day performance chart</strong> so you can see the trend over the selected timeframe</li>
<li>A <strong>day-by-day table</strong> (newest first) with the key metrics for the campaign</li>
<li>Campaign details and the reporting period at the top</li>
</ul>

<h3>Printing / Saving as PDF</h3>
<p>The report uses a print-friendly layout. Use your browser Print function (Ctrl+P / Cmd+P) and choose <strong>Save as PDF</strong> as the destination to keep a copy or share it.</p>

<h3>Timeframe</h3>
<p>The report respects the timeframe you were viewing on the stats page. To read the fastest, it reuses the cached Days-drilldown data where available and falls back to a live fetch when needed.</p>
',
    ],
    [
        'title'    => 'How to Keep Optee Updated',
        'slug'     => 'updates',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>Optee checks for updates to the core plugin and all your installed integrations automatically, roughly every 5 minutes. You never download zips by hand.</p>

<h3>The Update Badge</h3>
<p>When updates are available, a badge appears in the footer showing the number of updates (for example <strong>3 updates</strong>). Click it to open a popup listing every plugin with an update, along with its changelog for the new version.</p>

<h3>Applying Updates</h3>
<ol>
<li>Click the footer update badge</li>
<li>Review the changelog for each plugin</li>
<li>Click <strong>Update</strong> on a plugin to download and install the new version, or update them one by one</li>
</ol>

<h3>Installing New Integrations</h3>
<p>On any integration page (Traffic Sources, Trackers, etc.), a platform that is available but not yet installed shows an <strong>Install</strong> button on its card. Clicking it downloads and activates the integration for you.</p>

<h3>License and Updates</h3>
<p>Your app and automation keep working even if your license expires - only <strong>plugin updates</strong> are blocked until you renew. Everything downloads over a verified channel and is integrity-checked before it is installed.</p>
',
    ],
    [
        'title'    => 'How to Get Support and Request Features',
        'slug'     => 'support',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>Optee has a built-in support portal for reporting bugs, requesting features, and asking for new integrations. You can also vote on what gets built next.</p>

<h3>The Notification Bell</h3>
<p>The bell icon in the top bar shows updates on your support tickets (a reply, a status change, a resolution). Optee checks for updates every few minutes so you do not have to keep the portal open.</p>

<h3>Submitting a Ticket</h3>
<ol>
<li>Open the support portal from the footer or user menu (you are logged in automatically using your license)</li>
<li>Choose a ticket type:
    <ul>
    <li><strong>Bug</strong> - Something is broken (private)</li>
    <li><strong>Feature request</strong> - An improvement you want (public and voteable)</li>
    <li><strong>Integration request</strong> - A platform you want added (public and voteable)</li>
    <li><strong>Feedback</strong> - General thoughts (private)</li>
    </ul>
</li>
<li>Describe the issue or idea and submit</li>
</ol>

<h3>Voting</h3>
<p>Feature and integration requests are public. Vote on the ones you want prioritised - one vote per item - so the most-wanted work rises to the top. This directly influences the roadmap.</p>

<h3>Ticket Status</h3>
<p>Tickets move through <strong>Open</strong>, <strong>In Progress</strong>, <strong>Resolved</strong>, and <strong>Closed</strong>. You are notified via the bell as the status changes.</p>
',
    ],
    [
        'title'    => 'Bonuses and Promo Codes',
        'slug'     => 'bonuses-promo-codes',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>Optee partners with many of the traffic sources, trackers, and networks it integrates with to bring you exclusive sign-up bonuses and promo codes.</p>

<h3>Bonuses Page</h3>
<p>The <strong>Bonuses</strong> page (in the sidebar) lists partner platforms that offer a deal for Optee users - things like deposit matches, discounts, or free trial extensions. Each card explains the offer and how to claim it, usually by signing up through the provided link or entering a code at registration.</p>

<h3>Promo Codes Page</h3>
<p>The <strong>Promo Codes</strong> page appears once you have an active campaign. It surfaces time-sensitive promo codes and lets you request codes where applicable. Keep an eye on it - offers change over time.</p>

<h3>Tips</h3>
<ul>
<li>Many bonuses must be claimed <strong>at sign-up</strong>, so grab the code before you create your account on the platform</li>
<li>Read each card carefully - some codes are entered on the platform, others apply automatically via the link</li>
</ul>
',
    ],
    [
        'title'    => 'How to Use Insights',
        'slug'     => 'insights',
        'category' => 'How to use Optee',
        'pinned'   => true,
        'content'  => '
<p>Insights helps you discover top-performing offers across your connected affiliate networks. It shows network-wide performance data (not your own stats) so you can spot what is converting well right now and find your next campaign.</p>

<h3>Requirements</h3>
<ul>
<li>The affiliate network must have an Optee integration <strong>installed and active</strong></li>
<li>You must have entered valid <strong>API credentials</strong> for that network</li>
</ul>
<p>Only networks that support Insights and are configured will show up as tabs. If a networks plugin is disabled, its tab disappears.</p>

<h3>Reading the Data</h3>
<ol>
<li>Go to <strong>Insights</strong> in the sidebar</li>
<li>Switch between network tabs at the top</li>
<li>Each row is an offer with its network-wide performance figures; a status dot shows whether the offer is currently active, paused, or capped where the network exposes that</li>
</ol>

<h3>How It Stays Fresh</h3>
<p>Insights data is gathered by the cron job in the background, so the page loads instantly from stored data. Each tab shows when it was last refreshed. Use <strong>Refresh</strong> to pull the latest for a network on demand.</p>
',
    ],

    // ── External articles ─────────────────────────────────────

    // Guides
    [
        'title'    => 'How to Start with Adsterra Traffic Source [Promo code included]',
        'url'      => 'https://afflift.com/f/threads/%F0%9F%94%B4-how-to-start-with-adsterra-traffic-source-promo-code-included.14558/?r=571',
        'category' => 'Guides',
        'pinned'   => false,
    ],
    [
        'title'    => 'How to Start with Galaksion Ad Network [Promo code included]',
        'url'      => 'https://afflift.com/f/threads/%F0%9F%94%B5-how-to-start-with-galaksion-ad-network-promo-code-included.14118/?r=571',
        'category' => 'Guides',
        'pinned'   => false,
    ],
    // Optimization Tools
    [
        'title'    => 'Use Skro to Optimize PropellerAds Campaigns',
        'url'      => 'https://afflift.com/f/threads/use-skro-to-optimize-propellerads-campaigns.10269/?r=571',
        'category' => 'Optimization',
        'pinned'   => false,
    ],
    [
        'title'    => 'PropellerAds Optimizer Script (works with any tracker)',
        'url'      => 'https://afflift.com/f/threads/%F0%9F%93%8A-propellerads-optimizer-script-works-with-any-tracker.11813/?r=571',
        'category' => 'Optimization',
        'pinned'   => false,
    ],
    [
        'title'    => 'Free Optimizer Tool - Booster Script (PropellerAds + BeMob)',
        'url'      => 'https://afflift.com/f/threads/free-optimizer-tool-booster-script-%E2%9A%A1-propellerads-bemob.10601/?r=571',
        'category' => 'Optimization',
        'pinned'   => false,
    ],
    [
        'title'    => 'Free Optimizer Tool - Booster Script (PropellerAds + Skro)',
        'url'      => 'https://afflift.com/f/threads/free-optimizer-tool-booster-script-%E2%9A%A1-propellerads-skro.11774/?r=571',
        'category' => 'Optimization',
        'pinned'   => false,
    ],
    [
        'title'    => 'How to Use Google Sheets to Check Monetizer Offer Status and Start/Stop Traffic on PropellerAds',
        'url'      => 'https://afflift.com/f/threads/how-to-use-google-sheets-to-check-monetizer-offer-status-and-start-stop-traffic-on-propellerads.10496/?r=571',
        'category' => 'Optimization',
        'pinned'   => false,
    ],
    // Getting Started
    [
        'title'    => 'Should you sign up as a publisher, advertiser, or affiliate?',
        'url'      => 'https://afflift.com/f/threads/%F0%9F%98%95-should-you-sign-up-as-a-publisher-advertiser-or-affiliate.12525/?r=571',
        'category' => 'Getting Started',
        'pinned'   => false,
    ],
    [
        'title'    => 'Networks to avoid killing your Cashflow as a beginner',
        'url'      => 'https://afflift.com/f/threads/networks-to-avoid-killing-your-cashflow-as-a-beginner.5847/?r=571',
        'category' => 'Getting Started',
        'pinned'   => false,
    ],
    [
        'title'    => 'You are not alone - 22 Typical Affiliate Mistakes',
        'url'      => 'https://afflift.com/f/threads/you-are-not-alone-22-typical-affiliate-mistakes-%F0%9F%93%89%F0%9F%A5%B2.8052/?r=571',
        'category' => 'Getting Started',
        'pinned'   => false,
    ],
    [
        'title'    => 'The Complete Guide to Setting Up Your First Affiliate Campaign',
        'url'      => 'https://afflift.com/f/threads/the-complete-guide-to-setting-up-your-first-ever-affiliate-campaign.2803/?r=571',
        'category' => 'Getting Started',
        'pinned'   => false,
    ],
    [
        'title'    => 'Naming Conventions for Campaigns, Offers, and Landing Pages',
        'url'      => 'https://afflift.com/f/threads/%F0%9F%93%9D-naming-conventions-for-campaigns-offers-and-landing-pages.6045/?r=571',
        'category' => 'Campaign Setup',
        'pinned'   => false,
    ],
    // Traffic Sources
    [
        'title'    => 'Complete List of Push Notification Traffic Sources',
        'url'      => 'https://afflift.com/f/threads/complete-list-of-push-notification-traffic-sources.770/?r=571',
        'category' => 'Traffic Sources',
        'pinned'   => false,
    ],
    [
        'title'    => 'Ultimate Guide to Pop Ads',
        'url'      => 'https://afflift.com/f/threads/ultimate-guide-to-popads-%F0%9F%92%B0.4382/?r=571',
        'category' => 'Traffic Sources',
        'pinned'   => false,
    ],
    [
        'title'    => 'Guide to Launching Pop Campaigns on Adcash',
        'url'      => 'https://afflift.com/f/threads/guide-to-launching-pop-campaigns-on-adcash.8355/?r=571',
        'category' => 'Traffic Sources',
        'pinned'   => false,
    ],
    [
        'title'    => 'You Should Test Direct Click from PropellerAds',
        'url'      => 'https://afflift.com/f/threads/you-should-test-direct-click-from-propellerads.9514/?r=571',
        'category' => 'Traffic Sources',
        'pinned'   => false,
    ],
    [
        'title'    => 'Revenue Share Push Traffic Sources',
        'url'      => 'https://afflift.com/f/threads/revenue-share-push-traffic-sources.2932/?r=571',
        'category' => 'Traffic Sources',
        'pinned'   => false,
    ],
    // Tracking
    [
        'title'    => 'Cloud Trackers Comparison',
        'url'      => 'https://afflift.com/f/threads/cloud-trackers-comparison-%F0%9F%94%8E.10165/?r=571',
        'category' => 'Tracking',
        'pinned'   => false,
    ],
    [
        'title'    => 'Understanding Tracking Links and Postback URLs',
        'url'      => 'https://afflift.com/f/threads/understanding-tracking-links-and-postback-urls.3255/?r=571',
        'category' => 'Tracking',
        'pinned'   => false,
    ],
    [
        'title'    => 'Tracking Conversions and Sources from ProPush',
        'url'      => 'https://afflift.com/f/threads/tracking-conversions-and-sources-from-propush.7464/?r=571',
        'category' => 'Tracking',
        'pinned'   => false,
    ],
    [
        'title'    => 'How to Track ProPush Subscriptions with Custom Conversions',
        'url'      => 'https://afflift.com/f/threads/how-to-track-propush-subscriptions-in-your-tracker-with-custom-conversions.10045/?r=571',
        'category' => 'Tracking',
        'pinned'   => false,
    ],
    // Offers
    [
        'title'    => 'Cap Management Guide',
        'url'      => 'https://afflift.com/f/threads/cap-management-guide.4179/?r=571',
        'category' => 'Offers',
        'pinned'   => false,
    ],
    [
        'title'    => 'Running Trending Campaigns - Monetizer + PropellerAds',
        'url'      => 'https://afflift.com/f/threads/running-trending-campaigns-monetizer-propellerads.5091/?r=571',
        'category' => 'Offers',
        'pinned'   => false,
    ],
    [
        'title'    => 'Find Offers to Promote on PropellerAds',
        'url'      => 'https://afflift.com/f/threads/find-offers-to-promote-on-propellerads.6611/?r=571',
        'category' => 'Offers',
        'pinned'   => false,
    ],
    // Landing Pages
    [
        'title'    => 'Landing Pages 101',
        'url'      => 'https://afflift.com/f/threads/landing-pages-101.8005/?r=571',
        'category' => 'Landing Pages',
        'pinned'   => false,
    ],
    [
        'title'    => 'Free Landing Page Builder from affLIFT',
        'url'      => 'https://afflift.com/f/threads/free-landing-page-builder-from-afflift.1543/?r=571',
        'category' => 'Landing Pages',
        'pinned'   => false,
    ],
    [
        'title'    => 'Mega Post of Useful Landing Page Scripts',
        'url'      => 'https://afflift.com/f/threads/mega-thread-of-useful-landing-page-scripts.2595/?r=571',
        'category' => 'Landing Pages',
        'pinned'   => false,
    ],
    [
        'title'    => 'Mega Post of Anstrex Landing Pages',
        'url'      => 'https://afflift.com/f/threads/%F0%9F%94%A5-mega-post-of-anstrex-landing-pages-%F0%9F%94%A5.6125/?r=571',
        'category' => 'Landing Pages',
        'pinned'   => false,
    ],
    [
        'title'    => 'The Back Button Script Guide',
        'url'      => 'https://afflift.com/f/threads/the-back-button-script-guide.8283/?r=571',
        'category' => 'Landing Pages',
        'pinned'   => false,
    ],
    // Clickloss
    [
        'title'    => 'The Ultimate Guide to Clickloss',
        'url'      => 'https://afflift.com/f/threads/the-ultimate-guide-to-clickloss.3747/?r=571',
        'category' => 'Clickloss',
        'pinned'   => false,
    ],
    [
        'title'    => 'How to Calculate Clickloss Percentage on Zone/Source Level',
        'url'      => 'https://afflift.com/f/threads/how-to-calculate-clickloss-click-loss-percentage-on-zone-source-level.6449/?r=571',
        'category' => 'Clickloss',
        'pinned'   => false,
    ],
    // Bots & Fraud
    [
        'title'    => 'Bots - Gotta Catch Em All',
        'url'      => 'https://afflift.com/f/threads/%F0%9F%A4%96-bots-gotta-catch-em-all.6693/?r=571',
        'category' => 'Bots & Fraud',
        'pinned'   => false,
    ],
    [
        'title'    => 'How to Filter Fake Traffic Bots That Mess Your Tracking',
        'url'      => 'https://afflift.com/f/threads/how-to-filter-fake-traffic-bots-that-mess-your-tracking-statistics.10848/?r=571',
        'category' => 'Bots & Fraud',
        'pinned'   => false,
    ],
    // Hosting & Domains
    [
        'title'    => 'Host Your Landing Pages for Free (with CDN) in 10 Minutes',
        'url'      => 'https://afflift.com/f/threads/host-your-landing-pages-for-free-w-cdn-in-10-minutes.7673/?r=571',
        'category' => 'Hosting & Domains',
        'pinned'   => false,
    ],
    [
        'title'    => 'How to Avoid Getting Flagged by Google and Antiviruses',
        'url'      => 'https://afflift.com/f/threads/how-to-avoid-getting-flagged-by-google-and-antiviruses.7277/?r=571',
        'category' => 'Hosting & Domains',
        'pinned'   => false,
    ],
    [
        'title'    => 'Get an Email Notification When Your Domain Gets Flagged',
        'url'      => 'https://afflift.com/f/threads/get-an-email-notification-when-your-domain-gets-flagged.10447/?r=571',
        'category' => 'Hosting & Domains',
        'pinned'   => false,
    ],
];
