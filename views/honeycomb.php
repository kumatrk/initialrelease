<?php

declare(strict_types=1);

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Honeycomb\AddonInstaller;
use SimpleKuma\Honeycomb\AddonLoader;
use SimpleKuma\Honeycomb\AddonStore;
use SimpleKuma\Honeycomb\CatalogClient;
use SimpleKuma\Honeycomb\HoneycombConfig;
use SimpleKuma\Honeycomb\HourlyCostStore;
use SimpleKuma\Settings\SettingsManager;

/** @var mysqli $db */
/** @var \SimpleKuma\Auth\Permission|null $permission */

$settings = new SettingsManager($db);
$store = new AddonStore($db);
$loader = new AddonLoader($db, $store);
$catalog = new CatalogClient($db, $settings);
$installer = new AddonInstaller($db, $settings, $store);

$errors = [];
$success = '';
$canEdit = ($permission && (
        $permission->hasPermission(Permission::PERM_SETTINGS_EDIT)
        || $permission->hasPermission(Permission::PERM_UPDATE_MANAGE)
    ))
    || (Auth::allowsLegacyNoRolesFallback() && empty($_SESSION['role_ids'] ?? []));

Csrf::ensureToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate()) {
        $errors['general'] = Csrf::invalidRequestMessage();
    } elseif (!$canEdit) {
        $errors['general'] = 'You do not have permission to manage Honeycomb.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'refresh_catalog') {
            $catalog->fetch(true);
            header('Location: ' . APP_BASE_URL . '/index.php?page=honeycomb&success=catalog_refreshed');
            exit;
        }
        if ($action === 'install_addon') {
            $slug = trim((string) ($_POST['slug'] ?? ''));
            $result = $catalog->fetch(false);
            $entry = null;
            foreach ($result['addons'] as $addon) {
                if (($addon['slug'] ?? '') === $slug) {
                    $entry = $addon;
                    break;
                }
            }
            if ($entry === null) {
                $errors['general'] = 'That addon is not in the current catalog. Refresh and try again.';
            } else {
                $install = $installer->installFromCatalogEntry($entry);
                if ($install['ok']) {
                    header('Location: ' . APP_BASE_URL . '/index.php?page=honeycomb&success=installed');
                    exit;
                }
                $errors['general'] = $install['message'];
            }
        }
        if ($action === 'enable_addon' || $action === 'disable_addon') {
            $slug = trim((string) ($_POST['slug'] ?? ''));
            $toggle = $installer->setEnabled($slug, $action === 'enable_addon');
            if ($toggle['ok']) {
                header('Location: ' . APP_BASE_URL . '/index.php?page=honeycomb&success=status');
                exit;
            }
            $errors['general'] = $toggle['message'];
        }
        if ($action === 'uninstall_addon') {
            $slug = trim((string) ($_POST['slug'] ?? ''));
            $removed = $installer->uninstall($slug);
            if ($removed['ok']) {
                header('Location: ' . APP_BASE_URL . '/index.php?page=honeycomb&success=removed');
                exit;
            }
            $errors['general'] = $removed['message'];
        }
    }
}

if (isset($_GET['success'])) {
    $success = match ((string) $_GET['success']) {
        'catalog_refreshed' => 'Catalog refreshed from GitHub.',
        'installed' => 'Addon installed.',
        'status' => 'Addon status updated.',
        'removed' => 'Addon removed.',
        default => '',
    };
}

$catalogData = $catalog->fetch(false);
$installed = $loader->describeInstalled();
$installedSlugs = [];
foreach ($installed as $row) {
    $installedSlugs[(string) $row['slug']] = $row;
}

$honeyRepo = HoneycombConfig::DEFAULT_REPO;

$lastCheckRaw = (string) $settings->get(HoneycombConfig::SETTING_LAST_CHECK, '');
$lastCheck = $lastCheckRaw !== '' ? json_decode($lastCheckRaw, true) : null;
$schemaReady = $store->tableExists();
$hourlyReady = (new HourlyCostStore($db))->tableExists();
?>
<link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/honeycomb.css?v=5">

<div class="honeycomb-page">
    <header class="honeycomb-hero">
        <img src="<?= ASSETS_BASE_URL ?>/assets/images/honeycomblarge.png" alt="Honeycomb" class="honeycomb-hero__art">
        <div class="honeycomb-hero__copy">
            <p class="honeycomb-hero__kicker">Honeycomb</p>
            <h1 class="honeycomb-hero__title">Add-ons for Kuma</h1>
            <p class="honeycomb-hero__lede">
                Import Honeycomb addons without replacing Kuma. Browse the catalog, import only what you need,
                and update those addons independently of core.
            </p>
        </div>
    </header>

    <?php if ($success !== ''): ?>
        <div class="honeycomb-flash honeycomb-flash--ok"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors['general'])): ?>
        <div class="honeycomb-flash honeycomb-flash--err"><?= htmlspecialchars((string) $errors['general']) ?></div>
    <?php endif; ?>

    <?php if (!$schemaReady): ?>
        <div class="card honeycomb-card">
            <div class="card-body">
                <strong>Database update required.</strong>
                Open Settings → Updates and click <strong>Update database</strong> so Honeycomb tables can be created
                (migration <code>090_honeycomb_kernel.sql</code>).
            </div>
        </div>
    <?php elseif (!$hourlyReady): ?>
        <div class="card honeycomb-card">
            <div class="card-body">
                <strong>Honeycomb cost tables missing.</strong>
                Run database update for migration <code>092_honeycomb_runtime.sql</code>
                so addon spend can be stored and overlaid on stats.
            </div>
        </div>
    <?php endif; ?>

    <div class="card honeycomb-card">
        <div class="card-header honeycomb-card-header">
            <h2 class="card-title">Available addons</h2>
            <?php if ($canEdit): ?>
                <form method="POST" action="<?= APP_BASE_URL ?>/index.php?page=honeycomb">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="refresh_catalog">
                    <button type="submit" class="btn btn-secondary">Refresh catalog</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <p class="honeycomb-muted">
                Catalog URL:
                <code><?= htmlspecialchars((string) ($catalogData['catalog_url'] ?? '')) ?></code>
                <?php if (!empty($catalogData['from_cache'])): ?>
                    (cached)
                <?php endif; ?>
            </p>
            <?php if (!empty($catalogData['error'])): ?>
                <p class="honeycomb-warn">Could not refresh: <?= htmlspecialchars((string) $catalogData['error']) ?></p>
            <?php endif; ?>
            <?php if (is_array($lastCheck) && !empty($lastCheck['checked_at'])): ?>
                <p class="honeycomb-muted">Last check: <?= htmlspecialchars((string) $lastCheck['checked_at']) ?></p>
            <?php endif; ?>

            <?php if (empty($catalogData['addons'])): ?>
                <div class="honeycomb-empty">
                    <p>No addons in the catalog yet.</p>
                    <p class="honeycomb-muted">When addons are published to
                        <code><?= htmlspecialchars($honeyRepo) ?></code>, they will show up here for import.
                        Local Taboola source lives under <code>honeycomb-addons/</code> until published.</p>
                </div>
            <?php else: ?>
                <div class="honeycomb-catalog-toolbar" id="honeycomb-catalog-toolbar">
                    <label class="honeycomb-catalog-search">
                        <span class="honeycomb-catalog-label">Search</span>
                        <input type="search" id="honeycomb-catalog-q" placeholder="Type a name… e.g. Taboola"
                               autocomplete="off" spellcheck="false">
                    </label>
                    <label class="honeycomb-catalog-type">
                        <span class="honeycomb-catalog-label">Type</span>
                        <select id="honeycomb-catalog-type">
                            <option value="">All types</option>
                            <?php foreach (HoneycombConfig::TYPE_LABELS as $typeKey => $typeLabel): ?>
                                <option value="<?= htmlspecialchars($typeKey) ?>"><?= htmlspecialchars($typeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="honeycomb-muted honeycomb-catalog-count" id="honeycomb-catalog-count" aria-live="polite"></p>
                </div>
                <div class="honeycomb-catalog-table-wrap">
                    <table class="honeycomb-catalog-table" id="honeycomb-catalog-list">
                        <thead>
                            <tr>
                                <th scope="col" class="honeycomb-catalog-col-type">Type</th>
                                <th scope="col">Addon</th>
                                <th scope="col" class="honeycomb-catalog-col-ver">Version</th>
                                <th scope="col" class="honeycomb-catalog-col-action">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                    <?php foreach ($catalogData['addons'] as $addon): ?>
                        <?php
                        $slug = (string) $addon['slug'];
                        $local = $installedSlugs[$slug] ?? null;
                        $addonType = trim((string) ($addon['type'] ?? 'traffic_source'));
                        if ($addonType === '' || !isset(HoneycombConfig::TYPE_LABELS[$addonType])) {
                            $addonType = 'other';
                        }
                        $providerKey = trim((string) ($addon['provider_key'] ?? ''));
                        $searchBlob = strtolower(trim(
                            (string) ($addon['name'] ?? '') . ' ' .
                            $slug . ' ' .
                            $providerKey . ' ' .
                            $addonType . ' ' .
                            HoneycombConfig::typeLabel($addonType) . ' ' .
                            (string) ($addon['summary'] ?? '')
                        ));
                        ?>
                        <tr class="honeycomb-catalog-row"
                            data-slug="<?= htmlspecialchars($slug) ?>"
                            data-type="<?= htmlspecialchars($addonType) ?>"
                            data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') ?>">
                            <td class="honeycomb-catalog-col-type">
                                <span class="honeycomb-type-badge honeycomb-type-badge--<?= htmlspecialchars($addonType) ?>">
                                    <span class="honeycomb-type-badge__icon" aria-hidden="true">
                                        <?php if ($addonType === 'traffic_source'): ?>
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/></svg>
                                        <?php elseif ($addonType === 'utility'): ?>
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18.4V21h2.6l6.7-6.3a4 4 0 0 0 5.4-5.4l-3-3z"/><path d="m13 7 4 4"/></svg>
                                        <?php else: ?>
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/></svg>
                                        <?php endif; ?>
                                    </span>
                                    <span class="honeycomb-type-badge__label"><?= htmlspecialchars(HoneycombConfig::typeLabel($addonType)) ?></span>
                                </span>
                            </td>
                            <td>
                                <div class="honeycomb-catalog-name"><?= htmlspecialchars((string) $addon['name']) ?></div>
                                <?php if (!empty($addon['summary'])): ?>
                                    <div class="honeycomb-catalog-summary"><?= htmlspecialchars((string) $addon['summary']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="honeycomb-catalog-col-ver">
                                <code class="honeycomb-catalog-ver"><?= htmlspecialchars((string) $addon['version']) ?></code>
                            </td>
                            <td class="honeycomb-catalog-col-action">
                                <?php if ($local): ?>
                                    <span class="honeycomb-pill">Installed</span>
                                <?php elseif ($canEdit): ?>
                                    <form method="POST" action="<?= APP_BASE_URL ?>/index.php?page=honeycomb">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="install_addon">
                                        <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                                        <button type="submit" class="btn btn-primary" <?= empty($addon['compatible']) || empty($addon['zip_url']) ? 'disabled' : '' ?>>Import</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="honeycomb-empty honeycomb-catalog-empty" id="honeycomb-catalog-empty" hidden>
                    No addons match your search.
                </p>
                <script>
                (function () {
                    var q = document.getElementById('honeycomb-catalog-q');
                    var typeSel = document.getElementById('honeycomb-catalog-type');
                    var table = document.getElementById('honeycomb-catalog-list');
                    var empty = document.getElementById('honeycomb-catalog-empty');
                    var countEl = document.getElementById('honeycomb-catalog-count');
                    if (!q || !typeSel || !table) return;
                    var items = Array.prototype.slice.call(table.querySelectorAll('.honeycomb-catalog-row'));
                    function applyFilter() {
                        var needle = (q.value || '').trim().toLowerCase();
                        var type = (typeSel.value || '').trim();
                        var shown = 0;
                        items.forEach(function (row) {
                            var hay = row.getAttribute('data-search') || '';
                            var liType = (row.getAttribute('data-type') || '').trim();
                            var okType = !type || liType === type;
                            var okText = !needle || hay.indexOf(needle) !== -1;
                            var show = okType && okText;
                            row.classList.toggle('is-filtered-out', !show);
                            row.hidden = !show;
                            if (show) shown++;
                        });
                        if (empty) empty.hidden = shown > 0;
                        if (countEl) {
                            countEl.textContent = shown + ' of ' + items.length + ' addon' + (items.length === 1 ? '' : 's');
                        }
                    }
                    q.addEventListener('input', applyFilter);
                    typeSel.addEventListener('change', applyFilter);
                    applyFilter();
                })();
                </script>
            <?php endif; ?>
        </div>
    </div>

    <div class="card honeycomb-card">
        <div class="card-header">
            <h2 class="card-title">Installed on this Kuma</h2>
        </div>
        <div class="card-body">
            <?php if ($installed === []): ?>
                <p class="honeycomb-muted">Nothing imported yet. Addons you import are stored in <code>honeycomb/addons/</code> on this server, not in the Kuma zip.</p>
            <?php else: ?>
                <div class="honeycomb-catalog-toolbar" id="honeycomb-installed-toolbar">
                    <label class="honeycomb-catalog-search">
                        <span class="honeycomb-catalog-label">Search</span>
                        <input type="search" id="honeycomb-installed-q" placeholder="Type a name… e.g. Whop"
                               autocomplete="off" spellcheck="false">
                    </label>
                    <label class="honeycomb-catalog-type">
                        <span class="honeycomb-catalog-label">Type</span>
                        <select id="honeycomb-installed-type">
                            <option value="">All types</option>
                            <?php foreach (HoneycombConfig::TYPE_LABELS as $typeKey => $typeLabel): ?>
                                <option value="<?= htmlspecialchars($typeKey) ?>"><?= htmlspecialchars($typeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="honeycomb-catalog-type">
                        <span class="honeycomb-catalog-label">Status</span>
                        <select id="honeycomb-installed-status">
                            <option value="">All statuses</option>
                            <option value="enabled">Enabled</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </label>
                    <p class="honeycomb-muted honeycomb-catalog-count" id="honeycomb-installed-count" aria-live="polite"></p>
                </div>
                <div class="honeycomb-catalog-table-wrap">
                    <table class="honeycomb-catalog-table" id="honeycomb-installed-list">
                        <thead>
                            <tr>
                                <th scope="col" class="honeycomb-catalog-col-type">Type</th>
                                <th scope="col">Addon</th>
                                <th scope="col" class="honeycomb-catalog-col-ver">Version</th>
                                <th scope="col" class="honeycomb-catalog-col-status">Status</th>
                                <th scope="col" class="honeycomb-catalog-col-action">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                    <?php foreach ($installed as $row): ?>
                        <?php
                        $slug = (string) ($row['slug'] ?? '');
                        $addonType = trim((string) ($row['type'] ?? 'other'));
                        if ($addonType === '' || !isset(HoneycombConfig::TYPE_LABELS[$addonType])) {
                            $addonType = 'other';
                        }
                        $status = (string) ($row['status'] ?? 'disabled');
                        $providerKey = trim((string) ($row['provider_key'] ?? ''));
                        $searchBlob = strtolower(trim(
                            (string) ($row['name'] ?? '') . ' ' .
                            $slug . ' ' .
                            $providerKey . ' ' .
                            $addonType . ' ' .
                            HoneycombConfig::typeLabel($addonType) . ' ' .
                            $status . ' ' .
                            (string) ($row['error'] ?? '')
                        ));
                        ?>
                        <tr class="honeycomb-catalog-row"
                            data-slug="<?= htmlspecialchars($slug) ?>"
                            data-type="<?= htmlspecialchars($addonType) ?>"
                            data-status="<?= htmlspecialchars($status) ?>"
                            data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') ?>">
                            <td class="honeycomb-catalog-col-type">
                                <span class="honeycomb-type-badge honeycomb-type-badge--<?= htmlspecialchars($addonType) ?>">
                                    <span class="honeycomb-type-badge__icon" aria-hidden="true">
                                        <?php if ($addonType === 'traffic_source'): ?>
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/></svg>
                                        <?php elseif ($addonType === 'utility'): ?>
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18.4V21h2.6l6.7-6.3a4 4 0 0 0 5.4-5.4l-3-3z"/><path d="m13 7 4 4"/></svg>
                                        <?php else: ?>
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/></svg>
                                        <?php endif; ?>
                                    </span>
                                    <span class="honeycomb-type-badge__label"><?= htmlspecialchars(HoneycombConfig::typeLabel($addonType)) ?></span>
                                </span>
                            </td>
                            <td>
                                <div class="honeycomb-catalog-name"><?= htmlspecialchars((string) ($row['name'] ?? $slug)) ?></div>
                                <?php if (!empty($row['error'])): ?>
                                    <div class="honeycomb-catalog-summary honeycomb-warn"><?= htmlspecialchars((string) $row['error']) ?></div>
                                <?php elseif ($providerKey !== ''): ?>
                                    <div class="honeycomb-catalog-summary">Provider <code><?= htmlspecialchars($providerKey) ?></code></div>
                                <?php endif; ?>
                            </td>
                            <td class="honeycomb-catalog-col-ver">
                                <code class="honeycomb-catalog-ver"><?= htmlspecialchars((string) ($row['version'] ?? '')) ?></code>
                            </td>
                            <td class="honeycomb-catalog-col-status">
                                <span class="honeycomb-pill honeycomb-pill--<?= htmlspecialchars($status) ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </td>
                            <td class="honeycomb-catalog-col-action">
                                <?php if ($canEdit): ?>
                                    <div class="honeycomb-row-actions">
                                        <?php if (!empty($row['valid']) && $status === 'enabled'): ?>
                                            <form method="GET" action="<?= APP_BASE_URL ?>/index.php">
                                                <input type="hidden" name="page" value="honeycomb-addon">
                                                <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                                                <button type="submit" class="honeycomb-row-btn">Options</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($status === 'enabled'): ?>
                                            <form method="POST">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="action" value="disable_addon">
                                                <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                                                <button type="submit" class="honeycomb-row-btn">Disable</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="action" value="enable_addon">
                                                <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                                                <button type="submit" class="honeycomb-row-btn honeycomb-row-btn--primary">Enable</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="return confirm('Remove this addon from this install? Tracking stays in place.');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="uninstall_addon">
                                            <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                                            <button type="submit" class="honeycomb-row-btn honeycomb-row-btn--danger">Remove</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="honeycomb-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="honeycomb-empty honeycomb-catalog-empty" id="honeycomb-installed-empty" hidden>
                    No installed addons match your filters.
                </p>
                <script>
                (function () {
                    var q = document.getElementById('honeycomb-installed-q');
                    var typeSel = document.getElementById('honeycomb-installed-type');
                    var statusSel = document.getElementById('honeycomb-installed-status');
                    var table = document.getElementById('honeycomb-installed-list');
                    var empty = document.getElementById('honeycomb-installed-empty');
                    var countEl = document.getElementById('honeycomb-installed-count');
                    if (!q || !typeSel || !statusSel || !table) return;
                    var items = Array.prototype.slice.call(table.querySelectorAll('.honeycomb-catalog-row'));
                    function applyFilter() {
                        var needle = (q.value || '').trim().toLowerCase();
                        var type = (typeSel.value || '').trim();
                        var status = (statusSel.value || '').trim();
                        var shown = 0;
                        items.forEach(function (row) {
                            var hay = row.getAttribute('data-search') || '';
                            var liType = (row.getAttribute('data-type') || '').trim();
                            var liStatus = (row.getAttribute('data-status') || '').trim();
                            var okType = !type || liType === type;
                            var okStatus = !status || liStatus === status;
                            var okText = !needle || hay.indexOf(needle) !== -1;
                            var show = okType && okStatus && okText;
                            row.classList.toggle('is-filtered-out', !show);
                            row.hidden = !show;
                            if (show) shown++;
                        });
                        if (empty) empty.hidden = shown > 0;
                        if (countEl) {
                            countEl.textContent = shown + ' of ' + items.length + ' installed';
                        }
                    }
                    q.addEventListener('input', applyFilter);
                    typeSel.addEventListener('change', applyFilter);
                    statusSel.addEventListener('change', applyFilter);
                    applyFilter();
                })();
                </script>
            <?php endif; ?>
        </div>
    </div>

    <div class="card honeycomb-card">
        <div class="card-header">
            <h2 class="card-title">Keep ad spend up to date</h2>
        </div>
        <div class="card-body">
            <p class="honeycomb-muted">
                Traffic addons (and Facebook / Google cost sync) need a small scheduled job on your server.
                Once an hour it asks each connected platform for the latest spend and stores it in Kuma,
                so campaign stats stay accurate without you refreshing anything by hand.
            </p>
            <ol class="honeycomb-cron-steps">
                <li>Open your host’s cron / scheduled tasks panel (cPanel, Plesk, SSH crontab, etc.).</li>
                <li>Add <strong>one</strong> job that runs every hour, using the command below.</li>
                <li>That’s it — the same job covers Facebook cost, Google Ads cost, and Honeycomb traffic addons.</li>
            </ol>
            <p class="honeycomb-catalog-label" style="margin: 14px 0 6px;">Recommended hourly command</p>
            <pre class="honeycomb-cron">0 * * * * php <?= htmlspecialchars(str_replace('\\', '/', dirname(__DIR__) . '/scripts/kuma-traffic-api-cron.php')) ?></pre>
            <p class="honeycomb-muted" style="margin-top: 14px;">
                Already have separate Facebook or Google cron lines? You can leave them — Kuma won’t pull the same hour twice.
                Prefer one line for everything? Use the command above and you can remove the old ones when you’re ready.
            </p>
            <details class="honeycomb-cron-details">
                <summary>Alternative: Honeycomb-only job</summary>
                <p class="honeycomb-muted">
                    If your host blocks running other scripts from this job, keep your existing Facebook / Google schedules
                    and add this instead for Honeycomb addons only:
                </p>
                <pre class="honeycomb-cron">0 * * * * php <?= htmlspecialchars(str_replace('\\', '/', dirname(__DIR__) . '/scripts/honeycomb-cron.php')) ?></pre>
            </details>
        </div>
    </div>
</div>
