<?php
// Landing Pages CRUD Page
require_once __DIR__ . '/../config/config.php';

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
$landingPage = new \SimpleKuma\Entity\LandingPage($db);

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$errors = [];
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'delete') {
        $blockReason = $landingPage->getDeleteBlockReason((int)$id);
        if ($blockReason !== null) {
            $errors['general'] = $blockReason;
            $action = 'list';
        } elseif ($landingPage->delete($id)) {
            $success = 'Landing page deleted successfully';
            $action = 'list';
        } else {
            $errors['general'] = 'Failed to delete landing page';
        }
    } else {
        $data = [
            'name' => $_POST['name'] ?? '',
            'url' => $_POST['url'] ?? '',
            'notes' => $_POST['notes'] ?? '',
        ];

        $errors = $landingPage->validate($data);

        if (empty($errors)) {
            if ($action === 'edit' && $id) {
                if ($landingPage->update($id, $data)) {
                    $success = 'Landing page updated successfully';
                    $action = 'list';
                } else {
                    $errors['general'] = 'Failed to update landing page';
                }
            } else {
                if ($landingPage->create($data)) {
                    $success = 'Landing page created successfully';
                    $action = 'list';
                } else {
                    $errors['general'] = 'Failed to create landing page';
                }
            }
        }
    }
}

$editLP = null;
if ($action === 'edit' && $id) {
    $editLP = $landingPage->getById($id);
}

$db->close();
?>

<div class="page-header">
    <h1 class="page-title">Landing Pages</h1>
    <p class="page-description">Manage your pre-sell landing pages.</p>
</div>

<?php if ($success): ?>
<div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #28a745;">
    ✅ <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Your Landing Pages</h2>
            <a href="?page=landing-pages&action=add" class="btn btn-primary">+ Add Landing Page</a>
        </div>
        <div class="card-body">
            <?php
            $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            $landingPage = new \SimpleKuma\Entity\LandingPage($db);
            $pages = $landingPage->getAll();

            $rotationRef = new \SimpleKuma\Campaign\CampaignRotationReference($db);
            $campaignsByLp = $rotationRef->getCampaignsByLandingPageMap();
            $campaignCounts = [];
            foreach ($campaignsByLp as $lpId => $campaigns) {
                $campaignCounts[$lpId] = count($campaigns);
            }

            $db->close();
            ?>

            <?php if (empty($pages)): ?>
                <div style="text-align: center; padding: 60px; color: #999;">
                    <img src="<?= ASSETS_BASE_URL ?>/assets/images/landingpages.png" alt="Landing Pages" style="width: 64px; height: 64px; margin-bottom: 16px;">
                    <p>No landing pages yet. Add one to use in your campaigns!</p>
                    <a href="?page=landing-pages&action=add" class="btn btn-primary" style="margin-top: 20px;">+ Add Landing Page</a>
                </div>
            <?php else: ?>
                <!-- Desktop Table View (hidden on mobile) -->
                <div class="table-wrapper desktop-only">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>URL</th>
                                <th>Notes</th>
                                <th style="text-align: center;">Campaigns</th>
                                <th>Created</th>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pages as $page): ?>
                            <?php $campaignCount = $campaignCounts[$page['id']] ?? 0; ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($page['name']) ?></strong></td>
                                <td style="max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; font-family: monospace;">
                                    <a href="<?= htmlspecialchars($page['url']) ?>" target="_blank" style="color: #3d5a26;">
                                        <?= htmlspecialchars($page['url']) ?> 🔗
                                    </a>
                                </td>
                                <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; color: #666;">
                                    <?= $page['notes'] ? htmlspecialchars($page['notes']) : '-' ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($campaignCount > 0): ?>
                                        <span class="badge badge-info" 
                                              style="cursor: pointer; transition: all 0.2s;"
                                              onclick="showCampaignsModal(<?= $page['id'] ?>, '<?= htmlspecialchars($page['name'], ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($campaignsByLp[$page['id']] ?? []), ENT_QUOTES) ?>)"
                                              onmouseover="this.style.opacity='0.8'; this.style.transform='scale(1.05)'"
                                              onmouseout="this.style.opacity='1'; this.style.transform='scale(1)'"
                                              title="Click to view campaigns">
                                            <?= $campaignCount ?> <?= $campaignCount === 1 ? 'campaign' : 'campaigns' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-info" style="opacity: 0.5;">0 campaigns</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('M d, Y', strtotime($page['created_at'])) ?></td>
                                <td>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <!-- Edit Button -->
                                        <a href="?page=landing-pages&action=edit&id=<?= $page['id'] ?>" 
                                           style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none;"
                                           title="Edit Landing Page"
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
                                        <form method="post" action="?page=landing-pages&action=delete&id=<?= $page['id'] ?>" 
                                              style="display: inline; margin: 0;" 
                                              onsubmit="return confirm('Are you sure you want to delete this landing page?\\n\\nThis cannot be undone.');">
                                            <button type="submit" 
                                                    style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666;"
                                                    title="Delete Landing Page"
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
                <div class="mobile-landing-page-cards mobile-only">
                    <?php foreach ($pages as $page): ?>
                        <?php $campaignCount = $campaignCounts[$page['id']] ?? 0; ?>
                        <div class="mobile-landing-page-card" style="background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); padding: var(--spacing-md); margin-bottom: var(--spacing-md); box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                            <!-- Header: Name -->
                            <div style="margin-bottom: var(--spacing-sm); border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: var(--spacing-xs);">
                                <div style="font-weight: 600; font-size: 16px; color: #3d5a26;">
                                    <?= htmlspecialchars($page['name']) ?>
                                </div>
                                <div style="font-size: 11px; color: #666; margin-top: 4px;">
                                    Created: <?= date('M d, Y', strtotime($page['created_at'])) ?>
                                </div>
                            </div>
                            
                            <!-- URL -->
                            <div style="margin-bottom: var(--spacing-sm);">
                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>URL</strong></div>
                                <div style="font-size: 10px; font-family: monospace; color: #3d5a26; word-break: break-all; background: #f9f9f9; padding: 6px; border-radius: 3px;">
                                    <a href="<?= htmlspecialchars($page['url']) ?>" target="_blank" style="color: #3d5a26; text-decoration: none;">
                                        <?= htmlspecialchars($page['url']) ?> 🔗
                                    </a>
                                </div>
                            </div>
                            
                            <!-- Notes -->
                            <?php if ($page['notes']): ?>
                            <div style="margin-bottom: var(--spacing-sm);">
                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Notes</strong></div>
                                <div style="font-size: 12px; color: #666; background: #f9f9f9; padding: 6px; border-radius: 3px;">
                                    <?= htmlspecialchars($page['notes']) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Campaigns -->
                            <div style="margin-bottom: var(--spacing-sm);">
                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Campaigns</strong></div>
                                <div style="font-size: 13px;">
                                    <?php if ($campaignCount > 0): ?>
                                        <span class="badge badge-info" 
                                              style="cursor: pointer; transition: all 0.2s; font-size: 11px;"
                                              onclick="showCampaignsModal(<?= $page['id'] ?>, '<?= htmlspecialchars($page['name'], ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($campaignsByLp[$page['id']] ?? []), ENT_QUOTES) ?>)"
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
                                <a href="?page=landing-pages&action=edit&id=<?= $page['id'] ?>" 
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
                                <form method="post" action="?page=landing-pages&action=delete&id=<?= $page['id'] ?>" 
                                      style="flex: 1; margin: 0;" 
                                      onsubmit="return confirm('Are you sure you want to delete this landing page?\\n\\nThis cannot be undone.');">
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
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?= $action === 'edit' ? 'Edit' : 'Add' ?> Landing Page</h2>
            <a href="?page=landing-pages" class="btn btn-secondary">← Back</a>
        </div>
        <div class="card-body">
            <form method="post" action="?page=landing-pages&action=<?= $action ?><?= $id ? "&id={$id}" : '' ?>">
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                        Landing Page Name <span style="color: #d32f2f;">*</span>
                    </label>
                    <input type="text" name="name" value="<?= htmlspecialchars($editLP['name'] ?? '') ?>" required
                           placeholder="e.g., Health VSL Page"
                           style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                        Landing Page URL <span style="color: #d32f2f;">*</span>
                    </label>
                    <input type="url" name="url" value="<?= htmlspecialchars($editLP['url'] ?? '') ?>" required
                           placeholder="https://yourdomain.com/landing-page"
                           style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                    <div style="font-size: 12px; color: #666; margin-top: 4px;">
                        💡 <strong>Tip:</strong> LP click tracking scripts are provided in the campaign editor (Tracking Links) after you attach this page to a campaign.
                    </div>
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Notes</label>
                    <textarea name="notes" rows="3" 
                              style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;"><?= htmlspecialchars($editLP['notes'] ?? '') ?></textarea>
                </div>

                <div style="display: flex; gap: 12px;">
                    <button type="submit" class="btn btn-primary">
                        <?= $action === 'edit' ? 'Update' : 'Create' ?> Landing Page
                    </button>
                    <a href="?page=landing-pages" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
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

