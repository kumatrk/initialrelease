<?php
// Click Lookup Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
use SimpleKuma\Utils\Formatter;

// Use the global database connection if available, otherwise create a new one
$db = $GLOBALS['db'] ?? new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$userTimezone = $GLOBALS['userTimezone'] ?? 'UTC';
$userCurrency = $GLOBALS['userCurrency'] ?? 'USD';

$clickId = $_GET['click_id'] ?? '';
$clickData = null;
$error = null;
$conversions = [];
$isFacebook = false;
$metaAdName = null;
$metaAdsetName = null;

// Check if traffic_source_id column exists in clicks table
$trafficSourceColumnExists = false;
$checkColumn = $db->query("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND COLUMN_NAME = 'traffic_source_id'");
if ($checkColumn) {
    $row = $checkColumn->fetch_assoc();
    $trafficSourceColumnExists = ($row['count'] > 0);
}

// Check if slug_id column exists in clicks table
$slugColumnExists = false;
$checkSlugColumn = $db->query("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND COLUMN_NAME = 'slug_id'");
if ($checkSlugColumn) {
    $row = $checkSlugColumn->fetch_assoc();
    $slugColumnExists = ($row['count'] > 0);
}

if (!empty($clickId)) {
    // Validate click_id format (should be UUID)
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clickId)) {
        // Build query to get all click data
        $trafficSourceSelect = $trafficSourceColumnExists 
            ? "COALESCE(ts.name, cp_ts.name) as traffic_source_name,
               COALESCE(ts.id, cp_ts.id) as traffic_source_id,"
            : "cp_ts.name as traffic_source_name,
               cp_ts.id as traffic_source_id,";
        
        $trafficSourceJoin = $trafficSourceColumnExists
            ? "LEFT JOIN traffic_sources ts ON cl.traffic_source_id = ts.id"
            : "";
        
        $slugSelect = $slugColumnExists
            ? "cs.slug as slug_name,
               cs.slug_label as slug_label"
            : "NULL as slug_name,
               NULL as slug_label";
        
        $slugJoin = $slugColumnExists
            ? "LEFT JOIN campaign_slugs cs ON cl.slug_id = cs.id"
            : "";
        
        $sql = "SELECT 
                    cl.*,
                    cp.name as campaign_name,
                    cp.traffic_source_id as campaign_traffic_source_id,
                    {$trafficSourceSelect}
                    o.name as offer_name,
                    lp.name as landing_page_name,
                    {$slugSelect}
                FROM clicks cl
                INNER JOIN campaigns cp ON cl.campaign_id = cp.id
                LEFT JOIN traffic_sources cp_ts ON cp.traffic_source_id = cp_ts.id
                {$trafficSourceJoin}
                LEFT JOIN offers o ON cl.offer_id = o.id
                LEFT JOIN landing_pages lp ON cl.landing_page_id = lp.id
                {$slugJoin}
                WHERE cl.click_id = ?
                LIMIT 1";
        
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $clickId);
            $stmt->execute();
            $result = $stmt->get_result();
            $clickData = $result->fetch_assoc();
            $stmt->close();
            
            if ($clickData) {
                // Get all conversions for this click
                $convSql = "SELECT * FROM conversions WHERE click_id = ? ORDER BY ts DESC";
                $convStmt = $db->prepare($convSql);
                if ($convStmt) {
                    $convStmt->bind_param('s', $clickId);
                    $convStmt->execute();
                    $convResult = $convStmt->get_result();
                    while ($conv = $convResult->fetch_assoc()) {
                        $conversions[] = $conv;
                    }
                    $convStmt->close();
                }
                
                // Parse extra_json
                if (!empty($clickData['extra_json'])) {
                    $clickData['extra_json_parsed'] = json_decode($clickData['extra_json'], true);
                }

                // Meta ad/adset names (from traffic source tokens when available)
                require_once __DIR__ . '/../src/Facebook/FacebookTrafficSourceIdentifier.php';
                $fbIdentifier = new \SimpleKuma\Facebook\FacebookTrafficSourceIdentifier($db);
                $tsId = (int)($clickData['traffic_source_id'] ?? 0);
                $campTsId = (int)($clickData['campaign_traffic_source_id'] ?? 0);
                $isFacebook = $fbIdentifier->isFacebookSource($tsId) || $fbIdentifier->isFacebookSource($campTsId);

                if ($isFacebook) {
                    $sanitizeMetaName = static function ($value): ?string {
                        if ($value === null) {
                            return null;
                        }
                        $value = trim((string)$value);
                        if ($value === '' || strtolower($value) === 'null') {
                            return null;
                        }
                        if (strpos($value, '{{') !== false || strpos($value, '{ts:') !== false) {
                            return null;
                        }
                        return $value;
                    };

                    $tokens = $clickData['extra_json_parsed']['traffic_source_tokens'] ?? [];
                    $metaAdName = $sanitizeMetaName($clickData['ad_name_value'] ?? null)
                        ?? $sanitizeMetaName($tokens['ad_name'] ?? null);
                    $metaAdsetName = $sanitizeMetaName($clickData['adset_name_value'] ?? null)
                        ?? $sanitizeMetaName($tokens['adset_name'] ?? null);
                }
            } else {
                $error = "Click ID not found in database.";
            }
        } else {
            $error = "Database query error: " . $db->error;
        }
    } else {
        $error = "Invalid click ID format. Expected UUID format (e.g., 12345678-1234-1234-1234-123456789abc).";
    }
}
?>

<div class="page-header">
    <h1 class="page-title">Click Lookup</h1>
    <p class="page-description">Enter a click ID to view complete database information for that click</p>
</div>

<div class="card">
    <div class="card-body">
        <form method="GET" action="" style="margin-bottom: 24px;">
            <input type="hidden" name="page" value="click-lookup">
            <div style="display: flex; gap: 12px; align-items: flex-start; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 200px;">
                    <label for="click_id" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">
                        Click ID (UUID)
                    </label>
                    <input 
                        type="text" 
                        id="click_id" 
                        name="click_id" 
                        value="<?= htmlspecialchars($clickId) ?>" 
                        placeholder="e.g., 12345678-1234-1234-1234-123456789abc"
                        style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; font-family: monospace; box-sizing: border-box;"
                        required
                    >
                </div>
                <button type="submit" class="btn btn-primary" style="padding: 12px 24px; white-space: nowrap; align-self: flex-end; margin-top: 28px;">
                    Lookup Click
                </button>
            </div>
        </form>
        
        <?php if ($error): ?>
            <div style="background: #ffebee; border: 2px solid #c62828; border-radius: 6px; padding: 16px; margin-bottom: 24px;">
                <strong style="color: #c62828;">Error:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($clickData): ?>
            <div style="background: #e8f5e9; border: 2px solid #4caf50; border-radius: 6px; padding: 16px; margin-bottom: 24px;">
                <strong style="color: #2e7d32;">✓ Click found!</strong>
            </div>
            
            <!-- Basic Click Information -->
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-header" style="background: #f5f5f5; padding: 16px; border-bottom: 2px solid #ddd;">
                    <h2 style="margin: 0; font-size: 20px; color: #333;">Basic Click Information</h2>
                </div>
                <div class="card-body" style="padding: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Click ID</strong>
                            <div style="font-family: monospace; font-size: 14px; margin-top: 4px; word-break: break-all;">
                                <?= htmlspecialchars($clickData['click_id']) ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Database ID</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['id']) ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Timestamp (UTC)</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['ts']) ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Timestamp (Your Timezone)</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= Formatter::formatDateTime($clickData['ts'], $userTimezone) ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">IP Address</strong>
                            <div style="font-size: 14px; margin-top: 4px; font-family: monospace;">
                                <?= htmlspecialchars($clickData['ip'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">User Agent</strong>
                            <div style="font-size: 12px; margin-top: 4px; font-family: monospace; word-break: break-all; max-height: 100px; overflow-y: auto;">
                                <?= htmlspecialchars($clickData['ua'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Referrer</strong>
                            <div style="font-size: 12px; margin-top: 4px; word-break: break-all; max-height: 100px; overflow-y: auto;">
                                <?= htmlspecialchars($clickData['referrer'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Landing Page Clicked</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= $clickData['lp_click'] ? '<span style="color: #4caf50; font-weight: 600;">✓ Yes</span>' : '<span style="color: #999;">✗ No</span>' ?>
                            </div>
                        </div>
                        <?php if ($clickData['ts_lp']): ?>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">LP Click Timestamp</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= Formatter::formatDateTime($clickData['ts_lp'], $userTimezone) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Geographic Information -->
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-header" style="background: #f5f5f5; padding: 16px; border-bottom: 2px solid #ddd;">
                    <h2 style="margin: 0; font-size: 20px; color: #333;">Geographic Information</h2>
                </div>
                <div class="card-body" style="padding: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Country</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['country'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Region</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['region'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">City</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['city'] ?? 'N/A') ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Device Information -->
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-header" style="background: #f5f5f5; padding: 16px; border-bottom: 2px solid #ddd;">
                    <h2 style="margin: 0; font-size: 20px; color: #333;">Device Information</h2>
                </div>
                <div class="card-body" style="padding: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Device Type</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['device'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Device Brand</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['device_brand'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Device Model</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['device_model'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Operating System</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['os'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">OS Version</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['os_version'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Browser</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['browser'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Browser Version</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['browser_version'] ?? 'N/A') ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Campaign & Traffic Source Information -->
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-header" style="background: #f5f5f5; padding: 16px; border-bottom: 2px solid #ddd;">
                    <h2 style="margin: 0; font-size: 20px; color: #333;">Campaign & Traffic Source</h2>
                </div>
                <div class="card-body" style="padding: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Campaign ID</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['campaign_id']) ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Campaign Name</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['campaign_name']) ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Traffic Source</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['traffic_source_name'] ?? 'N/A') ?>
                                <?php if ($clickData['traffic_source_id']): ?>
                                    <span style="color: #999; font-size: 12px;">(ID: <?= htmlspecialchars($clickData['traffic_source_id']) ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($isFacebook): ?>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Ad Name</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($metaAdName ?? 'N/A') ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Adset Name</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($metaAdsetName ?? 'N/A') ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($clickData['slug_name']): ?>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Slug</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['slug_name']) ?>
                                <?php if ($clickData['slug_label']): ?>
                                    <span style="color: #999; font-size: 12px;">(<?= htmlspecialchars($clickData['slug_label']) ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Offer</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['offer_name'] ?? 'N/A') ?>
                                <?php if ($clickData['offer_id']): ?>
                                    <span style="color: #999; font-size: 12px;">(ID: <?= htmlspecialchars($clickData['offer_id']) ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Landing Page</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['landing_page_name'] ?? 'N/A') ?>
                                <?php if ($clickData['landing_page_id']): ?>
                                    <span style="color: #999; font-size: 12px;">(ID: <?= htmlspecialchars($clickData['landing_page_id']) ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Cost Information -->
            <?php if ($clickData['cost'] !== null): ?>
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-header" style="background: #f5f5f5; padding: 16px; border-bottom: 2px solid #ddd;">
                    <h2 style="margin: 0; font-size: 20px; color: #333;">Cost Information</h2>
                </div>
                <div class="card-body" style="padding: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Cost</strong>
                            <div style="font-size: 18px; margin-top: 4px; font-weight: 600; color: #333;">
                                <?= Formatter::formatCurrency($clickData['cost'], $clickData['cost_currency'] ?? $userCurrency) ?>
                            </div>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Currency</strong>
                            <div style="font-size: 14px; margin-top: 4px;">
                                <?= htmlspecialchars($clickData['cost_currency'] ?? 'N/A') ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Facebook Approval Check -->
            <?php 
            if ($isFacebook):
                $tokens = $clickData['extra_json_parsed']['traffic_source_tokens'] ?? [];
                $adId = $tokens['ad_id'] ?? null;
                $adsetId = $tokens['adset_id'] ?? null;

                $isApprovalClick = \SimpleKuma\Stats\CampaignStatsExpressions::shouldExcludeClickFromStats(
                    (int)($clickData['traffic_source_id'] ?? $clickData['campaign_traffic_source_id'] ?? 0),
                    $clickData['extra_json_parsed'] ?? null,
                    $clickData['ua'] ?? null
                );
                $hasPersistedFlag = array_key_exists('exclude_from_stats', $clickData);
                $isConvertedReal = $isApprovalClick
                    && !empty($conversions)
                    && $hasPersistedFlag
                    && (int)$clickData['exclude_from_stats'] === 0;
                $isFiltered = $hasPersistedFlag
                    ? (int)$clickData['exclude_from_stats'] === 1
                    : $isApprovalClick;
                $approvalColor = $isFiltered ? '#f57c00' : '#4caf50';
                $approvalBackground = $isFiltered ? '#fff3e0' : '#e8f5e9';
            ?>
            <div class="card" style="margin-bottom: 24px; border: 2px solid <?= $approvalColor ?>;">
                <div class="card-header" style="background: <?= $approvalBackground ?>; padding: 16px; border-bottom: 2px solid <?= $approvalColor ?>;">
                    <h2 style="margin: 0; font-size: 20px; color: #333;">
                        Facebook Approval Team Check
                        <?php if ($isConvertedReal): ?>
                            <span style="color: #2e7d32; margin-left: 12px;">✓ Included as REAL (converted)</span>
                        <?php elseif ($isFiltered): ?>
                            <span style="color: #f57c00; margin-left: 12px;">⚠️ Would be FILTERED</span>
                        <?php else: ?>
                            <span style="color: #4caf50; margin-left: 12px;">✓ Valid Click</span>
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="card-body" style="padding: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Ad ID</strong>
                            <div style="font-size: 14px; margin-top: 4px; font-family: monospace; word-break: break-all;">
                                <?= htmlspecialchars($adId ?? 'NULL') ?>
                            </div>
                            <?php if (empty($adId) || $adId === '' || $adId === 'null'): ?>
                                <div style="color: #f57c00; font-size: 12px; margin-top: 4px;">⚠️ Missing or invalid</div>
                            <?php elseif (is_string($adId) && (strpos($adId, '{{') === 0 || strpos($adId, '{ts:') === 0)): ?>
                                <div style="color: #f57c00; font-size: 12px; margin-top: 4px;">⚠️ Contains template token</div>
                            <?php else: ?>
                                <div style="color: #4caf50; font-size: 12px; margin-top: 4px;">✓ Valid</div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Adset ID</strong>
                            <div style="font-size: 14px; margin-top: 4px; font-family: monospace; word-break: break-all;">
                                <?= htmlspecialchars($adsetId ?? 'NULL') ?>
                            </div>
                            <?php if (empty($adsetId) || $adsetId === '' || $adsetId === 'null'): ?>
                                <div style="color: #f57c00; font-size: 12px; margin-top: 4px;">⚠️ Missing or invalid</div>
                            <?php elseif (is_string($adsetId) && (strpos($adsetId, '{{') === 0 || strpos($adsetId, '{ts:') === 0)): ?>
                                <div style="color: #f57c00; font-size: 12px; margin-top: 4px;">⚠️ Contains template token</div>
                            <?php else: ?>
                                <div style="color: #4caf50; font-size: 12px; margin-top: 4px;">✓ Valid</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Extra JSON Data -->
            <?php if (!empty($clickData['extra_json_parsed'])): ?>
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-header" style="background: #f5f5f5; padding: 16px; border-bottom: 2px solid #ddd;">
                    <h2 style="margin: 0; font-size: 20px; color: #333;">Additional Tracking Data (extra_json)</h2>
                </div>
                <div class="card-body" style="padding: 20px;">
                    <pre style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 6px; padding: 16px; overflow-x: auto; font-size: 12px; line-height: 1.6; max-height: 600px; overflow-y: auto;"><?= htmlspecialchars(json_encode($clickData['extra_json_parsed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Conversions -->
            <?php if (!empty($conversions)): ?>
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-header" style="background: #f5f5f5; padding: 16px; border-bottom: 2px solid #ddd;">
                    <h2 style="margin: 0; font-size: 20px; color: #333;">Conversions (<?= count($conversions) ?>)</h2>
                </div>
                <div class="card-body" style="padding: 20px;">
                    <?php foreach ($conversions as $index => $conv): ?>
                        <div class="card" style="margin-bottom: <?= $index < count($conversions) - 1 ? '16px' : '0' ?>; border: 1px solid #ddd;">
                            <div class="card-body" style="padding: 16px;">
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                                    <div>
                                        <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Conversion ID</strong>
                                        <div style="font-size: 14px; margin-top: 4px;">
                                            <?= htmlspecialchars($conv['id']) ?>
                                        </div>
                                    </div>
                                    <div>
                                        <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Transaction ID</strong>
                                        <div style="font-size: 14px; margin-top: 4px; font-family: monospace;">
                                            <?= htmlspecialchars($conv['txid'] ?? 'N/A') ?>
                                        </div>
                                    </div>
                                    <div>
                                        <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Event ID</strong>
                                        <div style="font-size: 14px; margin-top: 4px; font-family: monospace;">
                                            <?= htmlspecialchars($conv['event_id'] ?? 'N/A') ?>
                                        </div>
                                    </div>
                                    <div>
                                        <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Status</strong>
                                        <div style="font-size: 14px; margin-top: 4px;">
                                            <span style="padding: 4px 8px; border-radius: 4px; background: <?= $conv['status'] === 'approved' ? '#e8f5e9' : ($conv['status'] === 'rejected' ? '#ffebee' : '#fff3e0') ?>; color: <?= $conv['status'] === 'approved' ? '#2e7d32' : ($conv['status'] === 'rejected' ? '#c62828' : '#f57c00') ?>; font-weight: 600;">
                                                <?= htmlspecialchars(ucfirst($conv['status'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div>
                                        <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Value</strong>
                                        <div style="font-size: 14px; margin-top: 4px;">
                                            <?= $conv['value'] !== null ? Formatter::formatCurrency($conv['value'], $conv['currency'] ?? $userCurrency) : 'N/A' ?>
                                        </div>
                                    </div>
                                    <div>
                                        <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Payout</strong>
                                        <div style="font-size: 14px; margin-top: 4px;">
                                            <?= $conv['payout'] !== null ? Formatter::formatCurrency($conv['payout'], $conv['currency'] ?? $userCurrency) : 'N/A' ?>
                                        </div>
                                    </div>
                                    <div>
                                        <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Timestamp</strong>
                                        <div style="font-size: 14px; margin-top: 4px;">
                                            <?= Formatter::formatDateTime($conv['ts'], $userTimezone) ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($conv['source_json'])): 
                                    $sourceJson = json_decode($conv['source_json'], true);
                                ?>
                                    <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #eee;">
                                        <strong style="color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Incoming Postback Data (from offer)</strong>
                                        <pre style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 6px; padding: 12px; margin-top: 8px; overflow-x: auto; font-size: 11px; line-height: 1.5; max-height: 300px; overflow-y: auto;"><?= htmlspecialchars(json_encode($sourceJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                                    </div>
                                <?php endif; ?>
                                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #eee;">
                                    <button type="button" class="btn-view-postback-payload" data-conversion-id="<?= (int)$conv['id'] ?>" style="padding: 8px 16px; background: #2196F3; color: white; border: none; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                        📤 View Outbound Postback Payload
                                    </button>
                                    <div class="postback-payload-container" data-conversion-id="<?= (int)$conv['id'] ?>" style="margin-top: 12px; display: none;"></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-body" style="padding: 20px; text-align: center; color: #999;">
                    No conversions found for this click.
                </div>
            </div>
            <?php endif; ?>
            
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    const apiBase = '<?= htmlspecialchars(rtrim(APP_BASE_URL ?? '', '/')) ?>';
    
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }
    
    document.querySelectorAll('.btn-view-postback-payload').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const conversionId = this.getAttribute('data-conversion-id');
            const container = document.querySelector('.postback-payload-container[data-conversion-id="' + conversionId + '"]');
            if (!container) return;
            
            if (container.dataset.loaded === '1') {
                container.style.display = container.style.display === 'none' ? 'block' : 'none';
                return;
            }
            
            btn.disabled = true;
            btn.textContent = '⏳ Loading...';
            container.style.display = 'block';
            container.innerHTML = '<div style="padding: 16px; color: #666;">Loading postback details...</div>';
            
            fetch(apiBase + '/api-postback-details.php?conversion_id=' + conversionId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    btn.textContent = '📤 View Outbound Postback Payload';
                    container.dataset.loaded = '1';
                    
                    if (data.error) {
                        container.innerHTML = '<div style="padding: 12px; background: #ffebee; border: 1px solid #c62828; border-radius: 4px; color: #c62828;">' + escapeHtml(data.error) + '</div>';
                        return;
                    }
                    
                    var html = '';
                    if (data.postback_logs && data.postback_logs.length > 0) {
                        html += '<div style="background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px; padding: 16px;">';
                        html += '<h4 style="margin: 0 0 12px 0; font-size: 14px; color: #333;">Outbound Postbacks (sent to traffic sources / Facebook CAPI)</h4>';
                        data.postback_logs.forEach(function(log, idx) {
                            var isSkipped = log.postback_type === 'skipped_threshold';
                            var logClass = isSkipped ? '#fff8e1' : (log.success ? '#d4edda' : '#f8d7da');
                            var borderClass = isSkipped ? '#ffe082' : (log.success ? '#c3e6cb' : '#f5c6cb');
                            html += '<div style="margin-bottom: 16px; padding: 12px; background: ' + logClass + '; border: 1px solid ' + borderClass + '; border-radius: 4px;">';
                            var statusLabel = isSkipped ? 'Skipped (below minimum)' : (log.success ? '✓' : '✗');
                            var httpLabel = isSkipped ? '—' : (log.http_code || '—');
                            html += '<div style="font-weight: 600; margin-bottom: 8px;">Postback #' + (idx + 1) + ' &bull; ' + escapeHtml(log.postback_type || 'unknown') + ' &bull; HTTP ' + httpLabel + ' ' + statusLabel + '</div>';
                            if (log.request_url) {
                                html += '<div style="font-size: 11px; color: #666; margin-bottom: 8px; word-break: break-all;"><strong>URL:</strong> ' + escapeHtml(log.request_url) + '</div>';
                            }
                            var payloadToShow = log.request_body || log.reconstructed_request_body;
                            if (payloadToShow) {
                                var isReconstructed = !log.request_body && log.reconstructed_request_body;
                                try {
                                    var requestBody = JSON.parse(payloadToShow);
                                    if (requestBody.access_token) {
                                        requestBody.access_token = (requestBody.access_token || '').substring(0, 10) + '...' + (requestBody.access_token || '').substring((requestBody.access_token || '').length - 5) + ' (masked)';
                                    }
                                    html += '<details style="margin-top: 8px;"><summary style="cursor: pointer; font-weight: 600; color: #0c5460; font-size: 12px;">' + (isReconstructed ? '📤 Reconstructed Request Payload' : '📤 Request Payload') + '</summary>';
                                    if (isReconstructed) {
                                        html += '<div style="margin: 8px 0; padding: 8px; background: #fff3cd; border-radius: 4px; font-size: 11px;">⚠️ Reconstructed from current logic (original was not stored)</div>';
                                    }
                                    html += '<pre style="background: #fff; padding: 12px; border-radius: 4px; font-size: 11px; overflow-x: auto; max-height: 400px; overflow-y: auto; margin: 8px 0 0 0;">' + escapeHtml(JSON.stringify(requestBody, null, 2)) + '</pre></details>';
                                } catch (e) {
                                    html += '<pre style="background: #fff; padding: 12px; font-size: 11px; overflow-x: auto;">' + escapeHtml(payloadToShow) + '</pre>';
                                }
                            }
                            if (log.response_body) {
                                try {
                                    var resp = JSON.parse(log.response_body);
                                    html += '<details style="margin-top: 8px;"><summary style="cursor: pointer; font-weight: 600; color: #0c5460; font-size: 12px;">📥 Response</summary>';
                                    html += '<pre style="background: #fff; padding: 12px; border-radius: 4px; font-size: 11px; overflow-x: auto; margin: 8px 0 0 0;">' + escapeHtml(JSON.stringify(resp, null, 2)) + '</pre>';
                                    if (resp.events_received !== undefined) {
                                        html += '<div style="margin-top: 8px; padding: 8px; background: ' + (resp.events_received > 0 ? '#d4edda' : '#f8d7da') + '; border-radius: 4px;">Events received: ' + resp.events_received + (resp.fbtrace_id ? ' &bull; Trace ID: ' + escapeHtml(resp.fbtrace_id) : '') + '</div>';
                                    }
                                    html += '</details>';
                                } catch (e) {
                                    html += '<details style="margin-top: 8px;"><summary style="cursor: pointer; font-weight: 600; color: #0c5460;">📥 Response (raw)</summary><pre style="font-size: 11px;">' + escapeHtml(log.response_body) + '</pre></details>';
                                }
                            }
                            if (log.error_message) {
                                html += '<div style="margin-top: 8px; padding: 8px; background: #fff5f5; border-radius: 4px; color: #721c24; font-size: 12px;">❌ ' + escapeHtml(log.error_message) + '</div>';
                            }
                            html += '</div>';
                        });
                        html += '</div>';
                    } else {
                        html = '<div style="padding: 12px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">No postback logs found for this conversion.</div>';
                    }
                    container.innerHTML = html;
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.textContent = '📤 View Outbound Postback Payload';
                    container.innerHTML = '<div style="padding: 12px; background: #ffebee; border: 1px solid #c62828; border-radius: 4px; color: #c62828;">Failed to load: ' + escapeHtml(err.message) + '</div>';
                });
        });
    });
})();
</script>

<style>
@media (max-width: 768px) {
    /* Click Lookup form - stack vertically on mobile */
    .card-body form > div[style*="display: flex"] {
        flex-direction: column !important;
        align-items: stretch !important;
    }
    
    .card-body form > div[style*="display: flex"] > div {
        width: 100% !important;
        min-width: 100% !important;
    }
    
    .card-body form > div[style*="display: flex"] button {
        width: 100% !important;
        margin-top: 12px !important;
        align-self: stretch !important;
    }
}
</style>

