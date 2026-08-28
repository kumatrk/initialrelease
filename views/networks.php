<?php
// Networks CRUD Page
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
$network = new \SimpleKuma\Entity\Network($db);

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$errors = [];
$success = '';
$permission = $GLOBALS['permission'] ?? null;
$canManage = ($permission && $permission->hasPermission(Permission::PERM_NETWORK_MANAGE))
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
        $errors['general'] = 'You do not have permission to manage networks';
        $action = 'list';
    } elseif ($action === 'delete') {
        if ($network->delete($id)) {
            $success = 'Network deleted successfully';
            $action = 'list';
        } else {
            $errors['general'] = 'Failed to delete network';
        }
    } else {
        $data = [
            'name' => $_POST['name'] ?? '',
            // Network outbound postback templates removed — use Settings → Integrations (Custom Postbacks)
            // and Postback URLs for inbound S2S from affiliate networks.
            'postback_template' => '',
            'notes' => $_POST['notes'] ?? '',
        ];

        $errors = $network->validate($data);

        if (empty($errors)) {
            if ($action === 'edit' && $id) {
                if ($network->update($id, $data)) {
                    $success = 'Network updated successfully';
                    $action = 'list';
                } else {
                    $errors['general'] = 'Failed to update network';
                }
            } else {
                if ($network->create($data)) {
                    $success = 'Network created successfully';
                    $action = 'list';
                } else {
                    $errors['general'] = 'Failed to create network';
                }
            }
        }
    }
}

$editNetwork = null;
if ($action === 'edit' && $id) {
    $editNetwork = $network->getById($id);
}

$db->close();
?>

<div class="page-header">
    <h1 class="page-title">Affiliate Networks</h1>
    <p class="page-description">Organize offers by affiliate network. Configure postbacks under Settings → Integrations or Postback URLs.</p>
</div>

<?php if ($success): ?>
<div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #28a745;">
    ✅ <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Your Networks</h2>
            <?php if ($canManage): ?>
            <a href="?page=networks&action=add" class="btn btn-primary">+ Add Network</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php
            $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            $network = new \SimpleKuma\Entity\Network($db);
            $networks = $network->getAll();
            $db->close();
            ?>

            <?php if (empty($networks)): ?>
                <div style="text-align: center; padding: 60px; color: #999;">
                    <img src="<?= ASSETS_BASE_URL ?>/assets/images/networks.png" alt="Networks" style="width: 64px; height: 64px; margin-bottom: 16px;">
                    <p>No networks yet. Add your first affiliate network!</p>
                    <a href="?page=networks&action=add" class="btn btn-primary" style="margin-top: 20px;">+ Add Network</a>
                </div>
            <?php else: ?>
                <!-- Desktop Table View (hidden on mobile) -->
                <div class="table-wrapper desktop-only">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Network Name</th>
                                <th>Offers</th>
                                <th>Created</th>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($networks as $net): ?>
                            <?php
                            // Count offers using this network
                            $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
                            $offerCount = $db->query("SELECT COUNT(*) as count FROM offers WHERE network_id = {$net['id']}")->fetch_assoc()['count'];
                            $db->close();
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($net['name']) ?></strong></td>
                                <td>
                                    <span class="badge badge-info"><?= $offerCount ?> offers</span>
                                </td>
                                <td><?= date('M d, Y', strtotime($net['created_at'])) ?></td>
                                <td>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <!-- Edit Button -->
                                        <a href="?page=networks&action=edit&id=<?= $net['id'] ?>" 
                                           style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none;"
                                           title="Edit Network"
                                           onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                           onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                            ✏️
                                        </a>
                                        
                                        <!-- Delete Button -->
                                        <form method="post" action="?page=networks&action=delete&id=<?= $net['id'] ?>" 
                                              style="display: inline; margin: 0;" 
                                              onsubmit="return confirm('Are you sure you want to delete this network?\\n\\nThis cannot be undone.');">
                                            <?= Csrf::field() ?>
                                            <button type="submit" 
                                                    style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666;"
                                                    title="Delete Network"
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
                <div class="mobile-network-cards mobile-only">
                    <?php foreach ($networks as $net): ?>
                        <?php
                        // Count offers using this network
                        $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
                        $offerCount = $db->query("SELECT COUNT(*) as count FROM offers WHERE network_id = {$net['id']}")->fetch_assoc()['count'];
                        $db->close();
                        ?>
                        <div class="mobile-network-card" style="background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); padding: var(--spacing-md); margin-bottom: var(--spacing-md); box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                            <!-- Header: Name -->
                            <div style="margin-bottom: var(--spacing-sm); border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: var(--spacing-xs);">
                                <div style="font-weight: 600; font-size: 16px; color: #3d5a26;">
                                    <?= htmlspecialchars($net['name']) ?>
                                </div>
                                <div style="font-size: 11px; color: #666; margin-top: 4px;">
                                    Created: <?= date('M d, Y', strtotime($net['created_at'])) ?>
                                </div>
                            </div>
                            
                            <!-- Offers Count -->
                            <div style="margin-bottom: var(--spacing-sm);">
                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Offers</strong></div>
                                <div style="font-size: 13px;">
                                    <span class="badge badge-info" style="font-size: 11px;"><?= $offerCount ?> <?= $offerCount === 1 ? 'offer' : 'offers' ?></span>
                                </div>
                            </div>
                            
                            <!-- Actions -->
                            <div style="display: flex; gap: 8px; border-top: 1px solid rgba(0,0,0,0.1); padding-top: var(--spacing-sm);">
                                <a href="?page=networks&action=edit&id=<?= $net['id'] ?>" 
                                   style="flex: 1; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; text-decoration: none; color: #666; text-align: center; display: inline-block;">
                                    ✏️ Edit
                                </a>
                                <form method="post" action="?page=networks&action=delete&id=<?= $net['id'] ?>" 
                                      style="flex: 1; margin: 0;" 
                                      onsubmit="return confirm('Are you sure you want to delete this network?\\n\\nThis cannot be undone.');">
                                    <?= Csrf::field() ?>
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
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?= $action === 'edit' ? 'Edit' : 'Add' ?> Network</h2>
            <a href="?page=networks" class="btn btn-secondary">← Back</a>
        </div>
        <div class="card-body">
            <form method="post" action="?page=networks&action=<?= $action ?><?= $id ? "&id={$id}" : '' ?>">
                <?= Csrf::field() ?>
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                        Network Name <span style="color: #d32f2f;">*</span>
                    </label>
                    <input type="text" name="name" value="<?= htmlspecialchars($editNetwork['name'] ?? '') ?>" required
                           placeholder="e.g., ClickBank, MaxBounty, CJ Affiliate"
                           style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Notes</label>
                    <textarea name="notes" rows="3" 
                              style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;"><?= htmlspecialchars($editNetwork['notes'] ?? '') ?></textarea>
                </div>

                <div style="display: flex; gap: 12px;">
                    <button type="submit" class="btn btn-primary">
                        <?= $action === 'edit' ? 'Update' : 'Create' ?> Network
                    </button>
                    <a href="?page=networks" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>


