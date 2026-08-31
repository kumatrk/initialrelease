<?php
// Offers CRUD Page
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Auth\Permission;

// Mobile detection function
function isMobileDevice(): bool {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ua = strtolower($userAgent);
    
    $mobilePatterns = [
        'mobile', 'android', 'iphone', 'ipod', 'ipad', 'blackberry',
        'windows phone', 'opera mini', 'palm', 'smartphone', 'tablet'
    ];
    
    foreach ($mobilePatterns as $pattern) {
        if (strpos($ua, $pattern) !== false) {
            return true;
        }
    }
    return false;
}
$isMobile = isMobileDevice();

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$offer = new \SimpleKuma\Entity\Offer($db);
$network = new \SimpleKuma\Entity\Network($db);

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$errors = [];
$success = '';
$permission = $GLOBALS['permission'] ?? null;
$canManage = ($permission && $permission->hasPermission(Permission::PERM_OFFER_MANAGE))
    || (Auth::allowsLegacyNoRolesFallback() && empty($_SESSION['role_ids'] ?? []));

// Block add/edit screens when user cannot manage
if (!$canManage && in_array($action, ['add', 'edit'], true)) {
    $errors['general'] = 'You do not have permission to modify this resource.';
    $action = 'list';
}


// Handle form submissions
Csrf::ensureToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate()) {
        $errors['general'] = Csrf::invalidRequestMessage();
    } elseif (!$canManage) {
        $errors['general'] = 'You do not have permission to manage offers';
        $action = 'list';
    } elseif ($action === 'delete') {
        $blockReason = $offer->getDeleteBlockReason((int)$id);
        if ($blockReason !== null) {
            $errors['general'] = $blockReason;
            $action = 'list';
        } elseif ($offer->delete($id)) {
            $success = 'Offer deleted successfully';
            $action = 'list';
        } else {
            $errors['general'] = 'Failed to delete offer';
        }
    } else {
        $data = [
            'name' => $_POST['name'] ?? '',
            'url' => $_POST['url'] ?? '',
            'payout_type' => $_POST['payout_type'] ?? 'CPA',
            'payout_value' => isset($_POST['payout_value']) ? (float)$_POST['payout_value'] : 0,
            'network_id' => !empty($_POST['network_id']) ? (int)$_POST['network_id'] : null,
            'tags' => !empty($_POST['tags']) ? trim((string)$_POST['tags']) : null,
            'notes' => $_POST['notes'] ?? '',
            'is_24_7' => isset($_POST['is_24_7']) ? (int)$_POST['is_24_7'] : 1,
            'schedule_days' => isset($_POST['schedule_days']) && is_array($_POST['schedule_days']) ? $_POST['schedule_days'] : [],
            'schedule_start_time' => $_POST['schedule_start_time'] ?? null,
            'schedule_end_time' => $_POST['schedule_end_time'] ?? null,
            'schedule_timezone' => $_POST['schedule_timezone'] ?? 'UTC',
            'cap_enabled' => isset($_POST['cap_enabled']) ? (int)$_POST['cap_enabled'] : 0,
            'cap_limit' => !empty($_POST['cap_limit']) ? (int)$_POST['cap_limit'] : null,
            'cap_period' => $_POST['cap_period'] ?? null,
            'cap_timezone' => $_POST['cap_timezone'] ?? 'UTC',
        ];

        $errors = $offer->validate($data);

        if (empty($errors)) {
            if ($action === 'edit' && $id) {
                if ($offer->update($id, $data)) {
                    $success = 'Offer updated successfully';
                    $action = 'list';
                } else {
                    $errors['general'] = 'Failed to update offer';
                }
            } else {
                if ($offer->create($data)) {
                    $success = 'Offer created successfully';
                    $action = 'list';
                } else {
                    $errors['general'] = 'Failed to create offer';
                }
            }
        }
    }
}

$editOffer = null;
if ($action === 'edit' && $id) {
    $editOffer = $offer->getById($id);
}

$networks = $network->getAll();

// Load traffic sources for token selector (only if add/edit)
$allTrafficSourcesForTokens = [];
if ($action === 'add' || $action === 'edit') {
    try {
        $trafficSourceEntity = new \SimpleKuma\Entity\TrafficSource($db);
        $allTrafficSourcesForTokens = $trafficSourceEntity->getAll();
    } catch (Exception $e) {
        error_log('Error loading traffic sources for token context: ' . $e->getMessage());
        $allTrafficSourcesForTokens = [];
    }
}

$db->close();
?>

<div class="offers-page">
<div class="page-header">
    <h1 class="page-title">Offers</h1>
    <p class="page-description">Manage your affiliate offers and track payouts.</p>
</div>

<?php if ($success): ?>
<div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #28a745;">
    ✅ <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>

<?php if (!empty($errors['general'])): ?>
<div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #d32f2f;">
    ⚠️ <?= htmlspecialchars($errors['general']) ?>
</div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
    <!-- List View -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Your Offers</h2>
            <?php if ($canManage): ?>
            <a href="?page=offers&action=add" class="btn btn-primary">+ Add Offer</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php
            $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            $offer = new \SimpleKuma\Entity\Offer($db);
            $offers = $offer->getAll();

            $rotationRef = new \SimpleKuma\Campaign\CampaignRotationReference($db);
            $campaignsByOffer = $rotationRef->getCampaignsByOfferMap();
            $campaignCounts = [];
            foreach ($campaignsByOffer as $offerId => $campaigns) {
                $campaignCounts[$offerId] = count($campaigns);
            }

            $db->close();
            ?>

            <?php if (empty($offers)): ?>
                <div style="text-align: center; padding: 60px; color: #999;">
                    <img src="<?= ASSETS_BASE_URL ?>/assets/images/offers.png" alt="Offers" style="width: 64px; height: 64px; margin-bottom: 16px;">
                    <p>No offers yet. Add your first affiliate offer to get started!</p>
                    <a href="?page=offers&action=add" class="btn btn-primary" style="margin-top: 20px;">+ Add Offer</a>
                </div>
            <?php else: ?>
                <!-- Search and Filter Bar -->
                <div style="margin-bottom: 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <div style="position: relative; flex: 1; min-width: 260px; max-width: 450px;">
                        <input type="text" id="offerSearchInput" placeholder="🔍 Search offers by name, tag, network, URL..." 
                               oninput="filterOffersTable()"
                               style="width: 100%; padding: 9px 14px; border: 2px solid #ddd; border-radius: 6px; font-size: 13px; background: #fff;">
                    </div>
                    <span id="offerSearchCount" style="font-size: 13px; color: #666; font-weight: 500;"></span>
                </div>

                <!-- Desktop Table View (hidden on mobile) -->
                <div class="table-wrapper desktop-only">
                    <table class="table" id="offers-table">
                        <thead>
                            <tr>
                                <th>Offer Name</th>
                                <th>Network</th>
                                <th>Payout Type</th>
                                <th>Payout Value</th>
                                <th>URL</th>
                                <th style="text-align: center;">Campaigns</th>
                                <th>Created</th>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($offers as $off): ?>
                            <?php 
                                $campaignCount = $campaignCounts[$off['id']] ?? 0; 
                                $searchData = strtolower($off['name'] . ' ' . ($off['network_name'] ?? '') . ' ' . ($off['tags'] ?? '') . ' ' . $off['url']);
                            ?>
                            <tr class="offer-row" data-search="<?= htmlspecialchars($searchData, ENT_QUOTES) ?>">
                                <td>
                                    <strong><?= htmlspecialchars($off['name']) ?></strong>
                                    <?php if (!empty($off['tags'])): ?>
                                        <div style="margin-top: 3px; display: flex; flex-wrap: wrap; gap: 4px;">
                                            <?php foreach (explode(',', (string)$off['tags']) as $t): $t = trim($t); if ($t === '') continue; ?>
                                                <span style="display: inline-block; font-size: 10px; background: #e0f2fe; color: #0369a1; padding: 1px 5px; border-radius: 3px; font-weight: 500;">🏷️ <?= htmlspecialchars($t) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($off['network_name']): ?>
                                        <span class="badge badge-info"><?= htmlspecialchars($off['network_name']) ?></span>
                                    <?php else: ?>
                                        <span style="color: #999;">No network</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $off['payout_type'] === 'CPA' ? 'success' : 'info' ?>">
                                        <?= htmlspecialchars($off['payout_type']) ?>
                                    </span>
                                </td>
                                <td style="font-weight: 600; color: #28a745;">
                                    $<?= number_format($off['payout_value'], 2) ?>
                                </td>
                                <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; font-family: monospace;" title="<?= htmlspecialchars($off['url']) ?>">
                                    <?= htmlspecialchars($off['url']) ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($campaignCount > 0): ?>
                                        <span class="badge badge-info" 
                                              style="cursor: pointer; transition: all 0.2s;"
                                              onclick="showCampaignsModal(<?= $off['id'] ?>, '<?= htmlspecialchars($off['name'], ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($campaignsByOffer[$off['id']] ?? []), ENT_QUOTES) ?>)"
                                              onmouseover="this.style.opacity='0.8'; this.style.transform='scale(1.05)'"
                                              onmouseout="this.style.opacity='1'; this.style.transform='scale(1)'"
                                              title="Click to view campaigns">
                                            <?= $campaignCount ?> <?= $campaignCount === 1 ? 'campaign' : 'campaigns' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-info" style="opacity: 0.5;">0 campaigns</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('M d, Y', strtotime($off['created_at'])) ?></td>
                                <td>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <!-- Edit Button -->
                                        <a href="?page=offers&action=edit&id=<?= $off['id'] ?>" 
                                           style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none;"
                                           title="Edit Offer"
                                           onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                           onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                            ✏️
                                        </a>
                                        
                                        <!-- Delete Button -->
                                        <?php if ($campaignCount > 0): ?>
                                            <button type="button"
                                                    disabled
                                                    style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #f5f5f5; cursor: not-allowed; display: flex; align-items: center; justify-content: center; color: #bbb; opacity: 0.7;"
                                                    title="Used by <?= $campaignCount ?> <?= $campaignCount === 1 ? 'campaign' : 'campaigns' ?>. Remove from campaigns first.">
                                                🗑️
                                            </button>
                                        <?php else: ?>
                                        <form method="post" action="?page=offers&action=delete&id=<?= $off['id'] ?>" 
                                              style="display: inline; margin: 0;" 
                                              onsubmit="return confirm('Are you sure you want to delete this offer?\\n\\nThis cannot be undone.');">
                                            <?= Csrf::field() ?>
                                            <button type="submit" 
                                                    style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666;"
                                                    title="Delete Offer"
                                                    onmouseover="this.style.background='#ffebee'; this.style.borderColor='#f44336'; this.style.color='#f44336';"
                                                    onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                                🗑️
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Mobile Card View (hidden on desktop) -->
                <div class="mobile-offer-cards mobile-only">
                    <?php foreach ($offers as $off): ?>
                        <?php 
                            $campaignCount = $campaignCounts[$off['id']] ?? 0; 
                            $searchData = strtolower($off['name'] . ' ' . ($off['network_name'] ?? '') . ' ' . ($off['tags'] ?? '') . ' ' . $off['url']);
                        ?>
                        <div class="mobile-offer-card" data-search="<?= htmlspecialchars($searchData, ENT_QUOTES) ?>" style="background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); padding: var(--spacing-md); margin-bottom: var(--spacing-md); box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                            <!-- Header: Name -->
                            <div style="margin-bottom: var(--spacing-sm); border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: var(--spacing-xs);">
                                <div style="font-weight: 600; font-size: 16px; color: #3d5a26;">
                                    <?= htmlspecialchars($off['name']) ?>
                                </div>
                                <?php if (!empty($off['tags'])): ?>
                                    <div style="margin-top: 3px; display: flex; flex-wrap: wrap; gap: 4px;">
                                        <?php foreach (explode(',', (string)$off['tags']) as $t): $t = trim($t); if ($t === '') continue; ?>
                                            <span style="display: inline-block; font-size: 10px; background: #e0f2fe; color: #0369a1; padding: 1px 5px; border-radius: 3px; font-weight: 500;">🏷️ <?= htmlspecialchars($t) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div style="font-size: 11px; color: #666; margin-top: 4px;">
                                    Created: <?= date('M d, Y', strtotime($off['created_at'])) ?>
                                </div>
                            </div>
                            
                            <!-- Info: Network, Payout Type, Payout Value -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-sm); margin-bottom: var(--spacing-sm);">
                                <div>
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Network</strong></div>
                                    <div style="font-size: 12px;">
                                        <?php if ($off['network_name']): ?>
                                            <span class="badge badge-info" style="font-size: 10px;"><?= htmlspecialchars($off['network_name']) ?></span>
                                        <?php else: ?>
                                            <span style="color: #999; font-size: 11px;">No network</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div>
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Payout Type</strong></div>
                                    <div style="font-size: 12px;">
                                        <span class="badge badge-<?= $off['payout_type'] === 'CPA' ? 'success' : 'info' ?>" style="font-size: 10px;">
                                            <?= htmlspecialchars($off['payout_type']) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="margin-bottom: var(--spacing-sm);">
                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Payout Value</strong></div>
                                <div style="font-weight: 600; font-size: 18px; color: #28a745;">
                                    $<?= number_format($off['payout_value'], 2) ?>
                                </div>
                            </div>
                            
                            <div style="margin-bottom: var(--spacing-sm);">
                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>URL</strong></div>
                                <div style="font-size: 10px; font-family: monospace; color: #666; word-break: break-all; background: #f9f9f9; padding: 6px; border-radius: 3px;" title="<?= htmlspecialchars($off['url']) ?>">
                                    <?= htmlspecialchars($off['url']) ?>
                                </div>
                            </div>
                            
                            <div style="margin-bottom: var(--spacing-sm);">
                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Campaigns</strong></div>
                                <div style="font-size: 13px;">
                                    <?php if ($campaignCount > 0): ?>
                                        <span class="badge badge-info" 
                                              style="cursor: pointer; transition: all 0.2s; font-size: 11px;"
                                              onclick="showCampaignsModal(<?= $off['id'] ?>, '<?= htmlspecialchars($off['name'], ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($campaignsByOffer[$off['id']] ?? []), ENT_QUOTES) ?>)"
                                              title="Click to view campaigns">
                                            <?= $campaignCount ?> <?= $campaignCount === 1 ? 'campaign' : 'campaigns' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-info" style="opacity: 0.5; font-size: 11px;">0 campaigns</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Actions -->
                            <div style="display: flex; gap: 8px; border-top: 1px solid rgba(0,0,0,0.1); padding-top: var(--spacing-sm);">
                                <a href="?page=offers&action=edit&id=<?= $off['id'] ?>" 
                                   style="flex: 1; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; text-decoration: none; color: #666; text-align: center; display: inline-block;">
                                    ✏️ Edit
                                </a>
                                <?php if ($campaignCount > 0): ?>
                                    <button type="button"
                                            disabled
                                            style="flex: 1; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #f5f5f5; cursor: not-allowed; color: #bbb; opacity: 0.7;"
                                            title="Used by <?= $campaignCount ?> <?= $campaignCount === 1 ? 'campaign' : 'campaigns' ?>. Remove from campaigns first.">
                                        🗑️ Delete
                                    </button>
                                <?php else: ?>
                                <form method="post" action="?page=offers&action=delete&id=<?= $off['id'] ?>" 
                                      style="flex: 1; margin: 0;" 
                                      onsubmit="return confirm('Are you sure you want to delete this offer?\\n\\nThis cannot be undone.');">
                                    <?= Csrf::field() ?>
                                    <button type="submit" 
                                            style="width: 100%; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; color: #666;">
                                        🗑️ Delete
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
    <!-- Add/Edit Form -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?= $action === 'edit' ? 'Edit' : 'Add' ?> Offer</h2>
            <a href="?page=offers" class="btn btn-secondary">← Back to List</a>
        </div>
        <div class="card-body">
            <form method="post" action="?page=offers&action=<?= $action ?><?= $id ? "&id={$id}" : '' ?>">
                <?= Csrf::field() ?>
                <!-- Offer Name -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                        Offer Name <span style="color: #d32f2f;">*</span>
                    </label>
                    <input type="text" name="name" 
                           value="<?= htmlspecialchars($editOffer['name'] ?? $_POST['name'] ?? '') ?>" 
                           required 
                           placeholder="e.g., Health Supplement - Trial Offer"
                           style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                    <div style="font-size: 12px; color: #666; margin-top: 4px;">A descriptive name for this offer</div>
                    <?php if (isset($errors['name'])): ?>
                        <div style="color: #d32f2f; font-size: 14px; margin-top: 4px;"><?= htmlspecialchars($errors['name']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Offer Tags -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                        Tags
                    </label>
                    <input type="text" name="tags" 
                           value="<?= htmlspecialchars($editOffer['tags'] ?? $_POST['tags'] ?? '') ?>" 
                           placeholder="e.g., sweepstakes, us, direct (comma-separated)"
                           style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                    <div style="font-size: 12px; color: #666; margin-top: 4px;">Comma-separated tags for filtering and organizing offers</div>
                </div>

                <!-- Offer URL -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                        Offer URL <span style="color: #d32f2f;">*</span>
                    </label>
                    <textarea name="url" 
                              id="offer_url_textarea"
                              rows="3"
                              required 
                              placeholder="https://example.com/offer?aff=123&click_id={click_id}&campaign={campaign_id}"
                              style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 13px;"><?= htmlspecialchars($editOffer['url'] ?? $_POST['url'] ?? '') ?></textarea>
                    <div style="font-size: 12px; color: #666; margin-top: 8px; margin-bottom: 12px;">
                        The destination URL where users will be sent. You can add tokens like {click_id}, {campaign_id}, etc. Click any token below to insert it at your cursor position.
                    </div>
                    
                    <!-- Traffic Source Selector for Custom Token Labels -->
                    <div class="token-selector-box">
                        <label>
                            Select Traffic Source for Custom Token Labels (Optional):
                        </label>
                        <select id="traffic_source_selector_for_tokens" 
                                onchange="updateTrafficSourceTokenLabels()"
                                style="width: 100%; padding: 6px; border: 1px solid #66bb6a; border-radius: 3px; font-size: 12px;">
                            <option value="">-- None selected (show generic ts_token1-ts_token20) --</option>
                            <?php
                            foreach ($allTrafficSourcesForTokens as $ts):
                                $tsTokens = is_array($ts['tokens_json'] ?? null) 
                                    ? $ts['tokens_json'] 
                                    : (json_decode($ts['tokens_json'] ?? '[]', true) ?: []);
                                if (!empty($tsTokens)):
                            ?>
                                <option value="<?= $ts['id'] ?>" 
                                        data-tokens="<?= htmlspecialchars(json_encode($tsTokens, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
                                        data-ts-name="<?= htmlspecialchars($ts['name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($ts['name']) ?>
                                </option>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </select>
                        <div class="token-selector-hint">
                            Select a traffic source to see traffic source custom tokens (ts_token1-ts_token20) with their labels.
                        </div>
                    </div>
                    
                    <style>
                    .token-container-box {
                        background: #f8fafc;
                        padding: 14px;
                        border-radius: 6px;
                        border: 1.5px solid #e2e8f0;
                    }
                    .token-heading--builtin, .token-heading--custom {
                        color: #166534;
                        font-size: 12px;
                        display: block;
                        margin-bottom: 8px;
                        font-weight: 700;
                    }
                    .token-heading--campaign {
                        color: #1e40af;
                        font-size: 11px;
                        display: block;
                        margin-bottom: 6px;
                        font-weight: 700;
                    }
                    .token-heading--traffic-source {
                        color: #7e22ce;
                        font-size: 11px;
                        display: block;
                        margin-bottom: 6px;
                        font-weight: 700;
                    }
                    .custom-token-btn {
                        display: inline-flex !important;
                        align-items: center !important;
                        justify-content: center !important;
                        padding: 5px 11px !important;
                        border-radius: 5px !important;
                        cursor: pointer !important;
                        font-size: 11px !important;
                        font-weight: 600 !important;
                        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace !important;
                        line-height: 1.3 !important;
                        border-width: 1.5px !important;
                        border-style: solid !important;
                        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08) !important;
                        transition: all 0.15s ease-in-out !important;
                        user-select: none !important;
                        text-decoration: none !important;
                        outline: none !important;
                    }
                    .custom-token-btn:active {
                        transform: translateY(1px) scale(0.98) !important;
                    }
                    .custom-token-btn.token-btn--builtin, .custom-token-btn {
                        background: #f0fdf4 !important;
                        border-color: #86efac !important;
                        color: #166534 !important;
                    }
                    .custom-token-btn.token-btn--builtin:hover, .custom-token-btn:hover {
                        background: #166534 !important;
                        border-color: #166534 !important;
                        color: #ffffff !important;
                        transform: translateY(-1px) !important;
                        box-shadow: 0 3px 6px rgba(22, 101, 52, 0.25) !important;
                    }
                    .custom-token-btn.token-btn--campaign {
                        background: #eff6ff !important;
                        border-color: #93c5fd !important;
                        color: #1e40af !important;
                    }
                    .custom-token-btn.token-btn--campaign:hover {
                        background: #1e40af !important;
                        border-color: #1e40af !important;
                        color: #ffffff !important;
                        transform: translateY(-1px) !important;
                        box-shadow: 0 3px 6px rgba(30, 64, 175, 0.25) !important;
                    }
                    .custom-token-btn.token-btn--traffic-source {
                        background: #faf5ff !important;
                        border-color: #d8b4fe !important;
                        color: #6b21a8 !important;
                    }
                    .custom-token-btn.token-btn--traffic-source:hover {
                        background: #7e22ce !important;
                        border-color: #7e22ce !important;
                        color: #ffffff !important;
                        transform: translateY(-1px) !important;
                        box-shadow: 0 3px 6px rgba(126, 34, 206, 0.25) !important;
                    }
                    [data-theme-base="dark"] .token-container-box, [data-theme="dark"] .token-container-box {
                        background: #181d24 !important;
                        border-color: #2d333b !important;
                    }
                    [data-theme-base="dark"] .token-selector-box, [data-theme="dark"] .token-selector-box {
                        background: #132417 !important;
                        border-color: #244b2c !important;
                    }
                    [data-theme-base="dark"] .token-selector-box label, [data-theme="dark"] .token-selector-box label {
                        color: #86efac !important;
                    }
                    [data-theme-base="dark"] .token-selector-box .token-selector-hint, [data-theme="dark"] .token-selector-box .token-selector-hint {
                        color: #94a3b8 !important;
                    }
                    [data-theme-base="dark"] .token-heading--builtin, [data-theme-base="dark"] .token-heading--custom, [data-theme="dark"] .token-heading--builtin, [data-theme="dark"] .token-heading--custom {
                        color: #86efac !important;
                    }
                    [data-theme-base="dark"] .token-heading--campaign, [data-theme="dark"] .token-heading--campaign {
                        color: #93c5fd !important;
                    }
                    [data-theme-base="dark"] .token-heading--traffic-source, [data-theme="dark"] .token-heading--traffic-source {
                        color: #d8b4fe !important;
                    }
                    [data-theme-base="dark"] .custom-token-btn.token-btn--builtin, [data-theme-base="dark"] .custom-token-btn, [data-theme="dark"] .custom-token-btn.token-btn--builtin, [data-theme="dark"] .custom-token-btn {
                        background: #142e1b !important;
                        border-color: #22c55e !important;
                        color: #86efac !important;
                        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3) !important;
                    }
                    [data-theme-base="dark"] .custom-token-btn.token-btn--builtin:hover, [data-theme-base="dark"] .custom-token-btn:hover, [data-theme-base="dark"] .custom-token-btn.token-btn--builtin:hover, [data-theme-base="dark"] .custom-token-btn:hover {
                        background: #22c55e !important;
                        border-color: #86efac !important;
                        color: #052e16 !important;
                        transform: translateY(-1px) !important;
                        box-shadow: 0 3px 8px rgba(34, 197, 94, 0.4) !important;
                    }
                    [data-theme-base="dark"] .custom-token-btn.token-btn--campaign, [data-theme="dark"] .custom-token-btn.token-btn--campaign {
                        background: #172554 !important;
                        border-color: #3b82f6 !important;
                        color: #93c5fd !important;
                        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3) !important;
                    }
                    [data-theme-base="dark"] .custom-token-btn.token-btn--campaign:hover, [data-theme="dark"] .custom-token-btn.token-btn--campaign:hover {
                        background: #3b82f6 !important;
                        border-color: #93c5fd !important;
                        color: #0f172a !important;
                        transform: translateY(-1px) !important;
                        box-shadow: 0 3px 8px rgba(59, 130, 246, 0.4) !important;
                    }
                    [data-theme-base="dark"] .custom-token-btn.token-btn--traffic-source, [data-theme="dark"] .custom-token-btn.token-btn--traffic-source {
                        background: #2e1047 !important;
                        border-color: #a855f7 !important;
                        color: #e9d5ff !important;
                        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3) !important;
                    }
                    [data-theme-base="dark"] .custom-token-btn.token-btn--traffic-source:hover, [data-theme="dark"] .custom-token-btn.token-btn--traffic-source:hover {
                        background: #a855f7 !important;
                        border-color: #f3e8ff !important;
                        color: #1e0533 !important;
                        transform: translateY(-1px) !important;
                        box-shadow: 0 3px 8px rgba(168, 85, 247, 0.5) !important;
                    }
                    </style>
                    
                    <div class="token-container-box">
                        <?php
                        // Use ClickTokenReplacer for available tokens (click-time tokens)
                        try {
                            $clickTokenReplacer = new \SimpleKuma\Tracking\ClickTokenReplacer();
                            $availableTokens = $clickTokenReplacer->getAvailableTokens();
                        } catch (Exception $e) {
                            error_log('Error loading ClickTokenReplacer: ' . $e->getMessage());
                            $availableTokens = ['Built-in Tokens' => [], 'Custom Tokens' => []];
                        }
                        ?>
                        
                        <!-- Built-in Tokens -->
                        <?php foreach ($availableTokens as $category => $tokens): ?>
                            <?php if ($category === 'Built-in Tokens'): ?>
                                <div style="margin-bottom: 12px;">
                                    <strong class="token-heading--builtin"><?= htmlspecialchars($category) ?>:</strong>
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                        <?php foreach ($tokens as $token => $description): ?>
                                            <button type="button" 
                                                    onclick="insertTokenAtCursor('<?= htmlspecialchars($token) ?>')"
                                                    class="custom-token-btn token-btn--builtin"
                                                    data-token="<?= htmlspecialchars($token) ?>"
                                                    title="<?= htmlspecialchars($description) ?>">
                                                        <?= htmlspecialchars($token) ?>
                                                    </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        
                        <!-- Traffic Source Custom Tokens -->
                        <div id="traffic_source_tokens_display" style="margin-bottom: 0;">
                            <div id="traffic_source_tokens_header" style="margin-bottom: 6px;">
                                <strong class="token-heading--custom">Traffic Source Tokens:</strong>
                            </div>
                            <!-- Traffic Source Custom Tokens (will be updated by JavaScript) -->
                            <div id="traffic_source_tokens_section" style="margin-bottom: 8px; display: none;">
                                <strong class="token-heading--traffic-source">Selected Traffic Source Tokens:</strong>
                                <div id="traffic_source_tokens_buttons" style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px;"></div>
                            </div>
                            <!-- Generic fallback -->
                            <div id="traffic_source_tokens_buttons_generic" style="display: flex; flex-wrap: wrap; gap: 6px;">
                                <!-- Generic tokens (default) -->
                                <?php
                                for ($i = 1; $i <= 20; $i++):
                                    $token = "{ts_token{$i}}";
                                ?>
                                    <button type="button" 
                                            onclick="insertTokenAtCursor('<?= htmlspecialchars($token) ?>')"
                                            class="custom-token-btn token-btn--builtin"
                                            data-token="<?= htmlspecialchars($token) ?>"
                                            title="Traffic source token <?= $i ?>">
                                        <?= htmlspecialchars($token) ?>
                                    </button>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (isset($errors['url'])): ?>
                        <div style="color: #d32f2f; font-size: 14px; margin-top: 8px;"><?= htmlspecialchars($errors['url']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Payout Details -->
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 24px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                            Payout Type <span style="color: #d32f2f;">*</span>
                        </label>
                        <select name="payout_type" required
                                style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            <option value="CPA" <?= ($editOffer['payout_type'] ?? 'CPA') === 'CPA' ? 'selected' : '' ?>>
                                CPA (Cost Per Action)
                            </option>
                            <option value="CPL" <?= ($editOffer['payout_type'] ?? '') === 'CPL' ? 'selected' : '' ?>>
                                CPL (Cost Per Lead)
                            </option>
                            <option value="CPS" <?= ($editOffer['payout_type'] ?? '') === 'CPS' ? 'selected' : '' ?>>
                                CPS (Cost Per Sale)
                            </option>
                        </select>
                        <?php if (isset($errors['payout_type'])): ?>
                            <div style="color: #d32f2f; font-size: 14px; margin-top: 4px;"><?= htmlspecialchars($errors['payout_type']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                            Payout Value <span style="color: #d32f2f;">*</span>
                        </label>
                        <input type="number" name="payout_value" 
                               value="<?= htmlspecialchars($editOffer['payout_value'] ?? $_POST['payout_value'] ?? '0.00') ?>" 
                               step="0.01" 
                               min="0" 
                               required
                               placeholder="25.00"
                               style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                        <div style="font-size: 12px; color: #666; margin-top: 4px;">Commission amount in USD</div>
                        <?php if (isset($errors['payout_value'])): ?>
                            <div style="color: #d32f2f; font-size: 14px; margin-top: 4px;"><?= htmlspecialchars($errors['payout_value']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                            Network (Optional)
                        </label>
                        <select name="network_id"
                                style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            <option value="">No network</option>
                            <?php foreach ($networks as $net): ?>
                                <option value="<?= $net['id'] ?>" <?= ($editOffer['network_id'] ?? 0) == $net['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($net['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size: 12px; color: #666; margin-top: 4px;">The affiliate network providing this offer</div>
                    </div>
                </div>

                <!-- Offer Scheduling -->
                <div style="margin-bottom: 24px; background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%); border: 2px solid #ff9800; border-radius: 8px; padding: 36px;">
                    <h3 style="margin: 0 0 16px 0; color: #e65100; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                        <span style="font-size: 20px;">⏰</span>
                        Offer Availability Schedule
                    </h3>
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; color: #333;">
                            <input type="radio" name="is_24_7" value="1" 
                                   <?= ($editOffer['is_24_7'] ?? 1) == 1 ? 'checked' : '' ?>
                                   onchange="toggleScheduleFields(false)"
                                   style="width: 18px; height: 18px; cursor: pointer;">
                            <span>Available 24/7 (Always Active)</span>
                        </label>
                    </div>
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; color: #333;">
                            <input type="radio" name="is_24_7" value="0" 
                                   <?= ($editOffer['is_24_7'] ?? 1) == 0 ? 'checked' : '' ?>
                                   onchange="toggleScheduleFields(true)"
                                   style="width: 18px; height: 18px; cursor: pointer;">
                            <span>Only Available During Specific Hours</span>
                        </label>
                    </div>
                    
                    <?php 
                    $is247 = ($editOffer['is_24_7'] ?? 1) == 1;
                    $scheduleDays = $editOffer['schedule_days'] ?? [];
                    if (!is_array($scheduleDays)) {
                        $scheduleDays = [];
                    }
                    ?>
                    
                    <div id="schedule_fields" style="display: <?= $is247 ? 'none' : 'block' ?>; margin-top: 20px; padding-top: 20px; border-top: 2px solid #ffb74d;">
                        <!-- Days Selection -->
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                Available Days <span style="color: #d32f2f;">*</span>
                            </label>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php 
                                $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                                foreach ($days as $day): 
                                    $checked = in_array($day, $scheduleDays, true) ? 'checked' : '';
                                ?>
                                    <label style="display: flex; align-items: center; gap: 6px; padding: 8px 12px; background: #fff; border: 2px solid #ddd; border-radius: 6px; cursor: pointer; transition: all 0.2s;"
                                           onmouseover="this.style.borderColor='#ff9800'; this.style.background='#fff3e0';"
                                           onmouseout="this.style.borderColor='#ddd'; this.style.background='#fff';">
                                        <input type="checkbox" name="schedule_days[]" value="<?= $day ?>" <?= $checked ?>
                                               style="width: 16px; height: 16px; cursor: pointer;">
                                        <span style="font-size: 14px; text-transform: capitalize;"><?= ucfirst($day) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Select the days when this offer should be available for rotation</div>
                        </div>
                        
                        <!-- Time Range -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                    Start Time <span style="color: #d32f2f;">*</span>
                                </label>
                                <input type="time" name="schedule_start_time" 
                                       value="<?= htmlspecialchars($editOffer['schedule_start_time'] ?? '09:00') ?>"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            </div>
                            
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                    End Time <span style="color: #d32f2f;">*</span>
                                </label>
                                <input type="time" name="schedule_end_time" 
                                       value="<?= htmlspecialchars($editOffer['schedule_end_time'] ?? '17:00') ?>"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            </div>
                            
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                    Timezone <span style="color: #d32f2f;">*</span>
                                </label>
                                <select name="schedule_timezone"
                                        style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                    <optgroup label="US Timezones">
                                        <option value="America/New_York" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'America/New_York' ? 'selected' : '' ?>>Eastern Time (ET)</option>
                                        <option value="America/Chicago" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'America/Chicago' ? 'selected' : '' ?>>Central Time (CT)</option>
                                        <option value="America/Denver" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'America/Denver' ? 'selected' : '' ?>>Mountain Time (MT)</option>
                                        <option value="America/Los_Angeles" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'America/Los_Angeles' ? 'selected' : '' ?>>Pacific Time (PT)</option>
                                    </optgroup>
                                    <optgroup label="Europe">
                                        <option value="Europe/London" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'Europe/London' ? 'selected' : '' ?>>London (GMT/BST)</option>
                                        <option value="Europe/Paris" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'Europe/Paris' ? 'selected' : '' ?>>Paris (CET)</option>
                                        <option value="Europe/Berlin" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'Europe/Berlin' ? 'selected' : '' ?>>Berlin (CET)</option>
                                        <option value="Europe/Moscow" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'Europe/Moscow' ? 'selected' : '' ?>>Moscow (MSK)</option>
                                    </optgroup>
                                    <optgroup label="Asia & Pacific">
                                        <option value="Asia/Dubai" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'Asia/Dubai' ? 'selected' : '' ?>>Dubai (GST)</option>
                                        <option value="Asia/Singapore" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'Asia/Singapore' ? 'selected' : '' ?>>Singapore (SGT)</option>
                                        <option value="Asia/Tokyo" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'Asia/Tokyo' ? 'selected' : '' ?>>Tokyo (JST)</option>
                                        <option value="Australia/Sydney" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'Australia/Sydney' ? 'selected' : '' ?>>Sydney (AEDT)</option>
                                    </optgroup>
                                    <optgroup label="Other">
                                        <option value="UTC" <?= ($editOffer['schedule_timezone'] ?? 'UTC') === 'UTC' ? 'selected' : '' ?>>UTC (Universal)</option>
                                    </optgroup>
                                </select>
                            </div>
                        </div>
                        
                        <div style="background: #fff; padding: 12px; border-radius: 6px; border-left: 4px solid #ff9800;">
                            <p style="margin: 0; font-size: 13px; color: #e65100; line-height: 1.6;">
                                <strong>💡 How it works:</strong> When this offer is set to time-restricted, it will be <strong>completely excluded</strong> from rotation during times outside the selected schedule. Only offers that are currently available (24/7 or within their schedule window) will be rotated.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Offer CAP Settings -->
                <div style="margin-bottom: 24px; background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border: 2px solid #2196F3; border-radius: 8px; padding: 36px;">
                    <h3 style="margin: 0 0 16px 0; color: #1565c0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                        <span style="font-size: 20px;">📊</span>
                        Offer CAP (Lead Limit)
                    </h3>
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; color: #333;">
                            <input type="checkbox" name="cap_enabled" value="1" 
                                   <?= ($editOffer['cap_enabled'] ?? 0) == 1 ? 'checked' : '' ?>
                                   onchange="toggleCapFields(this.checked)"
                                   style="width: 18px; height: 18px; cursor: pointer;">
                            <span>Enable CAP Tracking (Optional)</span>
                        </label>
                        <div style="font-size: 12px; color: #666; margin-top: 4px; margin-left: 26px;">
                            Set a maximum number of leads that can be sent to this offer. When the cap is reached, the offer will be automatically excluded from rotation until the cap period resets.
                        </div>
                    </div>
                    
                    <?php 
                    $capEnabled = ($editOffer['cap_enabled'] ?? 0) == 1;
                    ?>
                    
                    <div id="cap_fields" style="display: <?= $capEnabled ? 'block' : 'none' ?>; margin-top: 20px; padding-top: 20px; border-top: 2px solid #64b5f6;">
                        <!-- Cap Limit -->
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                Cap Limit <span style="color: #d32f2f;">*</span>
                            </label>
                            <input type="number" name="cap_limit" 
                                   value="<?= htmlspecialchars($editOffer['cap_limit'] ?? $_POST['cap_limit'] ?? '') ?>"
                                   min="1"
                                   step="1"
                                   placeholder="e.g., 25"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Maximum number of leads allowed for the selected period</div>
                            <?php if (isset($errors['cap_limit'])): ?>
                                <div style="color: #d32f2f; font-size: 14px; margin-top: 4px;"><?= htmlspecialchars($errors['cap_limit']) ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Cap Period -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                    Cap Period <span style="color: #d32f2f;">*</span>
                                </label>
                                <select name="cap_period" 
                                        style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                    <option value="">-- Select Period --</option>
                                    <option value="day" <?= ($editOffer['cap_period'] ?? '') === 'day' ? 'selected' : '' ?>>Daily</option>
                                    <option value="week" <?= ($editOffer['cap_period'] ?? '') === 'week' ? 'selected' : '' ?>>Weekly</option>
                                    <option value="month" <?= ($editOffer['cap_period'] ?? '') === 'month' ? 'selected' : '' ?>>Monthly</option>
                                    <option value="lifetime" <?= ($editOffer['cap_period'] ?? '') === 'lifetime' ? 'selected' : '' ?>>Lifetime</option>
                                </select>
                                <div style="font-size: 12px; color: #666; margin-top: 4px;">How often the cap resets</div>
                                <?php if (isset($errors['cap_period'])): ?>
                                    <div style="color: #d32f2f; font-size: 14px; margin-top: 4px;"><?= htmlspecialchars($errors['cap_period']) ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                    Timezone <span style="color: #d32f2f;">*</span>
                                </label>
                                <select name="cap_timezone"
                                        style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                    <optgroup label="US Timezones">
                                        <option value="America/New_York" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'America/New_York' ? 'selected' : '' ?>>Eastern Time (ET)</option>
                                        <option value="America/Chicago" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'America/Chicago' ? 'selected' : '' ?>>Central Time (CT)</option>
                                        <option value="America/Denver" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'America/Denver' ? 'selected' : '' ?>>Mountain Time (MT)</option>
                                        <option value="America/Los_Angeles" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'America/Los_Angeles' ? 'selected' : '' ?>>Pacific Time (PT)</option>
                                    </optgroup>
                                    <optgroup label="Europe">
                                        <option value="Europe/London" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'Europe/London' ? 'selected' : '' ?>>London (GMT/BST)</option>
                                        <option value="Europe/Paris" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'Europe/Paris' ? 'selected' : '' ?>>Paris (CET)</option>
                                        <option value="Europe/Berlin" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'Europe/Berlin' ? 'selected' : '' ?>>Berlin (CET)</option>
                                        <option value="Europe/Moscow" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'Europe/Moscow' ? 'selected' : '' ?>>Moscow (MSK)</option>
                                    </optgroup>
                                    <optgroup label="Asia & Pacific">
                                        <option value="Asia/Dubai" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'Asia/Dubai' ? 'selected' : '' ?>>Dubai (GST)</option>
                                        <option value="Asia/Singapore" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'Asia/Singapore' ? 'selected' : '' ?>>Singapore (SGT)</option>
                                        <option value="Asia/Tokyo" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'Asia/Tokyo' ? 'selected' : '' ?>>Tokyo (JST)</option>
                                        <option value="Australia/Sydney" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'Australia/Sydney' ? 'selected' : '' ?>>Sydney (AEDT)</option>
                                    </optgroup>
                                    <optgroup label="Other">
                                        <option value="UTC" <?= ($editOffer['cap_timezone'] ?? 'UTC') === 'UTC' ? 'selected' : '' ?>>UTC (Universal)</option>
                                    </optgroup>
                                </select>
                                <div style="font-size: 12px; color: #666; margin-top: 4px;">Timezone for cap period calculations (not used for lifetime caps)</div>
                            </div>
                        </div>
                        
                        <div style="background: #fff; padding: 12px; border-radius: 6px; border-left: 4px solid #2196F3;">
                            <p style="margin: 0; font-size: 13px; color: #1565c0; line-height: 1.6;">
                                <strong>💡 How it works:</strong> When this offer reaches its cap limit, it will be <strong>automatically excluded</strong> from all campaign rotations until the cap period resets. The system tracks all leads sent to this offer and respects the selected timezone for period calculations (daily caps reset at midnight, weekly caps reset on Monday, monthly caps reset on the 1st).
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                        Notes (Optional)
                    </label>
                    <textarea name="notes" 
                              rows="4" 
                              placeholder="Additional notes about this offer (targeting, restrictions, etc.)"
                              style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: inherit; resize: vertical;"><?= htmlspecialchars($editOffer['notes'] ?? $_POST['notes'] ?? '') ?></textarea>
                    <div style="font-size: 12px; color: #666; margin-top: 4px;">Any additional information about this offer</div>
                </div>

                <!-- Info Box -->
                <div style="background: #e7f3ff; border: 2px solid #b3d9ff; border-radius: 4px; padding: 16px; margin-bottom: 24px;">
                    <strong style="color: #004085;">💡 Offer Best Practices:</strong>
                    <ul style="margin-left: 20px; margin-top: 8px; color: #004085; font-size: 14px; line-height: 1.8;">
                        <li>Use your <strong>affiliate tracking link</strong> as the Offer URL</li>
                        <li>Include your affiliate ID in the URL parameters</li>
                        <li>Test the offer URL to ensure it loads correctly</li>
                        <li>Set accurate payout values for proper ROI tracking</li>
                        <li>Link to a Network if this offer is from an affiliate network</li>
                    </ul>
                </div>

                <!-- Submit -->
                <div style="display: flex; gap: 12px; margin-top: 32px;">
                    <button type="submit" class="btn btn-primary">
                        <?= $action === 'edit' ? '💾 Update' : '✨ Create' ?> Offer
                    </button>
                    <a href="?page=offers" class="btn btn-secondary">Cancel</a>
                </div>
            </form>

            <script>
            function toggleScheduleFields(show) {
                const scheduleFields = document.getElementById('schedule_fields');
                if (scheduleFields) {
                    scheduleFields.style.display = show ? 'block' : 'none';
                    // Make required fields optional when hidden
                    const requiredFields = scheduleFields.querySelectorAll('input[type="time"], select');
                    requiredFields.forEach(field => {
                        if (!show) {
                            field.removeAttribute('required');
                        } else {
                            field.setAttribute('required', 'required');
                        }
                    });
                }
            }
            
            function toggleCapFields(show) {
                const capFields = document.getElementById('cap_fields');
                if (capFields) {
                    capFields.style.display = show ? 'block' : 'none';
                    // Make required fields optional when hidden
                    const requiredFields = capFields.querySelectorAll('input[type="number"], select[name="cap_period"]');
                    requiredFields.forEach(field => {
                        if (!show) {
                            field.removeAttribute('required');
                        } else {
                            field.setAttribute('required', 'required');
                        }
                    });
                }
            }
            
            function insertTokenAtCursor(token) {
                const textarea = document.getElementById('offer_url_textarea');
                if (!textarea) return;
                
                // Get current cursor position
                const cursorPos = textarea.selectionStart;
                const textBefore = textarea.value.substring(0, cursorPos);
                const textAfter = textarea.value.substring(cursorPos);
                
                // Insert token at cursor position
                textarea.value = textBefore + token + textAfter;
                
                // Restore focus and set cursor position after inserted token
                textarea.focus();
                const newCursorPos = cursorPos + token.length;
                textarea.setSelectionRange(newCursorPos, newCursorPos);
            }
            
            function updateTrafficSourceTokenLabels() {
                const selector = document.getElementById('traffic_source_selector_for_tokens');
                const selectedOption = selector.options[selector.selectedIndex];
                
                const trafficSourceTokensContainer = document.getElementById('traffic_source_tokens_buttons');
                const genericTokensContainer = document.getElementById('traffic_source_tokens_buttons_generic');
                const trafficSourceSection = document.getElementById('traffic_source_tokens_section');
                
                // Hide specific section, show generic
                trafficSourceSection.style.display = 'none';
                genericTokensContainer.style.display = 'flex';
                
                if (!selectedOption || !selectedOption.value) {
                    // Reset to generic tokens
                    return;
                }
                
                // Clear container
                trafficSourceTokensContainer.innerHTML = '';
                
                let hasTrafficSourceTokens = false;
                
                // Process Traffic Source Tokens
                try {
                    if (selectedOption.dataset.tokens) {
                        const tsTokens = JSON.parse(selectedOption.dataset.tokens);
                        if (Array.isArray(tsTokens) && tsTokens.length > 0) {
                            hasTrafficSourceTokens = true;
                            const trafficSourceName = selectedOption.dataset.tsName || 'Unknown';
                            // Sanitize traffic source name for use in token (remove spaces, special chars)
                            const tsNameSanitized = trafficSourceName.replace(/[^a-zA-Z0-9]/g, '');
                            
                            tsTokens.forEach((token, index) => {
                                const tokenNum = index + 1;
                                const paramName = token.parameter || `token${tokenNum}`;
                                
                                // Use unique format: {ts:TrafficSourceName:parameter}
                                // This ensures 100% uniqueness across all traffic sources
                                const tokenKey = `{ts:${tsNameSanitized}:${paramName}}`;
                                const displayText = token.name 
                                    ? `${token.name} (${paramName})` 
                                    : `${paramName}`;
                                const tooltipText = token.placeholder 
                                    ? `${token.name || 'Token'} - Parameter: ${paramName}, Placeholder: ${token.placeholder}` 
                                    : `${token.name || 'Token'} - Parameter: ${paramName}`;
                                
                                const button = createTokenButton(tokenKey, displayText, tooltipText, 'traffic-source');
                                trafficSourceTokensContainer.appendChild(button);
                            });
                        }
                    }
                } catch (e) {
                    console.error('Error parsing traffic source tokens:', e);
                }
                
                // Show/hide sections and generic tokens
                if (hasTrafficSourceTokens) {
                    genericTokensContainer.style.display = 'none';
                    trafficSourceSection.style.display = 'block';
                } else {
                    genericTokensContainer.style.display = 'flex';
                }
            }
            
            function createTokenButton(tokenKey, displayText, tooltipText, typeOrColor) {
                const button = document.createElement('button');
                button.type = 'button';
                let typeClass = 'token-btn--builtin';
                if (typeOrColor === 'campaign' || typeOrColor === '#1976d2') {
                    typeClass = 'token-btn--campaign';
                } else if (typeOrColor === 'traffic-source' || typeOrColor === '#7b1fa2') {
                    typeClass = 'token-btn--traffic-source';
                }
                button.className = 'custom-token-btn ' + typeClass;
                button.setAttribute('data-token', tokenKey);
                button.onclick = function() { insertTokenAtCursor(tokenKey); };
                button.title = tooltipText;
                button.textContent = displayText;
                return button;
            }
            </script>
        </div>
    </div>

    <!-- Payout Type Info -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">📖 Payout Type Guide</h2>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                <div style="padding: 16px; background: #f9f9f9; border-radius: 4px; border-left: 4px solid #3d5a26;">
                    <strong style="color: #3d5a26; font-size: 16px; display: block; margin-bottom: 8px;">CPA - Cost Per Action</strong>
                    <p style="font-size: 14px; color: #666; line-height: 1.6;">
                        Commission paid when a user completes a specific action (purchase, signup, download, etc.).
                        Most common for e-commerce and subscription offers.
                    </p>
                </div>

                <div style="padding: 16px; background: #f9f9f9; border-radius: 4px; border-left: 4px solid #3d5a26;">
                    <strong style="color: #3d5a26; font-size: 16px; display: block; margin-bottom: 8px;">CPL - Cost Per Lead</strong>
                    <p style="font-size: 14px; color: #666; line-height: 1.6;">
                        Commission paid when a user submits their contact information (email, phone, form submission).
                        Common for lead generation campaigns.
                    </p>
                </div>

                <div style="padding: 16px; background: #f9f9f9; border-radius: 4px; border-left: 4px solid #3d5a26;">
                    <strong style="color: #3d5a26; font-size: 16px; display: block; margin-bottom: 8px;">CPS - Cost Per Sale</strong>
                    <p style="font-size: 14px; color: #666; line-height: 1.6;">
                        Commission paid based on the sale amount, usually a percentage. 
                        Payout value represents the percentage or fixed amount per sale.
                    </p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Campaigns Modal -->
<div id="campaigns-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(2px);">
    <div style="background: #fff; border-radius: 12px; padding: 0; max-width: 600px; width: 100%; box-shadow: 0 8px 32px rgba(0,0,0,0.2); animation: modalSlideIn 0.3s ease-out; max-height: 90vh; overflow-y: auto;">
        <!-- Header -->
        <div style="padding: 20px 24px; border-bottom: 2px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #3d5a26 0%, #5a7a3a 100%); border-radius: 12px 12px 0 0;">
            <h2 id="campaigns-modal-title" style="margin: 0; font-size: 20px; font-weight: 600; color: #fff;">Campaigns</h2>
            <button onclick="closeCampaignsModal()" 
                    style="background: rgba(255,255,255,0.2); border: none; color: #fff; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 20px; display: flex; align-items: center; justify-content: center; transition: background 0.2s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.3)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                ×
            </button>
        </div>
        
        <!-- Body -->
        <div id="campaigns-modal-body" style="padding: 24px;">
            <!-- Campaigns list will be inserted here -->
        </div>
        
        <!-- Footer -->
        <div style="padding: 16px 24px; border-top: 1px solid #e0e0e0; text-align: right; background: #f9f9f9; border-radius: 0 0 12px 12px;">
            <button onclick="closeCampaignsModal()" 
                    style="padding: 8px 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px; color: #666; font-weight: 500; cursor: pointer; transition: all 0.2s;"
                    onmouseover="this.style.background='#f5f5f5'; this.style.borderColor='#999'"
                    onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'">
                Close
            </button>
        </div>
    </div>
</div>

<script>
function showCampaignsModal(id, name, campaigns) {
    const modal = document.getElementById('campaigns-modal');
    const title = document.getElementById('campaigns-modal-title');
    const body = document.getElementById('campaigns-modal-body');
    
    title.textContent = 'Campaigns using: ' + name;
    
    if (!campaigns || campaigns.length === 0) {
        body.innerHTML = '<p style="text-align: center; color: #999; padding: 40px;">No campaigns are using this item.</p>';
    } else {
        let html = '<div style="max-height: 400px; overflow-y: auto;">';
        html += '<table style="width: 100%; border-collapse: collapse;">';
        html += '<thead><tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;"><th style="padding: 12px; text-align: left; font-weight: 600;">Campaign Name</th><th style="padding: 12px; text-align: center; font-weight: 600;">Status</th><th style="padding: 12px; text-align: right; font-weight: 600;">Actions</th></tr></thead>';
        html += '<tbody>';
        
        campaigns.forEach(function(campaign) {
            const statusBadge = campaign.status === 'active' 
                ? '<span style="background: #4caf50; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">Active</span>'
                : campaign.status === 'paused'
                ? '<span style="background: #ff9800; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">Paused</span>'
                : '<span style="background: #999; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">' + campaign.status + '</span>';
            
            html += '<tr style="border-bottom: 1px solid #eee;">';
            html += '<td style="padding: 12px;"><strong>' + escapeHtml(campaign.name) + '</strong></td>';
            html += '<td style="padding: 12px; text-align: center;">' + statusBadge + '</td>';
            html += '<td style="padding: 12px; text-align: right;">';
            html += '<a href="?page=campaign-list&action=edit&id=' + campaign.id + '" style="color: #2196F3; text-decoration: none; font-size: 13px; font-weight: 500;" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">View →</a>';
            html += '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table></div>';
        body.innerHTML = html;
    }
    
    modal.style.display = 'flex';
    modal.onclick = function(e) {
        if (e.target === modal) {
            closeCampaignsModal();
        }
    };
}

function closeCampaignsModal() {
    document.getElementById('campaigns-modal').style.display = 'none';
}

function filterOffersTable() {
    const input = document.getElementById('offerSearchInput');
    const query = (input ? input.value : '').toLowerCase().trim();
    const rows = document.querySelectorAll('#offers-table .offer-row');
    const cards = document.querySelectorAll('.mobile-offer-cards .mobile-offer-card');
    let visibleCount = 0;
    const totalCount = rows.length || cards.length;

    rows.forEach(function(row) {
        const searchData = row.getAttribute('data-search') || '';
        const match = !query || searchData.indexOf(query) !== -1;
        row.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });

    cards.forEach(function(card) {
        const searchData = card.getAttribute('data-search') || '';
        const match = !query || searchData.indexOf(query) !== -1;
        card.style.display = match ? '' : 'none';
    });

    const countEl = document.getElementById('offerSearchCount');
    if (countEl) {
        if (query) {
            countEl.textContent = 'Showing ' + visibleCount + ' of ' + totalCount + ' offers';
        } else {
            countEl.textContent = totalCount + ' total offers';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('offerSearchInput')) {
        filterOffersTable();
    }
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Add CSS animation if not exists
if (!document.getElementById('modal-animation-styles')) {
    const style = document.createElement('style');
    style.id = 'modal-animation-styles';
    style.textContent = '@keyframes modalSlideIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }';
    document.head.appendChild(style);
}
</script>

</div>
