<?php
/**
 * Campaign creation wizard (parallel to classic ?page=campaign-list&action=add)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
require_once __DIR__ . '/../src/Release/TrafficSourceReleaseHelper.php';
require_once __DIR__ . '/../src/Campaign/CampaignFormParser.php';

use SimpleKuma\Auth\Permission;
use SimpleKuma\Campaign\CampaignFormParser;
use SimpleKuma\Release\TrafficSourceReleaseHelper;
use SimpleKuma\Utils\Formatter;

$pageTitle = 'Create Campaign';
$permission = $GLOBALS['permission'] ?? null;
$legacyNoRoles = empty($_SESSION['role_ids'] ?? []) && \SimpleKuma\Auth\Auth::allowsLegacyNoRolesFallback();

if ($permission && !$permission->hasPermission(Permission::PERM_CAMPAIGN_CREATE) && !$legacyNoRoles) {
    echo '<div class="card"><div class="card-body"><p>You do not have permission to create campaigns.</p>';
    echo '<a href="?page=campaign-list" class="btn btn-secondary">Back to Campaigns</a></div></div>';
    return;
}

$db = $GLOBALS['db'] ?? new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$userCurrency = $GLOBALS['userCurrency'] ?? 'USD';

$campaign = new \SimpleKuma\Entity\Campaign($db);
$trafficSource = new \SimpleKuma\Entity\TrafficSource($db);
$offer = new \SimpleKuma\Entity\Offer($db);
$landingPage = new \SimpleKuma\Entity\LandingPage($db);
$campaignGroup = new \SimpleKuma\Entity\CampaignGroup($db);
$facebookCapi = new \SimpleKuma\Entity\FacebookCapiIntegration($db);
$customPostback = new \SimpleKuma\Entity\CustomPostback($db);
$trackingDomain = new \SimpleKuma\Entity\TrackingDomain($db);

$trafficSources = $trafficSource->getAll();
$offers = $offer->getAll();
$landingPages = $landingPage->getAll();
$campaignGroups = $campaignGroup->getAll();
$facebookIntegrations = $facebookCapi->getAll();
require_once __DIR__ . '/../src/Entity/GoogleAdsIntegration.php';
$googleAds = new \SimpleKuma\Entity\GoogleAdsIntegration($db);
$googleAdsIntegrations = $googleAds->getAll();
require_once __DIR__ . '/../src/Entity/FacebookMarketingAdAccount.php';
$facebookAdAccountEntity = new \SimpleKuma\Entity\FacebookMarketingAdAccount($db);
$allFacebookAdAccounts = $facebookAdAccountEntity->getAll();
$allCustomPostbacks = $customPostback->getAll();
$verifiedTrackingDomains = $trackingDomain->getVerified();

$errors = [];
$success = '';
$initialStep = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_campaign'])) {
    $parsed = CampaignFormParser::fromPost($_POST, $campaign, $trafficSource, true, null);
    $errors = $parsed['errors'];
    $data = $parsed['data'];

    if (empty($errors)) {
        try {
            $newId = $campaign->create($data);
            if ($newId > 0) {
                $customPostback->setForCampaign($newId, $parsed['custom_postback_ids']);

                $campaignSlug = new \SimpleKuma\Entity\CampaignSlug($db);
                foreach ($parsed['slugs']['slug'] as $idx => $slug) {
                    $slug = trim($slug);
                    $slugLabel = trim($parsed['slugs']['slug_label'][$idx] ?? '');
                    if ($slug === '' || $slugLabel === '') {
                        continue;
                    }
                    try {
                        $campaignSlug->create($newId, $slug, $slugLabel);
                    } catch (\Throwable $e) {
                        $errors['slugs'][] = "Slug '{$slug}': " . $e->getMessage();
                    }
                }

                if (empty($errors)) {
                    header('Location: ?page=campaign-list&action=edit&id=' . $newId . '&success=created');
                    exit;
                }
            } else {
                $errors['general'] = 'Failed to create campaign.';
            }
        } catch (\Throwable $e) {
            $errors['general'] = 'Error creating campaign: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $initialStep = 1;
        if (!empty($errors['traffic_source_id']) || !empty($errors['google_ads_integration_id'])) {
            $initialStep = 2;
        } elseif (!empty($errors['rotation']) || !empty($errors['flow_type'])) {
            $initialStep = 3;
        } elseif (!empty($errors['slugs']) || !empty($errors['redirect_rules'])) {
            $initialStep = 4;
        }
    }
}

require __DIR__ . '/campaign-create/_data.php';

$wizardCssPath = __DIR__ . '/../public/assets/css/campaign-create-wizard.css';
$wizardJsPath = __DIR__ . '/../public/assets/js/campaign-create-wizard.js';
$extraCss = ASSETS_BASE_URL . '/assets/css/campaign-create-wizard.css?v=' . (file_exists($wizardCssPath) ? filemtime($wizardCssPath) : '1');
$extraJs = ASSETS_BASE_URL . '/assets/js/campaign-create-wizard.js?v=' . (file_exists($wizardJsPath) ? filemtime($wizardJsPath) : '1');
?>

<link rel="stylesheet" href="<?= htmlspecialchars($extraCss) ?>">

<div class="page-header">
    <h1 class="page-title">Create Campaign</h1>
    <p class="page-description">Step-by-step campaign setup. Prefer the full form? <a href="?page=campaign-list&action=add">Classic creator</a></p>
</div>

<?php if (!empty($errors)): ?>
<div style="background:#f8d7da;color:#721c24;padding:15px;border-radius:4px;margin-bottom:20px;border-left:4px solid #d32f2f;">
    <strong>Please fix the following:</strong><br>
    <?php foreach ($errors as $field => $error): ?>
        <?php if (is_array($error)): ?>
            <?php foreach ($error as $msg): ?>• <?= htmlspecialchars($msg) ?><br><?php endforeach; ?>
        <?php else: ?>
            • <?= htmlspecialchars($error) ?><br>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card campaign-wizard-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2 class="card-title" style="margin:0;">New campaign wizard</h2>
        <span id="wizard-progress-pct" class="wizard-progress-pct" aria-live="polite">0%</span>
    </div>
    <div class="card-body">
        <div class="wizard-progress-track" aria-hidden="true">
            <div class="wizard-progress-fill" id="wizard-progress-fill"></div>
        </div>
        <nav class="campaign-wizard-progress" aria-label="Wizard progress">
            <div class="campaign-wizard-progress-step is-active" data-step="1" data-step-num="1"><span class="step-label">Basics</span></div>
            <div class="campaign-wizard-progress-step" data-step="2" data-step-num="2"><span class="step-label">Traffic</span></div>
            <div class="campaign-wizard-progress-step" data-step="3" data-step-num="3"><span class="step-label">Flow</span></div>
            <div class="campaign-wizard-progress-step" data-step="4" data-step-num="4"><span class="step-label">Advanced</span></div>
            <div class="campaign-wizard-progress-step" data-step="5" data-step-num="5"><span class="step-label">Review</span></div>
        </nav>

        <form method="post" action="?page=campaign-create" id="campaign-create-form" novalidate>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="save_campaign" value="1">

            <div class="campaign-wizard-steps">
                <section class="wizard-step is-active" data-step="1" aria-hidden="false"><?php include __DIR__ . '/campaign-create/step-basics.php'; ?></section>
                <section class="wizard-step" data-step="2" aria-hidden="true"><?php include __DIR__ . '/campaign-create/step-traffic.php'; ?></section>
                <section class="wizard-step" data-step="3" aria-hidden="true"><?php include __DIR__ . '/campaign-create/step-flow.php'; ?></section>
                <section class="wizard-step" data-step="4" aria-hidden="true"><?php include __DIR__ . '/campaign-create/step-advanced.php'; ?></section>
                <section class="wizard-step" data-step="5" aria-hidden="true"><?php include __DIR__ . '/campaign-create/step-review.php'; ?></section>
            </div>
        </form>
    </div>

    <div class="campaign-wizard-nav" role="navigation" aria-label="Wizard actions">
        <a href="?page=campaign-list" class="btn btn-secondary">Cancel</a>
        <div class="btn-group-right">
            <button type="button" class="btn btn-secondary wizard-nav-btn--hidden" id="wizard-btn-back" aria-hidden="true">← Back</button>
            <button type="button" class="btn btn-primary" id="wizard-btn-next">Next →</button>
            <button type="submit" class="btn btn-primary wizard-nav-btn--hidden" id="wizard-btn-submit" aria-hidden="true"
                    form="campaign-create-form" name="save_campaign" value="1">✨ Create Campaign</button>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars($extraJs) ?>"></script>
<?php
$fbPickerJsPath = __DIR__ . '/../public/assets/js/facebook-campaign-picker.js';
$fbPickerJs = ASSETS_BASE_URL . '/assets/js/facebook-campaign-picker.js?v=' . (file_exists($fbPickerJsPath) ? filemtime($fbPickerJsPath) : '1');
?>
<script src="<?= htmlspecialchars($fbPickerJs) ?>"></script>
<script>
document.body.setAttribute('data-initial-step', '<?= (int)$initialStep ?>');
document.addEventListener('DOMContentLoaded', function () {
    if (window.FacebookCampaignPicker) {
        window.FacebookCampaignPicker.init({
            selectedCampaignId: <?= json_encode(cc_input('facebook_marketing_campaign_id')) ?>,
        });
    }
});
</script>
