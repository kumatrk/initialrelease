<?php
// Traffic Sources CRUD Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use SimpleKuma\TrafficSource\TrafficSourceCostStatus;
use SimpleKuma\TrafficSource\TrafficSourceTemplates;

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

// Get database connection
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$trafficSource = new \SimpleKuma\Entity\TrafficSource($db);

// Handle actions
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$errors = [];
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'delete') {
        // Check if traffic source is being used by campaigns
        $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM campaigns WHERE traffic_source_id = ?");
        $checkStmt->bind_param('i', $id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $checkRow = $checkResult->fetch_assoc();
        
        if ($checkRow['count'] > 0) {
            $errors['general'] = 'Cannot delete traffic source: ' . $checkRow['count'] . ' campaign(s) are currently using it. Please remove or reassign those campaigns first.';
            $action = 'list';
        } elseif ($trafficSource->delete($id)) {
            $success = 'Traffic source deleted successfully';
            $action = 'list';
        } else {
            $errors['general'] = 'Failed to delete traffic source';
        }
    } else {
        // Parse custom tokens (new detailed structure)
        $tokens = [];
        $tokenNames = $_POST['token_name'] ?? [];
        $tokenParameters = $_POST['token_parameter'] ?? [];
        $tokenPlaceholders = $_POST['token_placeholder'] ?? [];
        
        foreach ($tokenParameters as $idx => $parameter) {
            $parameter = trim($parameter);
            
            // Skip if parameter is empty
            if (empty($parameter)) {
                continue;
            }
            
            // Get pass-through options
            $passToLpKey = 'token_pass_to_lp_' . $idx;
            $passToOfferKey = 'token_pass_to_offer_' . $idx;
            $passToLp = isset($_POST[$passToLpKey]) && !empty($_POST[$passToLpKey]);
            $passToOffer = isset($_POST[$passToOfferKey]) && !empty($_POST[$passToOfferKey]);
            
            $tokens[] = [
                'name' => trim($tokenNames[$idx] ?? ''),
                'parameter' => $parameter,
                'placeholder' => trim($tokenPlaceholders[$idx] ?? ''),
                'pass_to_lp' => $passToLp,
                'pass_to_offer' => $passToOffer
            ];
        }

        $data = [
            'name' => $_POST['name'] ?? '',
            // Traffic-source outbound postback templates removed — use campaign Custom Postbacks
            'postback_template' => '',
            'cost_tracking_method' => $_POST['cost_tracking_method'] ?? 'manual_token',
            'cost_param_key' => $_POST['cost_param_key'] ?? '',
            'cost_currency' => $_POST['cost_currency'] ?? '',
            'tokens' => $tokens,
        ];

        $errors = $trafficSource->validate($data);

        if (empty($errors)) {
            if ($action === 'edit' && $id) {
                if ($trafficSource->update($id, $data)) {
                    $success = 'Traffic source updated successfully';
                    $action = 'list';
                } else {
                    $errors['general'] = 'Failed to update traffic source';
                }
            } else {
                if ($trafficSource->create($data)) {
                    $success = 'Traffic source created successfully';
                    $action = 'list';
                } else {
                    $errors['general'] = 'Failed to create traffic source';
                }
            }
        }
    }
}

// Load traffic source for editing
$editSource = null;
if ($action === 'edit' && $id) {
    $editSource = $trafficSource->getById($id);
}

$db->close();
?>

<div class="page-header">
    <h1 class="page-title">Traffic Sources</h1>
    <p class="page-description">Manage your traffic source configurations and tracking parameters.</p>
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
            <h2 class="card-title">Your Traffic Sources</h2>
            <a href="?page=traffic-sources&action=add" class="btn btn-primary">+ Add Traffic Source</a>
        </div>
        <div class="card-body">
            <?php
            $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            $trafficSource = new \SimpleKuma\Entity\TrafficSource($db);
            $sources = $trafficSource->getAll();
            $db->close();
            ?>

            <?php if (empty($sources)): ?>
                <div style="text-align: center; padding: 60px; color: #999;">
                    <img src="<?= ASSETS_BASE_URL ?>/assets/images/trafficsources.png" alt="Traffic Sources" style="width: 64px; height: 64px; margin-bottom: 16px;">
                    <p>No traffic sources yet. Add your first traffic source to get started!</p>
                    <a href="?page=traffic-sources&action=add" class="btn btn-primary" style="margin-top: 20px;">+ Add Traffic Source</a>
                </div>
            <?php else: ?>
                <p class="ts-traffic-sources-intro" style="font-size: 13px; color: var(--color-gray-600, #666); margin: 0 0 16px 0; line-height: 1.55;">
                    Google, YouTube, Facebook, PropellerAds, and Bing are set up for you already. For other networks—TikTok, Taboola, NewsBreak, and more—click
                    <strong>Add Traffic Source</strong>, then <strong>Create from Template</strong> to load the right tracking fields in one step.
                    If a source shows <strong>Variables only</strong>, clicks and stats will track, but ad spend from that network won’t import automatically yet.
                </p>
                <!-- Desktop Table View (hidden on mobile) -->
                <div class="table-wrapper desktop-only">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Custom Params</th>
                                <th>Cost Tracking</th>
                                <th>Created</th>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sources as $source): ?>
                                <tr>
                                <td><strong><?= htmlspecialchars($source['name']) ?></strong></td>
                                <td><?= count($source['tokens_json']) ?> params</td>
                                    <td>
                                        <?= TrafficSourceCostStatus::renderBadge($source, ' title="' . htmlspecialchars(TrafficSourceCostStatus::getHelpNote($source) ?? '', ENT_QUOTES) . '"') ?>
                                    </td>
                                <td><?= date('M d, Y', strtotime($source['created_at'])) ?></td>
                                <td>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <!-- Edit Button -->
                                        <a href="?page=traffic-sources&action=edit&id=<?= $source['id'] ?>" 
                                           style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none;"
                                           title="Edit Traffic Source"
                                           onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                           onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                            ✏️
                                        </a>
                                        
                                        <!-- Delete Button -->
                                        <form method="post" action="?page=traffic-sources&action=delete&id=<?= $source['id'] ?>" 
                                              style="display: inline; margin: 0;" 
                                              onsubmit="return confirm('Are you sure you want to delete this traffic source?\\n\\nThis cannot be undone.');">
                                            <button type="submit" 
                                                    style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666;"
                                                    title="Delete Traffic Source"
                                                    onmouseover="this.style.background='#ffebee'; this.style.borderColor='#f44336'; this.style.color='#f44336';"
                                                    onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                                🗑️
                                            </button>
                                        </form>
                                    </div>
                                </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Mobile Card View (hidden on desktop) -->
                <div class="mobile-traffic-source-cards mobile-only">
                    <?php foreach ($sources as $source): ?>
                        <?php $tokensCount = count($source['tokens_json'] ?? []); ?>
                        <div class="mobile-traffic-source-card" style="background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); padding: var(--spacing-md); margin-bottom: var(--spacing-md); box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                            <!-- Header: Name -->
                            <div style="margin-bottom: var(--spacing-sm); border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: var(--spacing-xs);">
                                <div style="font-weight: 600; font-size: 16px; color: #3d5a26;">
                                    <?= htmlspecialchars($source['name']) ?>
                                </div>
                                <div style="font-size: 11px; color: #666; margin-top: 4px;">
                                    Created: <?= date('M d, Y', strtotime($source['created_at'])) ?>
                                </div>
                            </div>
                            
                            <!-- Info: Custom Params and Cost Tracking -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-sm); margin-bottom: var(--spacing-sm);">
                                <div>
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Custom Params</strong></div>
                                    <div style="font-size: 13px; color: #3d5a26; font-weight: 500;">
                                        <?= $tokensCount ?> <?= $tokensCount === 1 ? 'param' : 'params' ?>
                                    </div>
                                </div>
                                <div>
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Cost Tracking</strong></div>
                                    <div style="font-size: 12px;">
                                        <?= TrafficSourceCostStatus::renderBadge($source, 'font-size:10px;') ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Actions -->
                            <div style="display: flex; gap: 8px; border-top: 1px solid rgba(0,0,0,0.1); padding-top: var(--spacing-sm);">
                                <a href="?page=traffic-sources&action=edit&id=<?= $source['id'] ?>" 
                                   style="flex: 1; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; text-decoration: none; color: #666; text-align: center; display: inline-block;">
                                    ✏️ Edit
                                </a>
                                <form method="post" action="?page=traffic-sources&action=delete&id=<?= $source['id'] ?>" 
                                      style="flex: 1; margin: 0;" 
                                      onsubmit="return confirm('Are you sure you want to delete this traffic source?\\n\\nThis cannot be undone.');">
                                    <button type="submit" 
                                            style="width: 100%; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; color: #666;">
                                        🗑️ Delete
                                    </button>
                                </form>
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
            <h2 class="card-title"><?= $action === 'edit' ? 'Edit' : 'Add' ?> Traffic Source</h2>
            <div style="display: flex; gap: 12px;">
                <?php if ($action === 'add'): ?>
                    <button type="button" onclick="showTemplateSelector()" class="btn btn-outline" style="display: flex; align-items: center; gap: 8px;">
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/tsourcetemplate.png" alt="Template" style="width: 20px; height: 20px;">
                        Create from Template
                    </button>
                <?php endif; ?>
                <a href="?page=traffic-sources" class="btn btn-secondary">← Back to List</a>
            </div>
        </div>
        <div class="card-body">
            <!-- Template Selector Modal -->
            <?php if ($action === 'add'): ?>
            <div id="templateModal" class="ts-template-modal-overlay" aria-hidden="true">
                <div class="ts-template-modal-panel" role="dialog" aria-modal="true" aria-labelledby="ts-template-modal-title">
                    <h3 id="ts-template-modal-title" class="ts-template-modal-title">
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/tsourcetemplate.png" alt="" style="width: 32px; height: 32px;">
                        Select Traffic Source Template
                    </h3>
                    <p class="ts-template-modal-intro">Pick a network to fill in the standard tracking fields for you. Google, YouTube, Facebook, PropellerAds, and Bing are already on your list—use this for everything else.</p>

                    <?php
                    $templateCatalog = TrafficSourceTemplates::all();
                    foreach (TrafficSourceTemplates::getSections() as $section):
                        $isWarningSection = !empty($section['warning']);
                    ?>
                    <div class="ts-template-section">
                        <?php if ($isWarningSection): ?>
                        <div class="ts-template-warning-banner">
                            <strong>API cost sync not built yet.</strong> Clicks and token breakdowns work; automatic cost pull will come in a future release.
                        </div>
                        <?php endif; ?>
                        <h4 class="ts-template-section-title"><?= htmlspecialchars($section['title']) ?></h4>
                        <p class="ts-template-section-desc"><?= htmlspecialchars($section['description']) ?></p>
                        <div class="ts-template-grid">
                            <?php foreach ($section['template_keys'] as $key):
                                $tpl = $templateCatalog[$key] ?? null;
                                if (!$tpl) continue;
                                $isPending = ($tpl['cost_tier'] ?? '') === TrafficSourceCostStatus::TIER_VARIABLES_ONLY;
                                $btnClass = 'ts-template-btn template-btn' . ($isPending ? ' ts-template-btn--pending' : '');
                            ?>
                            <button type="button" onclick="loadTemplate('<?= htmlspecialchars($key, ENT_QUOTES) ?>')" class="<?= $btnClass ?>">
                                <span class="ts-template-btn-title"><?= htmlspecialchars($tpl['name']) ?></span>
                                <span class="ts-template-btn-blurb"><?= htmlspecialchars($tpl['blurb']) ?></span>
                                <?php if ($isPending): ?>
                                <span class="ts-template-btn-tag">No API cost yet</span>
                                <?php endif; ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <button type="button" onclick="closeTemplateModal()" class="btn btn-secondary ts-template-modal-cancel">
                        Cancel
                    </button>
                </div>
            </div>
            <?php endif; ?>
            <form method="post" action="?page=traffic-sources&action=<?= $action ?><?= $id ? "&id={$id}" : '' ?>">
                <div id="traffic_source_cost_notice" style="display: none;"></div>
                <?php if ($action === 'edit' && $editSource): ?>
                    <?= TrafficSourceCostStatus::renderNotice($editSource) ?>
                <?php endif; ?>
                <!-- Basic Info -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                        Traffic Source Name <span style="color: #d32f2f;">*</span>
                    </label>
                    <input type="text" name="name" id="traffic_source_name" class="form-input" 
                           value="<?= htmlspecialchars($editSource['name'] ?? $_POST['name'] ?? '') ?>" 
                           oninput="autoSelectCostTrackingMethod()"
                           required style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                    <?php if (isset($errors['name'])): ?>
                        <div style="color: #d32f2f; font-size: 14px; margin-top: 4px;"><?= htmlspecialchars($errors['name']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Cost Tracking -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cost Tracking Method <span style="color: #d32f2f;">*</span></label>
                    <select name="cost_tracking_method" id="cost_tracking_method" 
                            onchange="toggleCostParamFields()"
                            style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px; margin-bottom: 16px;">
                        <option value="manual_token" <?= ($editSource['cost_tracking_method'] ?? $_POST['cost_tracking_method'] ?? 'manual_token') === 'manual_token' ? 'selected' : '' ?>>
                            Manual Token (URL Parameter)
                        </option>
                        <option value="integrated_api" <?= ($editSource['cost_tracking_method'] ?? $_POST['cost_tracking_method'] ?? '') === 'integrated_api' ? 'selected' : '' ?>>
                            Integrated API (Facebook / Google — or variables-only networks)
                        </option>
                    </select>
                    <div style="font-size: 12px; color: #666; margin-top: 4px; padding: 12px; background: #f9f9f9; border-radius: 4px; border-left: 3px solid #3d5a26;">
                        <strong>Manual Token:</strong> Cost passed as URL parameter (e.g., ?cost=10.50). You specify the parameter name.<br>
                        <strong>Integrated API:</strong> Live cost sync for Facebook and Google/YouTube (Settings → Integrations). For Rumble, Outbrain, etc., this means <em>variables only</em> — tokens work but API cost is not synced yet.
                    </div>
                </div>

                <div id="cost_param_fields" style="margin-bottom: 24px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cost Parameter Key</label>
                            <input type="text" name="cost_param_key" id="cost_param_key"
                                   value="<?= htmlspecialchars($editSource['cost_param_key'] ?? $_POST['cost_param_key'] ?? '') ?>" 
                                   placeholder="e.g., cost, cpc, price"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Parameter name in tracking URL that contains the cost value (e.g., ?cost=10.50)</div>
                        </div>
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cost Currency</label>
                            <select name="cost_currency" 
                                    style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;">
                                <?php
                                $currencies = [
                                    'USD' => 'USD - US Dollar',
                                    'EUR' => 'EUR - Euro',
                                    'GBP' => 'GBP - British Pound',
                                    'CAD' => 'CAD - Canadian Dollar',
                                    'AUD' => 'AUD - Australian Dollar',
                                    'JPY' => 'JPY - Japanese Yen',
                                    'CNY' => 'CNY - Chinese Yuan',
                                    'INR' => 'INR - Indian Rupee',
                                    'BRL' => 'BRL - Brazilian Real',
                                    'MXN' => 'MXN - Mexican Peso',
                                    'RUB' => 'RUB - Russian Ruble',
                                    'KRW' => 'KRW - South Korean Won',
                                    'SGD' => 'SGD - Singapore Dollar',
                                    'HKD' => 'HKD - Hong Kong Dollar',
                                    'NZD' => 'NZD - New Zealand Dollar',
                                    'CHF' => 'CHF - Swiss Franc',
                                    'SEK' => 'SEK - Swedish Krona',
                                    'NOK' => 'NOK - Norwegian Krone',
                                    'DKK' => 'DKK - Danish Krone',
                                    'PLN' => 'PLN - Polish Zloty',
                                    'ZAR' => 'ZAR - South African Rand',
                                    'TRY' => 'TRY - Turkish Lira',
                                    'AED' => 'AED - UAE Dirham',
                                    'SAR' => 'SAR - Saudi Riyal',
                                ];
                                $selectedCurrency = $editSource['cost_currency'] ?? $_POST['cost_currency'] ?? 'USD';
                                foreach ($currencies as $code => $label):
                                ?>
                                    <option value="<?= htmlspecialchars($code) ?>" <?= $selectedCurrency === $code ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Select the currency for cost tracking</div>
                        </div>
                    </div>
                </div>
                
                <script>
                function toggleCostParamFields() {
                    const method = document.getElementById('cost_tracking_method').value;
                    const fields = document.getElementById('cost_param_fields');
                    
                    if (method === 'manual_token') {
                        fields.style.display = 'block';
                    } else {
                        fields.style.display = 'none';
                    }
                }
                
                function autoSelectCostTrackingMethod() {
                    const nameInput = document.getElementById('traffic_source_name');
                    const costMethodSelect = document.getElementById('cost_tracking_method');
                    
                    if (!nameInput || !costMethodSelect) return;
                    
                    const nameValue = nameInput.value.toLowerCase().trim();
                    
                    // Auto-select integrated_api for Facebook or Google/YouTube
                    if (nameValue.includes('facebook')) {
                        costMethodSelect.value = 'integrated_api';
                        toggleCostParamFields(); // Update visibility
                    } else if (nameValue.includes('google') || nameValue.includes('youtube')) {
                        costMethodSelect.value = 'integrated_api';
                        toggleCostParamFields(); // Update visibility
                    } else if (nameValue === '') {
                        // Reset to manual_token if name is cleared
                        costMethodSelect.value = 'manual_token';
                        toggleCostParamFields(); // Update visibility
                    }
                }
                
                // Initialize on page load
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', function() {
                        toggleCostParamFields();
                        autoSelectCostTrackingMethod(); // Auto-select on load if editing existing source
                    });
                } else {
                    toggleCostParamFields();
                    autoSelectCostTrackingMethod(); // Auto-select on load if editing existing source
                }
                </script>
                        
                <!-- Custom Tracking Tokens (Up to 20) -->
                <fieldset style="margin-bottom: 24px; border: 2px solid #ddd; border-radius: 4px; padding: 16px;">
                    <legend style="font-weight: 600; padding: 0 8px;">Traffic Source Tokens (Up to 20)</legend>
                    <p style="font-size: 13px; color: #666; margin-bottom: 16px;">
                        Define tracking parameters for this traffic source. These will automatically append to campaign tracking links when this source is selected.
                    </p>
                    
                    <!-- Table Header -->
                    <div class="token-table-header desktop-only" style="display: grid; grid-template-columns: 60px 2fr 1.5fr 2fr 2.5fr 100px auto; gap: 12px; margin-bottom: 8px; padding: 8px; background: #f0f0f0; border-radius: 4px; font-size: 12px; font-weight: 600;">
                        <div>Token</div>
                        <div>Name</div>
                        <div>Parameter</div>
                        <div>Placeholder</div>
                        <div>URL Append</div>
                        <div style="text-align: center;">Pass To</div>
                        <div></div>
                    </div>
                    
                    <div id="tokens_container">
                        <?php
                        $editTokens = $editSource['tokens_json'] ?? [];
                        // Default to 3 rows, but show existing tokens if editing
                        $tokenCount = max(count($editTokens), 3);
                        if ($tokenCount > 20) $tokenCount = 20;
                        
                        // Fill with existing tokens or empty placeholders
                        for ($i = 0; $i < $tokenCount; $i++) {
                            $token = $editTokens[$i] ?? ['name' => '', 'parameter' => '', 'placeholder' => '', 'pass_to_lp' => false, 'pass_to_offer' => false];
                            $tokenNum = $i + 1;
                            $urlAppend = '';
                            if (!empty($token['parameter']) && !empty($token['placeholder'])) {
                                $urlAppend = '&' . $token['parameter'] . '=' . $token['placeholder'];
                            }
                        ?>
                        <div class="token-row" style="display: grid; grid-template-columns: 60px 2fr 1.5fr 2fr 2.5fr 100px auto; gap: 12px; margin-bottom: 8px; align-items: center;">
                            <div style="text-align: center; font-weight: 600; color: #666;">Token <?= $tokenNum ?></div>
                            <div>
                                <input type="text" name="token_name[]" 
                                       value="<?= htmlspecialchars($token['name'] ?? '') ?>"
                                       placeholder="Display name"
                                       maxlength="100"
                                       style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                                       aria-label="Token name">
                            </div>
                            <div>
                                <input type="text" name="token_parameter[]" 
                                       value="<?= htmlspecialchars($token['parameter'] ?? '') ?>"
                                       placeholder="e.g., fbclid"
                                       maxlength="50"
                                       onchange="updateTSTokenUrlAppend(this)"
                                       style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                                       aria-label="Parameter name">
                            </div>
                            <div>
                                <input type="text" name="token_placeholder[]" 
                                       value="<?= htmlspecialchars($token['placeholder'] ?? '') ?>"
                                       placeholder="e.g., {{site_source_name}}"
                                       maxlength="100"
                                       onchange="updateTSTokenUrlAppend(this)"
                                       style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                                       aria-label="Placeholder">
                            </div>
                            <div>
                                <input type="text" class="ts-url-append-display" 
                                       value="<?= htmlspecialchars($urlAppend) ?>"
                                       readonly
                                       style="width: 100%; padding: 8px; background: #f9f9f9; border: 1px solid #ccc; border-radius: 4px; font-size: 12px; font-family: monospace; color: #3d5a26;"
                                       aria-label="URL append preview">
                            </div>
                            <div style="display: flex; gap: 8px; justify-content: center;">
                                <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px;">
                                    <input type="checkbox" name="token_pass_to_lp_<?= $i ?>[]" 
                                           value="1"
                                           <?= !empty($token['pass_to_lp']) ? 'checked' : '' ?>
                                           style="width: 16px; height: 16px;">
                                    LP
                                </label>
                                <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px;">
                                    <input type="checkbox" name="token_pass_to_offer_<?= $i ?>[]" 
                                           value="1"
                                           <?= !empty($token['pass_to_offer']) ? 'checked' : '' ?>
                                           style="width: 16px; height: 16px;">
                                    Offer
                                </label>
                            </div>
                            <button type="button" class="btn btn-outline" onclick="removeTSTokenRow(this)" 
                                    style="padding: 6px 10px; font-size: 12px;" 
                                    aria-label="Remove token row">×</button>
                        </div>
                        <?php } ?>
                    </div>
                    <button type="button" class="btn btn-outline" onclick="addTSTokenRow()" id="add_ts_token_btn" 
                            style="margin-top: 12px;" 
                            aria-label="Add another token row">
                        + Add Token
                    </button>
                    <div style="font-size: 12px; color: #666; margin-top: 8px;">
                        💡 Maximum 20 tokens. Parameter names must be unique. These tokens auto-append to campaign tracking links.
                    </div>
                </fieldset>

                <!-- Submit -->
                <div style="display: flex; gap: 12px; margin-top: 32px;">
                    <button type="submit" class="btn btn-primary">
                        <?= $action === 'edit' ? 'Update' : 'Create' ?> Traffic Source
                    </button>
                    <a href="?page=traffic-sources" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        function updateTSTokenUrlAppend(input) {
            const row = input.closest('.token-row');
            if (!row) return;
            
            const paramInput = row.querySelector('input[name="token_parameter[]"]');
            const placeholderInput = row.querySelector('input[name="token_placeholder[]"]');
            const urlAppendDisplay = row.querySelector('.ts-url-append-display');
            
            if (!paramInput || !placeholderInput || !urlAppendDisplay) return;
            
            const param = paramInput.value.trim();
            const placeholder = placeholderInput.value.trim();
            
            if (param && placeholder) {
                urlAppendDisplay.value = '&' + param + '=' + placeholder;
            } else if (param) {
                urlAppendDisplay.value = '&' + param + '=';
            } else {
                urlAppendDisplay.value = '';
            }
        }

        function addTSTokenRow() {
            const container = document.getElementById('tokens_container');
            if (!container) return;
            const currentRows = container.querySelectorAll('.token-row').length;
            
            if (currentRows >= 20) {
                alert('Maximum 20 tokens allowed');
                return;
            }
            
            const tokenNum = currentRows + 1;
            const tokenIdx = currentRows;
            
            const newRow = document.createElement('div');
            newRow.className = 'token-row';
            newRow.style.cssText = 'display: grid; grid-template-columns: 60px 2fr 1.5fr 2fr 2.5fr 100px auto; gap: 12px; margin-bottom: 8px; align-items: center;';
            newRow.innerHTML = `
                <div style="text-align: center; font-weight: 600; color: #666;">Token ${tokenNum}</div>
                <div>
                    <input type="text" name="token_name[]" 
                           placeholder="Display name"
                           maxlength="100"
                           style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                           aria-label="Token name">
                </div>
                <div>
                    <input type="text" name="token_parameter[]" 
                           placeholder="e.g., fbclid"
                           maxlength="50"
                           onchange="updateTSTokenUrlAppend(this)"
                           style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                           aria-label="Parameter name">
                </div>
                <div>
                    <input type="text" name="token_placeholder[]" 
                           placeholder="e.g., {{site_source_name}}"
                           maxlength="100"
                           onchange="updateTSTokenUrlAppend(this)"
                           style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                           aria-label="Placeholder">
                </div>
                <div>
                    <input type="text" class="ts-url-append-display" 
                           readonly
                           style="width: 100%; padding: 8px; background: #f9f9f9; border: 1px solid #ccc; border-radius: 4px; font-size: 12px; font-family: monospace; color: #3d5a26;"
                           aria-label="URL append preview">
                </div>
                <div style="display: flex; gap: 8px; justify-content: center;">
                    <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px;">
                        <input type="checkbox" name="token_pass_to_lp_${tokenIdx}[]" 
                               value="1"
                               style="width: 16px; height: 16px;">
                        LP
                    </label>
                    <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px;">
                        <input type="checkbox" name="token_pass_to_offer_${tokenIdx}[]" 
                               value="1"
                               style="width: 16px; height: 16px;">
                        Offer
                    </label>
                </div>
                <button type="button" class="btn btn-outline" onclick="removeTSTokenRow(this)" 
                        style="padding: 6px 10px; font-size: 12px;" 
                        aria-label="Remove token row">×</button>
            `;
            container.appendChild(newRow);
            updateAddTSTokenButton();
            updateTSTokenNumbers();
        }

        function removeTSTokenRow(button) {
            const container = document.getElementById('tokens_container');
            if (!container) return;
            const rows = container.querySelectorAll('.token-row');
            
            if (rows.length <= 1) {
                alert('At least one token row must remain');
                return;
            }
            
            button.closest('.token-row').remove();
            updateAddTSTokenButton();
            updateTSTokenNumbers();
        }

        function updateTSTokenNumbers() {
            const container = document.getElementById('tokens_container');
            if (!container) return;
            const rows = container.querySelectorAll('.token-row');
            rows.forEach((row, idx) => {
                const tokenLabel = row.querySelector('div:first-child');
                if (tokenLabel) {
                    tokenLabel.textContent = 'Token ' + (idx + 1);
                }
            });
        }

        function updateAddTSTokenButton() {
            const container = document.getElementById('tokens_container');
            if (!container) return;
            const currentRows = container.querySelectorAll('.token-row').length;
            const addBtn = document.getElementById('add_ts_token_btn');
            if (!addBtn) return;
            
            if (currentRows >= 20) {
                addBtn.disabled = true;
                addBtn.style.opacity = '0.5';
                addBtn.style.cursor = 'not-allowed';
            } else {
                addBtn.disabled = false;
                addBtn.style.opacity = '1';
                addBtn.style.cursor = 'pointer';
            }
        }

        // Initialize button state on page load
        if (document.getElementById('add_ts_token_btn')) {
            updateAddTSTokenButton();
        }

        const templates = <?= TrafficSourceTemplates::forJavaScript() ?>;
        const COST_TIER_VARIABLES_ONLY = <?= json_encode(TrafficSourceCostStatus::TIER_VARIABLES_ONLY) ?>;

        function showTemplateCostNotice(template) {
            const box = document.getElementById('traffic_source_cost_notice');
            if (!box) return;
            if (template && template.cost_tier === COST_TIER_VARIABLES_ONLY) {
                box.style.display = 'block';
                box.innerHTML = '<div class="ts-cost-notice ts-cost-notice--warning" style="margin:0 0 16px 0;">'
                    + '<strong>Tracking variables ready — API cost coming soon</strong><br>'
                    + 'Click and conversion tracking work with these tokens. Automatic API cost sync for this network is not built into Kuma yet — cost in reports may stay at zero until that integration ships.'
                    + '</div>';
            } else {
                box.style.display = 'none';
                box.innerHTML = '';
            }
        }

        function showTemplateSelector() {
            const modal = document.getElementById('templateModal');
            if (!modal) return;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('ts-template-modal-open');
        }

        function closeTemplateModal() {
            const modal = document.getElementById('templateModal');
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('ts-template-modal-open');
        }

        function loadTemplate(templateName) {
            const template = templates[templateName];
            if (!template) return;
            
            // Set basic fields
            document.querySelector('input[name="name"]').value = template.name;
            if (document.querySelector('select[name="cost_tracking_method"]')) {
                document.querySelector('select[name="cost_tracking_method"]').value = template.cost_tracking_method || 'manual_token';
            }
            document.querySelector('input[name="cost_param_key"]').value = template.cost_param_key || '';
            const currencySelect = document.querySelector('select[name="cost_currency"]');
            if (currencySelect) {
                currencySelect.value = template.cost_currency || 'USD';
            }
            if (typeof toggleCostParamFields === 'function') {
                toggleCostParamFields(); // Update visibility based on selected method
            }
            
            // Trigger auto-selection based on name (in case template didn't set it correctly)
            if (typeof autoSelectCostTrackingMethod === 'function') {
                autoSelectCostTrackingMethod();
            }
            
            // Clear existing token rows
            const container = document.getElementById('tokens_container');
            if (!container) return;
            container.innerHTML = '';
            
            // Add template tokens
            template.tokens.forEach((token, idx) => {
                const tokenNum = idx + 1;
                const urlAppend = token.parameter && token.placeholder ? '&' + token.parameter + '=' + token.placeholder : (token.parameter ? '&' + token.parameter + '=' : '');
                
                const newRow = document.createElement('div');
                newRow.className = 'token-row';
                newRow.style.cssText = 'display: grid; grid-template-columns: 60px 2fr 1.5fr 2fr 2.5fr 100px auto; gap: 12px; margin-bottom: 8px; align-items: center;';
                newRow.innerHTML = `
                    <div style="text-align: center; font-weight: 600; color: #666;">Token ${tokenNum}</div>
                    <div>
                        <input type="text" name="token_name[]" 
                               value="${token.name || ''}"
                               placeholder="Display name"
                               maxlength="100"
                               style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                               aria-label="Token name">
                    </div>
                    <div>
                        <input type="text" name="token_parameter[]" 
                               value="${token.parameter || ''}"
                               placeholder="e.g., fbclid"
                               maxlength="50"
                               onchange="updateTSTokenUrlAppend(this)"
                               style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                               aria-label="Parameter name">
                    </div>
                    <div>
                        <input type="text" name="token_placeholder[]" 
                               value="${token.placeholder || ''}"
                               placeholder="e.g., {{site_source_name}}"
                               maxlength="100"
                               onchange="updateTSTokenUrlAppend(this)"
                               style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                               aria-label="Placeholder">
                    </div>
                    <div>
                        <input type="text" class="ts-url-append-display" 
                               value="${urlAppend}"
                               readonly
                               style="width: 100%; padding: 8px; background: #f9f9f9; border: 1px solid #ccc; border-radius: 4px; font-size: 12px; font-family: monospace; color: #3d5a26;"
                               aria-label="URL append preview">
                    </div>
                    <div style="display: flex; gap: 8px; justify-content: center;">
                        <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px;">
                            <input type="checkbox" name="token_pass_to_lp_${idx}[]" 
                                   value="1" ${token.pass_to_lp ? 'checked' : ''}
                                   style="width: 16px; height: 16px;">
                            LP
                        </label>
                        <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px;">
                            <input type="checkbox" name="token_pass_to_offer_${idx}[]" 
                                   value="1" ${token.pass_to_offer ? 'checked' : ''}
                                   style="width: 16px; height: 16px;">
                            Offer
                        </label>
                    </div>
                    <button type="button" class="btn btn-outline" onclick="removeTSTokenRow(this)" 
                            style="padding: 6px 10px; font-size: 12px;" 
                            aria-label="Remove token row">×</button>
                `;
                container.appendChild(newRow);
            });
            
            updateAddTSTokenButton();
            updateTSTokenNumbers();
            closeTemplateModal();
            showTemplateCostNotice(template);

            const varsOnly = template.cost_tier === COST_TIER_VARIABLES_ONLY;
            alert('Template loaded: ' + template.name + (varsOnly
                ? '\n\nNote: This network supports click/token tracking only. API cost sync is not available yet.'
                : '\n\nReview the tokens and click Create Traffic Source when ready.'));
        }

        (function initTemplateModal() {
            const modal = document.getElementById('templateModal');
            if (!modal) return;
            document.body.appendChild(modal);
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeTemplateModal();
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeTemplateModal();
                }
            });
        })();
    </script>
<?php endif; ?>
