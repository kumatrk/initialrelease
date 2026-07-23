<?php
// Postback URLs & Pixels Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Tracking endpoints (postback.php, pixel.php) live under public/
$defaultTrackingBase = rtrim(APP_BASE_URL, '/');

use SimpleKuma\Entity\TrackingDomain;

// Load verified tracking domains
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$trackingDomains = [];
if (!$db->connect_error) {
    $domainEntity = new TrackingDomain($db);
    $trackingDomains = $domainEntity->getVerified();
    $db->close();
}

$defaultHost = parse_url(BASE_URL, PHP_URL_HOST) ?: 'Current Domain';
?>

<link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/postback-urls.css">

<div class="postbacks-page">
    <div class="page-header">
        <h1 class="page-title">Postback URLs & Pixels</h1>
        <p class="page-description">Global tracking endpoints to integrate with affiliate networks and platforms.</p>
    </div>

    <!-- Postback URL (S2S) -->
    <div class="card postback-page-card">
        <div class="card-header">
            <h2 class="card-title postback-card-title">
                <img src="<?= ASSETS_BASE_URL ?>/assets/images/postbacks.png" alt="">
                Server-to-Server (S2S) Postback URL
            </h2>
        </div>
        <div class="card-body">
            <p class="postback-intro">
                Give this URL to your affiliate networks to send conversion notifications directly to Simple KUMA.
            </p>

            <div class="postback-field">
                <label for="s2s-domain-select" class="postback-field-label">Select Tracking Domain</label>
                <select id="s2s-domain-select" class="postback-select" onchange="updatePostbackUrl('s2s')">
                    <option value="">Default (<?= htmlspecialchars($defaultHost) ?>)</option>
                    <?php if (!empty($trackingDomains)): ?>
                        <?php foreach ($trackingDomains as $domain): ?>
                            <option value="<?= htmlspecialchars($domain['domain']) ?>">
                                <?= htmlspecialchars($domain['domain']) ?> (Verified)
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>No custom domains available</option>
                    <?php endif; ?>
                </select>
                <p class="postback-field-hint">
                    Select a verified custom domain to use for this postback URL, or use the default domain.
                    <?php if (empty($trackingDomains)): ?>
                        <a href="?page=settings&tab=domains">Add custom domains</a>
                    <?php endif; ?>
                </p>
            </div>

            <div class="postback-url-box">
                <div class="postback-url-row">
                    <div class="postback-url-text" id="s2s-url" data-base-url="<?= htmlspecialchars($defaultTrackingBase) ?>">
                        <?= htmlspecialchars($defaultTrackingBase) ?>/postback.php?click_id={click_id}&txid={txid}&payout={payout}&status={status}
                    </div>
                    <button type="button" onclick="copyUrl('s2s-url', this)" class="btn btn-primary postback-copy-btn">
                        Copy
                    </button>
                </div>
            </div>

            <section class="postback-doc-section postback-doc-section--info">
                <h3 class="postback-section-heading postback-section-heading--info">Required Parameters</h3>
                <div class="postback-param-table-wrap">
                    <table class="postback-param-table">
                        <tr>
                            <td>{click_id}</td>
                            <td>Required — The unique click identifier from Simple KUMA</td>
                        </tr>
                        <tr>
                            <td class="is-optional">{txid}</td>
                            <td>Optional — Network transaction ID</td>
                        </tr>
                        <tr>
                            <td class="is-optional">{payout}</td>
                            <td>Optional — Payout amount</td>
                        </tr>
                        <tr>
                            <td class="is-optional">{status}</td>
                            <td>Optional — approved, pending, rejected</td>
                        </tr>
                        <tr>
                            <td class="is-optional">{value}</td>
                            <td>Optional — Sale value</td>
                        </tr>
                        <tr>
                            <td class="is-optional">{event_id}</td>
                            <td>Optional — For deduplication</td>
                        </tr>
                    </table>
                </div>
            </section>

            <section class="postback-doc-section postback-doc-section--warn">
                <h3 class="postback-section-heading postback-section-heading--warn">Example Network Configuration</h3>
                <p class="postback-example-text">
                    Replace <code class="postback-inline-code">{click_id}</code>, <code class="postback-inline-code">{txid}</code>, etc.
                    with your network's actual macro variables.
                </p>
                <div class="postback-network-examples">
                    <div>ClickBank: ...?click_id=<strong>[tid]</strong>&amp;txid=<strong>[receipt]</strong>&amp;payout=<strong>[amount]</strong></div>
                    <div>MaxBounty: ...?click_id=<strong>%%SUBID%%</strong>&amp;txid=<strong>%%TRANSACTION_ID%%</strong>&amp;payout=<strong>%%PAYOUT%%</strong></div>
                </div>
            </section>
        </div>
    </div>

    <!-- Pixel URL (Client-Side) -->
    <div class="card postback-page-card">
        <div class="card-header">
            <h2 class="card-title postback-card-title">Conversion Pixel URL</h2>
        </div>
        <div class="card-body">
            <p class="postback-intro">
                Place this pixel on your thank-you page to track conversions from the client-side.
            </p>

            <div class="postback-field">
                <label for="pixel-domain-select" class="postback-field-label">Select Tracking Domain</label>
                <select id="pixel-domain-select" class="postback-select" onchange="updatePostbackUrl('pixel')">
                    <option value="">Default (<?= htmlspecialchars($defaultHost) ?>)</option>
                    <?php if (!empty($trackingDomains)): ?>
                        <?php foreach ($trackingDomains as $domain): ?>
                            <option value="<?= htmlspecialchars($domain['domain']) ?>">
                                <?= htmlspecialchars($domain['domain']) ?> (Verified)
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>No custom domains available</option>
                    <?php endif; ?>
                </select>
                <p class="postback-field-hint">
                    Select a verified custom domain to use for this pixel URL, or use the default domain.
                    <?php if (empty($trackingDomains)): ?>
                        <a href="?page=settings&tab=domains">Add custom domains</a>
                    <?php endif; ?>
                </p>
            </div>

            <div class="postback-url-box">
                <div class="postback-url-row">
                    <div class="postback-url-text" id="pixel-url" data-base-url="<?= htmlspecialchars($defaultTrackingBase) ?>">
                        <?= htmlspecialchars($defaultTrackingBase) ?>/pixel.php?click_id={click_id}&value={value}&currency={currency}
                    </div>
                    <button type="button" onclick="copyUrl('pixel-url', this)" class="btn btn-primary postback-copy-btn">
                        Copy
                    </button>
                </div>
            </div>

            <section class="postback-doc-section postback-doc-section--info">
                <h3 class="postback-section-heading postback-section-heading--info">Implementation Options</h3>

                <div class="postback-impl-block">
                    <strong class="postback-subheading postback-subheading--info">Option 1: Image Pixel (Recommended)</strong>
                    <div class="postback-code-block" id="pixel-example-1">&lt;img src="<?= htmlspecialchars($defaultTrackingBase) ?>/pixel.php?click_id={click_id}&amp;value=25.00&amp;currency=USD"
     width="1" height="1" style="display:none;" /&gt;</div>
                </div>

                <div class="postback-impl-block">
                    <strong class="postback-subheading postback-subheading--info">Option 2: JavaScript Pixel</strong>
                    <div class="postback-code-block" id="pixel-example-2">&lt;script&gt;
  var img = new Image();
  img.src = '<?= htmlspecialchars($defaultTrackingBase) ?>/pixel.php?click_id={click_id}&value=25.00&currency=USD';
&lt;/script&gt;</div>
                </div>
            </section>

            <section class="postback-doc-section postback-doc-section--neutral">
                <h3 class="postback-section-heading">Dynamic Parameters</h3>
                <div class="postback-param-table-wrap">
                    <table class="postback-param-table">
                        <tr>
                            <td>{click_id}</td>
                            <td>Required — Pass this from your tracking link via URL parameter</td>
                        </tr>
                        <tr>
                            <td class="is-optional">{value}</td>
                            <td>Optional — Conversion value/sale amount</td>
                        </tr>
                        <tr>
                            <td class="is-optional">{currency}</td>
                            <td>Optional — Currency code (default: USD)</td>
                        </tr>
                        <tr>
                            <td class="is-optional">{event_id}</td>
                            <td>Optional — For deduplication</td>
                        </tr>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <!-- Test Endpoints -->
    <div class="card postback-page-card">
        <div class="card-header">
            <h2 class="card-title postback-card-title">Test Your Integration</h2>
        </div>
        <div class="card-body">
            <p class="postback-intro">
                Use these test links to verify your postback integration is working correctly.
            </p>

            <div class="postback-test-grid">
                <div class="postback-test-card">
                    <strong class="postback-test-card-title">Test S2S Postback</strong>
                    <a href="<?= htmlspecialchars($defaultTrackingBase) ?>/postback.php?click_id=test-123&txid=test-txid&payout=25.00&status=approved"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="btn btn-outline"
                       id="test-s2s-link">
                        Fire Test Postback
                    </a>
                    <div class="postback-test-card-note">Opens in new tab — check the response</div>
                </div>

                <div class="postback-test-card">
                    <strong class="postback-test-card-title">Test Pixel</strong>
                    <a href="<?= htmlspecialchars($defaultTrackingBase) ?>/pixel.php?click_id=test-456&value=50.00&currency=USD&format=json"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="btn btn-outline"
                       id="test-pixel-link">
                        Fire Test Pixel
                    </a>
                    <div class="postback-test-card-note">Returns JSON response</div>
                </div>
            </div>

            <section class="postback-doc-section postback-doc-section--warn postback-test-note-section">
                <h3 class="postback-section-heading postback-section-heading--warn">Note</h3>
                <p class="postback-note-text">
                    These test links will fail because click_id "test-123" doesn't exist in the database.
                    Use a real click_id from an actual campaign click to test properly.
                </p>
            </section>
        </div>
    </div>
</div>

<script>
    const baseUrl = <?= json_encode($defaultTrackingBase, JSON_UNESCAPED_SLASHES) ?>;

    function copyUrl(elementId, buttonElement) {
        const element = document.getElementById(elementId);
        const url = element.textContent.trim();
        const originalText = buttonElement.textContent;
        const originalBg = buttonElement.style.background;
        const originalBorder = buttonElement.style.border;

        function showCopied() {
            buttonElement.textContent = 'Copied!';
            buttonElement.style.background = '#4caf50';
            buttonElement.style.border = '1px solid #4caf50';
            buttonElement.disabled = true;
            setTimeout(function () {
                buttonElement.textContent = originalText;
                buttonElement.style.background = originalBg;
                buttonElement.style.border = originalBorder;
                buttonElement.disabled = false;
            }, 2000);
        }

        function showFailed() {
            buttonElement.textContent = 'Copy failed';
            buttonElement.style.background = '#f44336';
            setTimeout(function () {
                buttonElement.textContent = originalText;
                buttonElement.style.background = originalBg;
            }, 2000);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(showCopied).catch(function () {
                fallbackCopy(url, showCopied, showFailed);
            });
        } else {
            fallbackCopy(url, showCopied, showFailed);
        }
    }

    function fallbackCopy(url, onSuccess, onFail) {
        const textArea = document.createElement('textarea');
        textArea.value = url;
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            onSuccess();
        } catch (err) {
            onFail();
        }
        document.body.removeChild(textArea);
    }

    function updatePostbackUrl(type) {
        const selectId = type === 's2s' ? 's2s-domain-select' : 'pixel-domain-select';
        const urlElementId = type === 's2s' ? 's2s-url' : 'pixel-url';
        const select = document.getElementById(selectId);
        const urlElement = document.getElementById(urlElementId);

        if (!select || !urlElement) return;

        const selectedDomain = select.value;
        const domainBase = selectedDomain || baseUrl;

        if (type === 's2s') {
            urlElement.textContent = domainBase + '/postback.php?click_id={click_id}&txid={txid}&payout={payout}&status={status}';
            const testLink = document.getElementById('test-s2s-link');
            if (testLink) {
                testLink.href = domainBase + '/postback.php?click_id=test-123&txid=test-txid&payout=25.00&status=approved';
            }
        } else {
            urlElement.textContent = domainBase + '/pixel.php?click_id={click_id}&value={value}&currency={currency}';

            const example1 = document.getElementById('pixel-example-1');
            const example2 = document.getElementById('pixel-example-2');
            if (example1) {
                example1.textContent = '<img src="' + domainBase + '/pixel.php?click_id={click_id}&value=25.00&currency=USD"\n     width="1" height="1" style="display:none;" />';
            }
            if (example2) {
                example2.textContent = '<script>\n  var img = new Image();\n  img.src = \'' + domainBase + '/pixel.php?click_id={click_id}&value=25.00&currency=USD\';\n<\/script>';
            }

            const testLink = document.getElementById('test-pixel-link');
            if (testLink) {
                testLink.href = domainBase + '/pixel.php?click_id=test-456&value=50.00&currency=USD&format=json';
            }
        }
    }
</script>
