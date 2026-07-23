<?php
/**
 * Kuma API page content (keys + documentation).
 *
 * Expects: $apiKeys, $apiKeyEntity, $apiBaseUrl, $newApiKeyPlain, $errors, $success, $permission
 */
use SimpleKuma\Api\ApiAgentPrompts;
use SimpleKuma\Api\ApiDocExamples;
use SimpleKuma\Api\RouteRegistry;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Auth\SingleAdminMode;

$canManageKeys = ($permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT))
    || SingleAdminMode::isEnabled();
$apiTableMissing = !$apiKeyEntity->tableExists();
?>
<link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/settings-api.css">

<div class="kuma-api-page">
<?php if (!empty($success)): ?>
<div class="alert alert-success" style="margin-bottom: 20px; padding: 12px 16px; background: #d4edda; color: #155724; border-radius: 6px;">
    <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>

<?php if (!empty($errors['general'])): ?>
<div class="alert alert-error" style="margin-bottom: 20px; padding: 12px 16px; background: #f8d7da; color: #721c24; border-radius: 6px;">
    <?= htmlspecialchars($errors['general']) ?>
</div>
<?php endif; ?>

<div class="card api-page-card" id="api-rest-section">
    <div class="card-header">
        <h2 class="card-title">REST API</h2>
    </div>
    <div class="card-body">
        <p style="color: #666; margin: 0 0 16px 0; font-size: 14px;">
            Use the Kuma API to manage campaigns, offers, and stats from automation tools (Codex, Hermes, scripts).
            Authenticate with a Bearer API key — not your login password.
        </p>
        <div class="api-base-url-box">
            <span class="api-base-url-label">Base URL</span>
            <code id="api-base-url" class="api-code-inline"><?= htmlspecialchars($apiBaseUrl) ?></code>
            <button type="button" class="btn btn-secondary api-copy-btn" data-copy-target="api-base-url">Copy</button>
        </div>
    </div>
</div>

<?php if ($apiTableMissing): ?>
<div class="card api-page-card api-page-card--migration">
    <div class="card-body">
        <strong>Database migration required.</strong>
        Run migration <code>060_create_api_keys_table.sql</code> before creating API keys.
    </div>
</div>
<?php endif; ?>

<div class="card api-page-card" id="api-keys-section">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="card-title" style="margin: 0;">API Keys</h2>
    </div>
    <div class="card-body">
        <?php if ($newApiKeyPlain): ?>
            <div class="api-key-reveal">
                <strong>Copy your new API key now — it will not be shown again.</strong>
                <code id="new-api-key-plain" class="api-code-block"><?= htmlspecialchars($newApiKeyPlain) ?></code>
                <button type="button" class="btn btn-primary api-copy-btn" data-copy-target="new-api-key-plain">Copy key</button>
            </div>
        <?php endif; ?>

        <?php if ($canManageKeys && !$apiTableMissing): ?>
            <form method="POST" action="<?= APP_BASE_URL ?>/index.php?page=kuma-api" style="margin-bottom: 24px; max-width: 480px;">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="create_api_key">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Create new key</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" name="api_key_name" placeholder="e.g. Codex production" required maxlength="100"
                           style="flex: 1; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
                <?php if (isset($errors['api_key_name'])): ?>
                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['api_key_name']) ?></div>
                <?php endif; ?>
            </form>
        <?php elseif (!$canManageKeys): ?>
            <p style="color: #666; font-size: 14px;">You need settings edit permission to manage API keys.</p>
        <?php endif; ?>

        <?php if (empty($apiKeys)): ?>
            <p style="color: #666;">No API keys yet.<?= ($canManageKeys && !$apiTableMissing) ? ' Create one above to get started.' : '' ?></p>
        <?php else: ?>
            <table class="table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Prefix</th>
                        <th>Last used</th>
                        <th>Created</th>
                        <?php if ($canManageKeys): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apiKeys as $key): ?>
                    <tr>
                        <td><?= htmlspecialchars($key['name']) ?></td>
                        <td><code><?= htmlspecialchars($key['key_prefix']) ?>…</code></td>
                        <td><?= $key['last_used_at'] ? htmlspecialchars($key['last_used_at']) : '—' ?></td>
                        <td><?= htmlspecialchars($key['created_at']) ?></td>
                        <?php if ($canManageKeys): ?>
                        <td>
                            <form method="POST" action="<?= APP_BASE_URL ?>/index.php?page=kuma-api" style="display: inline;"
                                  onsubmit="return confirm('Revoke this API key? This cannot be undone.');">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="revoke_api_key">
                                <input type="hidden" name="api_key_id" value="<?= (int)$key['id'] ?>">
                                <button type="submit" class="btn btn-secondary" style="font-size: 12px; padding: 4px 10px;">Revoke</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card api-page-card" id="api-docs-section">
    <div class="card-header">
        <h2 class="card-title">API Documentation</h2>
    </div>
    <div class="card-body api-docs-body">
        <nav class="api-docs-nav">
            <a href="#api-overview">Overview</a>
            <a href="#api-quickstart">Quick start</a>
            <a href="#api-auth">Authentication</a>
            <a href="#api-endpoints">Endpoints</a>
            <a href="#api-examples">Request examples</a>
            <a href="#api-reference">Complete reference</a>
            <a href="#api-rotation">Rotation</a>
            <a href="#api-ai">AI tools</a>
        </nav>

        <section id="api-overview" class="api-doc-section">
            <h3>Overview</h3>
            <p>
                The Kuma REST API (v1) is a JSON HTTP API for managing your tracker outside the web UI.
                Create and update offers, landing pages, and campaigns; retrieve tracking URLs with the correct
                traffic-source tokens; and pull clicks, conversions, and performance stats — all from scripts,
                internal tools, or AI agents like Codex and Hermes.
            </p>
            <p>
                The API reuses the same validation and business logic as the Kuma admin (same entity classes,
                same rotation rules, same cap behavior). What you create via API behaves identically to what you
                create in the browser. It is intentionally focused on <strong>campaign operations and reporting</strong>,
                not a full duplicate of every Settings screen.
            </p>

            <h4>Who this is for</h4>
            <ul class="api-bullet-list">
                <li><strong>AI assistants</strong> — hand an agent your base URL, API key, and OpenAPI spec; it can bootstrap with <code>GET /catalog</code> and build campaigns end-to-end</li>
                <li><strong>Automation scripts</strong> — cron jobs, deployment pipelines, or bulk campaign setup without clicking through the UI</li>
                <li><strong>External dashboards</strong> — pull stats and conversion rows into your own reporting stack</li>
            </ul>

            <h4>Typical workflow</h4>
            <ol class="api-steps-list api-overview-flow">
                <li><strong>Authenticate</strong> — every request uses <code>Authorization: Bearer kuma_...</code> (create a key above)</li>
                <li><strong>Bootstrap</strong> — call <code>GET /catalog</code> to load traffic sources, offers, landing pages, networks, groups, and verified <code>tracking_domains</code> (with IDs) in one response</li>
                <li><strong>Build prerequisites</strong> — create a network and offer (<code>POST /offers</code>) if needed; set caps and schedules on offers here</li>
                <li><strong>Create a campaign</strong> — <code>POST /campaigns</code> with flow type (DTO, LP, or Split), rotation weights, traffic source, and optional slugs</li>
                <li><strong>Get the tracking URL</strong> — returned on create, or fetch anytime via <code>GET /campaigns/{id}/tracking-link</code></li>
                <li><strong>Monitor performance</strong> — <code>GET /stats/campaigns</code>, <code>GET /clicks</code>, and <code>GET /conversions</code> with date range and timezone params</li>
            </ol>

            <h4>What you can do (v1)</h4>
            <ul class="api-bullet-list">
                <li><strong>Networks</strong> — create, list, update, delete affiliate network labels</li>
                <li><strong>Offers</strong> — full CRUD including payout type, URL tokens, click caps, and day/time scheduling</li>
                <li><strong>Landing pages</strong> — full CRUD for LP rotation flows</li>
                <li><strong>Campaigns</strong> — create and update with DTO, LP, or Split rotation; custom tokens; redirect rules; fallback offers; pause/archive via status</li>
                <li><strong>Tracking links</strong> — server-built URLs with traffic-source and campaign token placeholders appended</li>
                <li><strong>Catalog</strong> — single-call reference data for populating AI or script context without multiple round trips</li>
                <li><strong>Stats</strong> — per-campaign summary (clicks, conversions, cost, revenue, profit, ROI) for a date range</li>
                <li><strong>Clicks &amp; conversions</strong> — paginated raw rows for visitor log and conversion reporting</li>
            </ul>

            <h4>Campaign flow types</h4>
            <ul class="api-bullet-list">
                <li><strong>DTO</strong> — direct-to-offer; rotation is a weighted list of offers</li>
                <li><strong>LP</strong> — landing page rotation, then offer rotation on LP click</li>
                <li><strong>Split</strong> — percentage split between LP path and direct-to-offer path</li>
            </ul>
            <p>Enabled weights in each rotation group must sum to <strong>100</strong>. See the <a href="#api-rotation">Rotation</a> section for JSON examples.</p>

            <h4>Not included in v1</h4>
            <p>These remain in the web UI for now — the API does not expose them:</p>
            <ul class="api-bullet-list">
                <li>Traffic source create/edit (read-only via <code>/catalog</code> — complex token and cost config stays in UI)</li>
                <li>Facebook / Google OAuth integrations and ad account linking</li>
                <li>User management, roles, and billing</li>
                <li>Settings changes (domains, GeoIP, data retention, etc.) — except API key management on this page</li>
                <li>Postback firing, installer, or in-app updates</li>
                <li>Full campaign-stats drill-down (token-level charts) — v1 stats are summary + raw click/conversion lists</li>
            </ul>

            <h4>Technical notes</h4>
            <ul class="api-bullet-list">
                <li><strong>Base URL:</strong> <code><?= htmlspecialchars($apiBaseUrl) ?></code> — all paths below are relative to this</li>
                <li><strong>Format:</strong> JSON request bodies on POST/PATCH; JSON responses with <code>{ "data": ... }</code>, paginated <code>meta</code>, or <code>{ "error": ... }</code> — see <a href="#api-reference">Complete reference</a></li>
                <li><strong>Permissions:</strong> API keys inherit the creating user’s role — each endpoint checks the same permissions as the web UI (see endpoint table)</li>
                <li><strong>Rate limit:</strong> 120 requests per minute per API key</li>
                <li><strong>Tracking is unchanged:</strong> clicks still hit <code>/km/{key}</code>; the API does not replace your live tracking endpoints</li>
            </ul>
        </section>

        <section id="api-quickstart" class="api-doc-section">
            <h3>Quick start</h3>
            <p class="api-quickstart-intro">
                Run these steps in order — about five minutes from zero to a live tracking URL.
                Replace <code>kuma_YOUR_KEY_HERE</code> in every curl below with the key you create in step 1.
            </p>

            <div class="api-quickstart-prereqs">
                <strong>Before you run curl</strong>
                <ul class="api-bullet-list api-quickstart-prereq-list">
                    <li>Base URL: <code><?= htmlspecialchars($apiBaseUrl) ?></code></li>
                    <li>Send <code>Content-Type: application/json</code> on POST/PATCH bodies</li>
                    <li>Responses use <code>{ "data": ... }</code> on success or <code>{ "error": ... }</code> on failure — check for <code>error</code> first</li>
                </ul>
            </div>

            <div class="api-quickstart-steps">
                <?php foreach (ApiDocExamples::quickStartSteps() as $i => $step): ?>
                <article class="api-quickstart-step" id="<?= htmlspecialchars($step['id'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="api-quickstart-step-head">
                        <span class="api-quickstart-num"><?= $i + 1 ?></span>
                        <h4 class="api-quickstart-step-title"><?= htmlspecialchars($step['title']) ?></h4>
                    </div>
                    <p class="api-quickstart-step-summary"><?= htmlspecialchars($step['summary']) ?></p>

                    <?php if (!empty($step['action'])): ?>
                    <a class="btn btn-secondary api-quickstart-action" href="<?= htmlspecialchars($step['action']['href'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($step['action']['label']) ?>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($step['curl'])): ?>
                    <pre class="api-code-block" id="ex-<?= htmlspecialchars($step['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($step['curl']) ?></pre>
                    <button type="button" class="btn btn-secondary api-copy-btn" data-copy-from="ex-<?= htmlspecialchars($step['id'], ENT_QUOTES, 'UTF-8') ?>">Copy curl</button>
                    <?php endif; ?>

                    <?php if (!empty($step['expect'])): ?>
                    <div class="api-quickstart-expect">
                        <span class="api-quickstart-expect-label">Success looks like</span>
                        <?php if (!empty($step['expectNote'])): ?>
                        <span class="api-quickstart-expect-note"><?= htmlspecialchars($step['expectNote']) ?></span>
                        <?php endif; ?>
                        <code class="api-quickstart-expect-code"><?= htmlspecialchars($step['expect']) ?></code>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($step['tips'])): ?>
                    <ul class="api-bullet-list api-quickstart-tips">
                        <?php foreach ($step['tips'] as $tip): ?>
                        <li><?= htmlspecialchars($tip) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>

            <div class="api-quickstart-next">
                <h4 class="api-ref-subheading">What’s next?</h4>
                <ul class="api-quickstart-next-links">
                    <li><a href="#api-examples">Request examples</a> — offer caps, LP/Split campaigns, stats filters</li>
                    <li><a href="#api-reference">Complete reference</a> — every field and response variant</li>
                    <li><a href="#api-ai">AI tools</a> — copy-paste prompts for Codex / Hermes</li>
                    <li><a href="<?= htmlspecialchars($apiBaseUrl) ?>/openapi.json" target="_blank" rel="noopener">OpenAPI spec</a> — machine-readable route list</li>
                </ul>
            </div>
        </section>

        <section id="api-auth" class="api-doc-section">
            <h3>Authentication</h3>
            <p>Send your API key on every request (except <code>/health</code> and <code>/openapi.json</code>):</p>
            <pre class="api-code-block">Authorization: Bearer kuma_YOUR_KEY_HERE</pre>
            <p>Keys are shown <strong>once</strong> at creation. Revoke immediately if leaked. Rate limit: 120 requests/minute per key.</p>
            <p>OpenAPI spec: <code id="openapi-url"><?= htmlspecialchars($apiBaseUrl) ?>/openapi.json</code>
                <button type="button" class="btn btn-secondary api-copy-btn" data-copy-target="openapi-url">Copy</button>
            </p>
        </section>

        <section id="api-endpoints" class="api-doc-section">
            <h3>Endpoint reference</h3>
            <table class="table api-endpoint-table">
                <thead>
                    <tr><th>Method</th><th>Path</th><th>Permission</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <?php foreach (RouteRegistry::routes() as $route): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($route['method']) ?></code></td>
                        <td><code><?= htmlspecialchars(str_replace('/api/v1', '', $route['path']) ?: '/') ?></code></td>
                        <td><?= $route['permission'] ? '<span class="api-perm-badge">' . htmlspecialchars($route['permission']) . '</span>' : '—' ?></td>
                        <td><?= htmlspecialchars($route['description']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section id="api-examples" class="api-doc-section">
            <h3>Worked examples</h3>
            <p>Replace <code>kuma_YOUR_KEY_HERE</code> with your key from above. Copy-paste curl for common scenarios — every field and response variant is in <a href="#api-reference">Complete reference</a>.</p>

            <h4>Create offer</h4>
            <p class="api-example-intro">POST <code>/offers</code> — use <code>network_id</code> from <code>GET /catalog</code>.</p>
            <?php
            $tabs = ApiDocExamples::offerExamples();
            $stripId = 'offer-examples';
            $ariaLabel = 'Create offer examples';
            require __DIR__ . '/partials/api-example-tabstrip.php';
            ?>

            <h4>Create campaign</h4>
            <p class="api-example-intro">POST <code>/campaigns</code> — pick ids from <code>GET /catalog</code> (traffic source, offers, landing pages, tracking_domains).</p>
            <?php
            $tabs = ApiDocExamples::campaignExamples();
            $stripId = 'campaign-examples';
            $ariaLabel = 'Create campaign examples';
            require __DIR__ . '/partials/api-example-tabstrip.php';
            ?>

            <h4>Get tracking link</h4>
            <p class="api-example-intro">GET <code>/campaigns/{id}/tracking-link</code> — returns the live URL with tokens.</p>
            <?php
            $tabs = ApiDocExamples::trackingLinkExamples();
            $stripId = 'tracking-examples';
            $ariaLabel = 'Tracking link examples';
            require __DIR__ . '/partials/api-example-tabstrip.php';
            ?>

            <h4>Campaign stats</h4>
            <p class="api-example-intro">GET <code>/stats/campaigns</code> or <code>/stats/campaigns/{id}</code>.</p>
            <?php
            $tabs = ApiDocExamples::statsExamples();
            $stripId = 'stats-examples';
            $ariaLabel = 'Campaign stats examples';
            require __DIR__ . '/partials/api-example-tabstrip.php';
            ?>

            <h4>List conversions</h4>
            <p class="api-example-intro">GET <code>/conversions</code> — paginated conversion rows.</p>
            <?php
            $tabs = ApiDocExamples::conversionsExamples();
            $stripId = 'conversions-examples';
            $ariaLabel = 'Conversions examples';
            require __DIR__ . '/partials/api-example-tabstrip.php';
            ?>

            <h4>List clicks</h4>
            <p class="api-example-intro">GET <code>/clicks</code> — paginated visitor log (requires visitor log permission).</p>
            <?php
            $tabs = ApiDocExamples::clicksExamples();
            $stripId = 'clicks-examples';
            $ariaLabel = 'Clicks examples';
            require __DIR__ . '/partials/api-example-tabstrip.php';
            ?>
        </section>

        <?php require __DIR__ . '/partials/api-complete-reference.php'; ?>

        <section id="api-rotation" class="api-doc-section">
            <h3>Campaign rotation shapes</h3>
            <p>Enabled weights must sum to <strong>100</strong> per rotation group. These are the JSON structures used inside <code>rotation</code> on POST /campaigns. Item fields (<code>type</code>, <code>id</code>, <code>weight</code>, <code>enabled</code>) are in <a href="#api-reference">Complete reference → Campaigns → Response fields</a>.</p>
            <?php
            $tabs = ApiDocExamples::rotationExamples();
            $stripId = 'rotation-examples';
            $ariaLabel = 'Rotation JSON examples';
            $copyLabel = 'Copy JSON';
            require __DIR__ . '/partials/api-example-tabstrip.php';
            unset($copyLabel);
            ?>
        </section>

        <section id="api-ai" class="api-doc-section">
            <h3>Using with AI tools</h3>
            <p>Give your AI agent the base URL, Bearer API key, and OpenAPI spec. Pick a prompt below for the task you want — each includes catalog bootstrap, ID lookup rules, and task-specific JSON shapes.</p>
            <ul class="api-bullet-list">
                <li>Base URL: <code><?= htmlspecialchars($apiBaseUrl) ?></code></li>
                <li>Bearer API key (create one above — shown once at creation)</li>
                <li>OpenAPI: <code><?= htmlspecialchars($apiBaseUrl) ?>/openapi.json</code></li>
            </ul>

            <?php $agentPrompts = ApiAgentPrompts::all($apiBaseUrl); ?>
            <div class="api-prompt-tabs api-tabstrip" data-api-tabstrip="agent-prompts">
                <div class="api-prompt-tablist" role="tablist" aria-label="AI agent prompts">
                    <?php foreach ($agentPrompts as $i => $tab): ?>
                    <button type="button"
                            class="api-prompt-tab<?= $i === 0 ? ' is-active' : '' ?>"
                            role="tab"
                            id="api-prompt-tab-<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"
                            aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                            aria-controls="api-prompt-panel-<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"
                            data-prompt-tab="<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($tab['label']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($agentPrompts as $i => $tab): ?>
                <div class="api-prompt-panel<?= $i === 0 ? ' is-active' : '' ?>"
                     role="tabpanel"
                     id="api-prompt-panel-<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"
                     aria-labelledby="api-prompt-tab-<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"
                     data-prompt-panel="<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"
                     <?= $i === 0 ? '' : 'hidden' ?>>
                    <p class="api-prompt-summary"><?= htmlspecialchars($tab['summary']) ?></p>
                    <pre class="api-prompt-text api-code-block" id="api-prompt-text-<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tab['prompt']) ?></pre>
                    <button type="button" class="btn btn-secondary api-copy-btn" data-copy-from="api-prompt-text-<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>">Copy prompt</button>
                </div>
                <?php endforeach; ?>
            </div>

            <p class="api-prompt-tip muted-tip">Tip: start with <strong>General setup</strong> + <strong>Bootstrap catalog</strong>, then use a create prompt. Replace placeholder IDs with values from your catalog response.</p>
        </section>
    </div>
</div>

</div><!-- .kuma-api-page -->

<script>
document.querySelectorAll('.api-copy-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var text = '';
        if (btn.dataset.copyTarget) {
            var el = document.getElementById(btn.dataset.copyTarget);
            text = el ? (el.textContent || el.innerText) : '';
        } else if (btn.dataset.copyFrom) {
            var pre = document.getElementById(btn.dataset.copyFrom);
            text = pre ? pre.textContent : '';
        }
        if (!text) return;
        navigator.clipboard.writeText(text.trim()).then(function() {
            var orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(function() { btn.textContent = orig; }, 1500);
        });
    });
});

(function() {
    document.querySelectorAll('[data-api-tabstrip]').forEach(function(root) {
        var tablist = root.querySelector('.api-prompt-tablist');
        if (!tablist) return;

        tablist.querySelectorAll('.api-prompt-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                var id = tab.getAttribute('data-prompt-tab');
                tablist.querySelectorAll('.api-prompt-tab').forEach(function(t) {
                    var active = t === tab;
                    t.classList.toggle('is-active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                root.querySelectorAll(':scope > .api-prompt-panel').forEach(function(panel) {
                    var active = panel.getAttribute('data-prompt-panel') === id;
                    panel.classList.toggle('is-active', active);
                    if (active) {
                        panel.removeAttribute('hidden');
                    } else {
                        panel.setAttribute('hidden', '');
                    }
                });
            });
        });
    });
})();
</script>
