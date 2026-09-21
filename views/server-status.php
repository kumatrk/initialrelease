<?php

declare(strict_types=1);

/**
 * Server Status — VPS CPU / RAM / disk monitor + retention shortcuts.
 */

use SimpleKuma\Auth\Csrf;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Auth\SingleAdminMode;
use SimpleKuma\DataRetention\HostResourceHealth;
use SimpleKuma\DataRetention\RetentionLifecycleRunner;
use SimpleKuma\DataRetention\StorageHealth;
use SimpleKuma\Settings\SettingsManager;

$settings = new SettingsManager($db);
$errors = $errors ?? [];
$success = $success ?? '';
$canEdit = ($permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT))
    || SingleAdminMode::isEnabled();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate()) {
        $errors['general'] = Csrf::invalidRequestMessage();
    } elseif (!$canEdit) {
        $errors['general'] = 'You do not have permission to change server status settings.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_host_resource_thresholds') {
            $settings->setMultiple([
                'cpu_warn_percent' => (string) max(0, min(99, (int) ($_POST['cpu_warn_percent'] ?? '85'))),
                'ram_warn_percent' => (string) max(0, min(99, (int) ($_POST['ram_warn_percent'] ?? '90'))),
                'storage_warn_percent' => (string) max(0, min(99, (int) ($_POST['storage_warn_percent'] ?? '90'))),
            ]);
            HostResourceHealth::evaluateAndRecord($settings, null, dirname(__DIR__));
            header('Location: ' . APP_BASE_URL . '/index.php?page=server-status&success=thresholds_saved');
            exit;
        }
        if ($action === 'run_data_retention_now') {
            try {
                @set_time_limit(600);
                $db->query("SET time_zone = '+00:00'");
                $result = RetentionLifecycleRunner::run($db, dirname(__DIR__));
                $_SESSION['server_status_retention_log'] = $result['log'] !== '' ? $result['log'] : 'No output.';
                $flag = $result['ok'] ? 'retention_ok' : 'retention_warn';
                header('Location: ' . APP_BASE_URL . '/index.php?page=server-status&success=' . $flag);
                exit;
            } catch (\Throwable $e) {
                $errors['general'] = 'Retention run failed: ' . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['success'])) {
    $map = [
        'thresholds_saved' => 'Warning thresholds saved.',
        'retention_ok' => 'Archive & retention finished successfully.',
        'retention_warn' => 'Archive & retention finished with warnings — see log below.',
    ];
    $key = (string) $_GET['success'];
    if (isset($map[$key])) {
        $success = $map[$key];
    }
}

$retentionLog = null;
if (isset($_SESSION['server_status_retention_log'])) {
    $retentionLog = (string) $_SESSION['server_status_retention_log'];
    unset($_SESSION['server_status_retention_log']);
}

$snapshot = HostResourceHealth::probe(dirname(__DIR__));
HostResourceHealth::evaluateAndRecord($settings, $snapshot, dirname(__DIR__));
$tableSizes = HostResourceHealth::probeClickTableSizes($db);
$warnings = HostResourceHealth::activeWarnings($settings);

$cpuWarn = (int) $settings->get('cpu_warn_percent', (string) HostResourceHealth::DEFAULT_CPU_WARN_PERCENT);
$ramWarn = (int) $settings->get('ram_warn_percent', (string) HostResourceHealth::DEFAULT_RAM_WARN_PERCENT);
$diskWarn = (int) $settings->get('storage_warn_percent', '90');
$logRetention = (int) $settings->get('log_retention_days', '0');
$archiveAfter = (int) $settings->get('archive_after_days', '365');

$fmt = static function (int $bytes): string {
    return StorageHealth::formatBytes($bytes);
};

$meterClass = static function (?float $pct, int $warnAt): string {
    if ($pct === null) {
        return 'ss-meter ss-meter--na';
    }
    if ($warnAt > 0 && $pct >= $warnAt) {
        return 'ss-meter ss-meter--warn';
    }
    if ($warnAt > 0 && $pct >= max(1, $warnAt - 15)) {
        return 'ss-meter ss-meter--elevated';
    }
    return 'ss-meter ss-meter--ok';
};

$diskPct = isset($snapshot['disk']['used_percent']) ? (float) $snapshot['disk']['used_percent'] : null;
$cpuPct = isset($snapshot['cpu']['pressure_percent']) ? (float) $snapshot['cpu']['pressure_percent'] : null;
$ramPct = isset($snapshot['ram']['used_percent']) ? (float) $snapshot['ram']['used_percent'] : null;

$totalClickBytes = 0;
foreach ($tableSizes as $row) {
    $totalClickBytes += (int) $row['bytes'];
}
?>
<link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/server-status.css?v=2">

<div class="server-status-page">
    <div class="page-header">
        <h1 class="page-title">Server Status</h1>
        <p class="page-description">
            Watch VPS CPU, RAM, and disk while traffic is high. When storage grows, archive or purge old raw clicks —
            campaign report summaries stay available.
        </p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="ss-flash ss-flash--ok"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors['general'])): ?>
        <div class="ss-flash ss-flash--err"><?= htmlspecialchars((string) $errors['general']) ?></div>
    <?php endif; ?>

    <?php if ($warnings !== []): ?>
        <div class="ss-alert-list">
            <?php foreach ($warnings as $w): ?>
                <div class="ss-alert ss-alert--<?= htmlspecialchars($w['kind']) ?>">
                    <strong><?= htmlspecialchars($w['label']) ?></strong>
                    <span><?= htmlspecialchars($w['detail']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="ss-grid">
        <div class="card ss-card">
            <div class="card-header"><h2 class="card-title">CPU</h2></div>
            <div class="card-body">
                <?php if ($snapshot['cpu'] === null): ?>
                    <div class="ss-meter ss-meter--idle" aria-hidden="true">
                        <div class="ss-meter__fill"></div>
                    </div>
                    <p class="ss-muted">Not available on this host (<?= htmlspecialchars($snapshot['platform']) ?>).</p>
                <?php else: ?>
                    <?php $cpu = $snapshot['cpu']; ?>
                    <div class="<?= $meterClass($cpuPct, $cpuWarn) ?>">
                        <div class="ss-meter__fill" style="--ss-pct: <?= min(100, max(0, (float) $cpuPct)) ?>%; width: <?= min(100, max(0, (float) $cpuPct)) ?>%"></div>
                    </div>
                    <div class="ss-metric-row">
                        <span class="ss-metric-value"><?= htmlspecialchars((string) $cpu['pressure_percent']) ?>%</span>
                        <span class="ss-muted">load pressure (1-min / cores)</span>
                    </div>
                    <dl class="ss-dl">
                        <div><dt>Load 1 / 5 / 15</dt><dd><?= htmlspecialchars("{$cpu['load_1']} / {$cpu['load_5']} / {$cpu['load_15']}") ?></dd></div>
                        <div><dt>CPU cores</dt><dd><?= (int) $cpu['cores'] ?></dd></div>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ss-card">
            <div class="card-header"><h2 class="card-title">RAM</h2></div>
            <div class="card-body">
                <?php if ($snapshot['ram'] === null): ?>
                    <div class="ss-meter ss-meter--idle" aria-hidden="true">
                        <div class="ss-meter__fill"></div>
                    </div>
                    <p class="ss-muted">Not available on this host (needs Linux <code>/proc/meminfo</code>).</p>
                    <dl class="ss-dl">
                        <div><dt>PHP memory (this request)</dt><dd><?= htmlspecialchars($fmt((int) $snapshot['php']['memory_usage_bytes'])) ?></dd></div>
                        <div><dt>PHP memory_limit</dt><dd><?= htmlspecialchars((string) $snapshot['php']['memory_limit']) ?></dd></div>
                    </dl>
                <?php else: ?>
                    <?php $ram = $snapshot['ram']; ?>
                    <div class="<?= $meterClass($ramPct, $ramWarn) ?>">
                        <div class="ss-meter__fill" style="--ss-pct: <?= min(100, max(0, (float) $ramPct)) ?>%; width: <?= min(100, max(0, (float) $ramPct)) ?>%"></div>
                    </div>
                    <div class="ss-metric-row">
                        <span class="ss-metric-value"><?= htmlspecialchars((string) $ram['used_percent']) ?>%</span>
                        <span class="ss-muted">used</span>
                    </div>
                    <dl class="ss-dl">
                        <div><dt>Used</dt><dd><?= htmlspecialchars($fmt((int) $ram['used_bytes'])) ?></dd></div>
                        <div><dt>Available</dt><dd><?= htmlspecialchars($fmt((int) $ram['available_bytes'])) ?></dd></div>
                        <div><dt>Total</dt><dd><?= htmlspecialchars($fmt((int) $ram['total_bytes'])) ?></dd></div>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ss-card">
            <div class="card-header"><h2 class="card-title">Disk</h2></div>
            <div class="card-body">
                <?php if ($snapshot['disk'] === null): ?>
                    <p class="ss-muted">Disk probe unavailable.</p>
                <?php else: ?>
                    <?php $disk = $snapshot['disk']; ?>
                    <div class="<?= $meterClass($diskPct, $diskWarn) ?>">
                        <div class="ss-meter__fill" style="--ss-pct: <?= min(100, max(0, (float) $diskPct)) ?>%; width: <?= min(100, max(0, (float) $diskPct)) ?>%"></div>
                    </div>
                    <div class="ss-metric-row">
                        <span class="ss-metric-value"><?= htmlspecialchars((string) $disk['used_percent']) ?>%</span>
                        <span class="ss-muted">used</span>
                    </div>
                    <dl class="ss-dl">
                        <div><dt>Free</dt><dd><?= htmlspecialchars($fmt((int) $disk['free_bytes'])) ?></dd></div>
                        <div><dt>Total</dt><dd><?= htmlspecialchars($fmt((int) $disk['total_bytes'])) ?></dd></div>
                        <div><dt>Path</dt><dd class="ss-path"><?= htmlspecialchars((string) $disk['path']) ?></dd></div>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <p class="ss-probed">Last probe: <?= htmlspecialchars((string) $snapshot['probed_at']) ?> UTC · platform <?= htmlspecialchars((string) $snapshot['platform']) ?>
        · <a href="<?= APP_BASE_URL ?>/index.php?page=server-status">Refresh</a>
    </p>

    <?php if ($snapshot['notes'] !== []): ?>
        <ul class="ss-notes">
            <?php foreach ($snapshot['notes'] as $note): ?>
                <li><?= htmlspecialchars($note) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="ss-two-col">
        <div class="card ss-card">
            <div class="card-header"><h2 class="card-title">Click data size</h2></div>
            <div class="card-body">
                <p class="ss-muted" style="margin-top:0;">
                    Approximate MySQL size (data + indexes). Purging raw clicks shrinks hot/archive tables;
                    summary tables stay so KPI reports keep loading.
                </p>
                <?php if ($tableSizes === []): ?>
                    <p class="ss-muted">Could not read information_schema table sizes.</p>
                <?php else: ?>
                    <table class="ss-table">
                        <thead>
                            <tr><th>Table</th><th>Size</th><th>≈ Rows</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tableSizes as $row): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($row['table']) ?></code></td>
                                    <td><?= htmlspecialchars($fmt((int) $row['bytes'])) ?></td>
                                    <td><?= $row['rows'] !== null ? number_format((int) $row['rows']) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr><td>Total</td><td colspan="2"><?= htmlspecialchars($fmt($totalClickBytes)) ?></td></tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ss-card">
            <div class="card-header"><h2 class="card-title">Data retention</h2></div>
            <div class="card-body">
                <dl class="ss-dl">
                    <div>
                        <dt>Log retention</dt>
                        <dd><?= $logRetention > 0 ? htmlspecialchars((string) $logRetention) . ' days' : 'Never delete raw' ?></dd>
                    </div>
                    <div>
                        <dt>Archive after</dt>
                        <dd><?= $archiveAfter > 0 ? htmlspecialchars((string) $archiveAfter) . ' days' : 'Off' ?></dd>
                    </div>
                </dl>
                <p class="ss-muted">
                    Schedule <code>scripts/run-data-retention-cron.php</code> daily.
                    Full controls:
                    <a href="<?= APP_BASE_URL ?>/index.php?page=settings&tab=privacy">Settings → Data Retention</a>.
                </p>
                <?php if ($canEdit): ?>
                    <form method="post" class="ss-inline-form" onsubmit="return confirm('Run archive &amp; retention now? This may take a few minutes on large databases.');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="run_data_retention_now">
                        <button type="submit" class="btn btn-primary">Run archive &amp; retention now</button>
                    </form>
                <?php endif; ?>
                <?php if ($retentionLog !== null && $retentionLog !== ''): ?>
                    <pre class="ss-log"><?= htmlspecialchars($retentionLog) ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card ss-card">
        <div class="card-header"><h2 class="card-title">Warning thresholds</h2></div>
        <div class="card-body">
            <p class="ss-muted" style="margin-top:0;">
                When a metric crosses its threshold, Kuma shows an in-app banner (no email). Set a value to <strong>0</strong> to disable that warning.
            </p>
            <?php if (!$canEdit): ?>
                <p class="ss-muted">You need settings edit permission to change thresholds.</p>
                <dl class="ss-dl">
                    <div><dt>CPU pressure</dt><dd><?= $cpuWarn ?>%</dd></div>
                    <div><dt>RAM used</dt><dd><?= $ramWarn ?>%</dd></div>
                    <div><dt>Disk used</dt><dd><?= $diskWarn ?>%</dd></div>
                </dl>
            <?php else: ?>
                <form method="post" class="ss-thresh-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="save_host_resource_thresholds">
                    <div class="ss-thresh-grid">
                        <label>
                            <span>CPU pressure %</span>
                            <input type="number" name="cpu_warn_percent" min="0" max="99" value="<?= (int) $cpuWarn ?>">
                        </label>
                        <label>
                            <span>RAM used %</span>
                            <input type="number" name="ram_warn_percent" min="0" max="99" value="<?= (int) $ramWarn ?>">
                        </label>
                        <label>
                            <span>Disk used %</span>
                            <input type="number" name="storage_warn_percent" min="0" max="99" value="<?= (int) $diskWarn ?>">
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary">Save thresholds</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
