<?php
/**
 * Advanced wizard step: custom tokens, redirect rules, slugs.
 * Markup matches the campaign editor optional accordions (chevrons + theme tokens).
 */
$assetsBase = defined('ASSETS_BASE_URL') ? ASSETS_BASE_URL : '';
?>
<h3 class="wizard-step-title">Advanced (optional)</h3>
<p class="wizard-step-desc">Custom tokens, redirect rules, and tracking slugs.</p>

<details class="campaign-optional-details">
    <summary>
        <img src="<?= htmlspecialchars($assetsBase . '/assets/images/customtokensbear.png', ENT_QUOTES, 'UTF-8') ?>" alt="">
        Custom Campaign Tokens (Optional)
    </summary>
    <div class="campaign-optional-details__body">
        <p class="campaign-optional-details__hint">
            Define tokens here first. They appear in redirect rules below without saving the campaign.
        </p>
        <div id="custom_tokens_container">
            <?php for ($i = 0; $i < $tokenCount; $i++):
                $tName = $_POST['custom_token_name'][$i] ?? '';
                $tParam = $_POST['custom_token_parameter'][$i] ?? '';
                $tPlace = $_POST['custom_token_placeholder'][$i] ?? '';
            ?>
            <div class="custom-token-row" style="display: grid; grid-template-columns: 60px 1fr 1fr 1fr auto; gap: 8px; margin-bottom: 8px; align-items: center;">
                <div class="campaign-form-hint" style="text-align: center; font-weight: 600; margin: 0;">Token <?= $i + 1 ?></div>
                <input type="text" name="custom_token_name[]" class="campaign-form-input" value="<?= htmlspecialchars($tName) ?>" placeholder="Name">
                <input type="text" name="custom_token_parameter[]" class="campaign-form-input" value="<?= htmlspecialchars($tParam) ?>" placeholder="sub1">
                <input type="text" name="custom_token_placeholder[]" class="campaign-form-input" value="<?= htmlspecialchars($tPlace) ?>" placeholder="{value}">
                <button type="button" class="btn btn-outline" onclick="removeCustomTokenRow(this)">×</button>
            </div>
            <?php endfor; ?>
        </div>
        <button type="button" class="btn btn-outline" onclick="addCustomTokenRow()" style="margin-top: 8px;">+ Add Token</button>
    </div>
</details>

<details class="campaign-optional-details">
    <summary>
        <img src="<?= htmlspecialchars($assetsBase . '/assets/images/redirectrulesbear.png', ENT_QUOTES, 'UTF-8') ?>" alt="">
        Redirect Rules (Optional)
    </summary>
    <div class="campaign-optional-details__body">
        <p class="campaign-optional-details__hint">
            Rules use custom tokens from above and tokens from your selected traffic source. No need to save first.
        </p>
        <div id="redirect_rules_container"></div>
        <button type="button" class="btn btn-outline" onclick="addRedirectRule()" style="margin-top: 8px;">+ Add Redirect Rule</button>
        <p class="campaign-form-hint" style="margin-top: 8px;">
            Rules are evaluated in order. The first matching rule redirects traffic to the specified URL.
        </p>
    </div>
</details>

<details class="campaign-optional-details">
    <summary>
        <span aria-hidden="true">🔗</span>
        Campaign Slugs (Optional)
    </summary>
    <div class="campaign-optional-details__body">
        <p class="campaign-optional-details__hint">
            Create multiple tracking slugs for this campaign. Each slug routes to the same campaign with a unique tracking link.
        </p>
        <div id="slug-items-container">
            <div class="slug-row" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label class="campaign-form-label" for="wizard_slug_0">Slug</label>
                    <input type="text" name="slug[]" id="wizard_slug_0" class="slug-input campaign-form-input" placeholder="fb-ad-1" oninput="validateSlugInput(this)" style="font-family:monospace;">
                    <div class="slug-error" style="font-size:11px;color:#d32f2f;display:none;"></div>
                </div>
                <div>
                    <label class="campaign-form-label" for="wizard_slug_label_0">Label</label>
                    <input type="text" name="slug_label[]" id="wizard_slug_label_0" class="campaign-form-input" placeholder="Facebook Ad 1">
                </div>
                <div style="display:flex;align-items:flex-end;">
                    <button type="button" class="btn btn-outline" onclick="addSlugRow()">+ Add</button>
                </div>
            </div>
        </div>
    </div>
</details>

<script>
window.redirectRuleTokens = {
    custom: <?= json_encode(array_map(fn($t) => ['name' => $t['name'] ?? '', 'parameter' => $t['parameter'] ?? ''], $customTokensForRules)) ?>,
    builtIn: <?= json_encode($builtInTokensForRules) ?>,
    trafficSource: <?= json_encode($trafficSourceTokensForRules) ?>
};
window.trafficSourceTokensById = <?= json_encode($trafficSourceTokensById) ?>;
window.wizardRedirectRulesFromPost = <?= json_encode($wizardRedirectRulesFromPost) ?>;
</script>
