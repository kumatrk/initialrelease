<?php
/**
 * Advanced wizard step: custom tokens, redirect rules, slugs.
 */
?>
<h3 class="wizard-step-title">Advanced (optional)</h3>
<p class="wizard-step-desc">Custom tokens, redirect rules, and tracking slugs.</p>

<details open style="margin-bottom: 20px;">
    <summary style="cursor: pointer; font-weight: 600; padding: 8px;">Custom Campaign Tokens</summary>
    <div style="padding: 12px 0;">
        <p style="font-size: 12px; color: #666; margin: 0 0 12px 0;">
            Define tokens here first. They appear in redirect rules below without saving the campaign.
        </p>
        <div id="custom_tokens_container">
            <?php for ($i = 0; $i < $tokenCount; $i++):
                $tName = $_POST['custom_token_name'][$i] ?? '';
                $tParam = $_POST['custom_token_parameter'][$i] ?? '';
                $tPlace = $_POST['custom_token_placeholder'][$i] ?? '';
            ?>
            <div class="custom-token-row" style="display: grid; grid-template-columns: 60px 1fr 1fr 1fr auto; gap: 8px; margin-bottom: 8px; align-items: center;">
                <div style="text-align: center; font-weight: 600; color: #666;">Token <?= $i + 1 ?></div>
                <input type="text" name="custom_token_name[]" value="<?= htmlspecialchars($tName) ?>" placeholder="Name" style="padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                <input type="text" name="custom_token_parameter[]" value="<?= htmlspecialchars($tParam) ?>" placeholder="sub1" style="padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                <input type="text" name="custom_token_placeholder[]" value="<?= htmlspecialchars($tPlace) ?>" placeholder="{value}" style="padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                <button type="button" class="btn btn-outline" onclick="removeCustomTokenRow(this)">×</button>
            </div>
            <?php endfor; ?>
        </div>
        <button type="button" class="btn btn-outline" onclick="addCustomTokenRow()" style="margin-top: 8px;">+ Add Token</button>
    </div>
</details>

<details open style="margin-bottom: 20px;">
    <summary style="cursor: pointer; font-weight: 600; padding: 8px;">Redirect Rules</summary>
    <div style="padding: 12px 0;">
        <p style="font-size: 12px; color: #666; margin: 0 0 12px 0;">
            Rules use custom tokens from above and tokens from your selected traffic source. No need to save first.
        </p>
        <div id="redirect_rules_container"></div>
        <button type="button" class="btn btn-outline" onclick="addRedirectRule()" style="margin-top: 8px;">+ Add Redirect Rule</button>
        <div style="font-size: 12px; color: #666; margin-top: 8px;">
            Rules are evaluated in order. The first matching rule redirects traffic to the specified URL.
        </div>
    </div>
</details>

<details style="margin-bottom: 20px;">
    <summary style="cursor: pointer; font-weight: 600; padding: 8px;">Campaign Slugs</summary>
    <div style="padding: 12px 0;">
        <div id="slug-items-container">
            <div class="slug-row" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 600; font-size: 13px;">Slug</label>
                    <input type="text" name="slug[]" class="slug-input" placeholder="fb-ad-1" oninput="validateSlugInput(this)"
                           style="width:100%;padding:8px;border:2px solid #ddd;border-radius:4px;font-family:monospace;">
                    <div class="slug-error" style="font-size:11px;color:#d32f2f;display:none;"></div>
                </div>
                <div>
                    <label style="font-weight: 600; font-size: 13px;">Label</label>
                    <input type="text" name="slug_label[]" placeholder="Facebook Ad 1" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:4px;">
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
