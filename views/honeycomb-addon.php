<?php

declare(strict_types=1);

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Honeycomb\AddonLoader;
use SimpleKuma\Honeycomb\AddonStore;

/** @var mysqli $db */
/** @var \SimpleKuma\Auth\Permission|null $permission */

$slug = trim((string) ($_GET['slug'] ?? $_POST['slug'] ?? ''));
$store = new AddonStore($db);
$loader = new AddonLoader($db, $store);

$errors = [];
$success = '';
$canEdit = ($permission && (
        $permission->hasPermission(Permission::PERM_SETTINGS_EDIT)
        || $permission->hasPermission(Permission::PERM_UPDATE_MANAGE)
    ))
    || (Auth::allowsLegacyNoRolesFallback() && empty($_SESSION['role_ids'] ?? []));

Csrf::ensureToken();

$installedMeta = null;
foreach ($loader->describeInstalled() as $row) {
    if ((string) ($row['slug'] ?? '') === $slug) {
        $installedMeta = $row;
        break;
    }
}

$panel = null;
if ($installedMeta !== null && ($installedMeta['status'] ?? '') === 'enabled' && !empty($installedMeta['valid'])) {
    try {
        foreach ($loader->kernel()->settingsPanels() as $candidate) {
            if ($candidate->addonSlug() === $slug) {
                $panel = $candidate;
                break;
            }
        }
    } catch (Throwable $e) {
        $errors['general'] = 'Could not load addon settings: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate()) {
        $errors['general'] = Csrf::invalidRequestMessage();
    } elseif (!$canEdit) {
        $errors['general'] = 'You do not have permission to manage Honeycomb.';
    } elseif ($panel === null) {
        $errors['general'] = 'This addon has no options panel, or it is not enabled.';
    } else {
        $handled = $panel->handlePost($_POST);
        if ($handled === null) {
            $errors['general'] = 'Unknown settings action.';
        } elseif (!empty($handled['ok'])) {
            header(
                'Location: ' . APP_BASE_URL . '/index.php?page=honeycomb-addon&slug='
                . rawurlencode($slug) . '&success=1&msg=' . rawurlencode((string) ($handled['message'] ?? 'Saved.'))
            );
            exit;
        } else {
            $errors['general'] = (string) ($handled['message'] ?? 'Could not save settings.');
        }
    }
}

if (isset($_GET['success'])) {
    $success = (string) ($_GET['msg'] ?? 'Settings saved.');
}

$addonName = (string) ($installedMeta['name'] ?? $slug);
$addonVersion = (string) ($installedMeta['version'] ?? '');
$backUrl = APP_BASE_URL . '/index.php?page=honeycomb';
?>
<link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/honeycomb.css?v=4">

<div class="honeycomb-page">
    <div class="honeycomb-options-nav">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-secondary">← Back to Honeycomb</a>
    </div>

    <header class="honeycomb-options-hero">
        <p class="honeycomb-hero__kicker">Honeycomb addon</p>
        <h1 class="honeycomb-hero__title"><?= htmlspecialchars($addonName) ?></h1>
        <p class="honeycomb-hero__lede">
            Addon-specific options for this install.
            <?php if ($addonVersion !== ''): ?>
                <span class="honeycomb-ver">v<?= htmlspecialchars($addonVersion) ?></span>
            <?php endif; ?>
        </p>
    </header>

    <?php if ($success !== ''): ?>
        <div class="honeycomb-flash honeycomb-flash--ok"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors['general'])): ?>
        <div class="honeycomb-flash honeycomb-flash--err"><?= htmlspecialchars((string) $errors['general']) ?></div>
    <?php endif; ?>

    <?php if ($slug === '' || $installedMeta === null): ?>
        <div class="card honeycomb-card">
            <div class="card-body">
                <p>That addon is not installed on this Kuma.</p>
                <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-primary">Browse Honeycomb</a>
            </div>
        </div>
    <?php elseif (($installedMeta['status'] ?? '') !== 'enabled'): ?>
        <div class="card honeycomb-card">
            <div class="card-body">
                <p>Enable <strong><?= htmlspecialchars($addonName) ?></strong> on the Honeycomb page before editing options.</p>
                <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-primary">Back to Honeycomb</a>
            </div>
        </div>
    <?php elseif ($panel === null): ?>
        <div class="card honeycomb-card">
            <div class="card-header">
                <h2 class="card-title">No options</h2>
            </div>
            <div class="card-body">
                <p class="honeycomb-muted">
                    This addon does not expose a settings panel. Campaign bindings (if any) are configured on each campaign.
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="card honeycomb-card">
            <div class="card-header">
                <h2 class="card-title"><?= htmlspecialchars($panel->title()) ?></h2>
            </div>
            <div class="card-body honeycomb-addon-options">
                <?= $panel->renderHtml() ?>
            </div>
        </div>
    <?php endif; ?>
</div>
