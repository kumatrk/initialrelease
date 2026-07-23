<?php
/**
 * Shared data for campaign create wizard partials.
 * Included by views/campaign-create.php
 */

use SimpleKuma\Release\TrafficSourceReleaseHelper;
use SimpleKuma\Utils\Formatter;

if (!function_exists('cc_input')) {
    function cc_input(string $key, $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_scalar($v) ? (string)$v : $default;
    }
}

if (!function_exists('cc_selected')) {
    function cc_selected($a, $b): string
    {
        return (string)$a === (string)$b ? 'selected' : '';
    }
}

if (!function_exists('cc_checked')) {
    function cc_checked($value): string
    {
        return $value ? 'checked' : '';
    }
}

$firstSelectable = TrafficSourceReleaseHelper::getFirstSelectable($trafficSources);
$defaultTrafficSourceId = cc_input('traffic_source_id', (string)($firstSelectable['id'] ?? ''));

$flowType = cc_input('flow_type', 'DTO');

// Offer rows from POST or default
$offerRows = [];
if (!empty($_POST['offer_id']) && is_array($_POST['offer_id'])) {
    foreach ($_POST['offer_id'] as $idx => $oid) {
        $offerRows[] = [
            'id' => $oid,
            'weight' => $_POST['offer_weight'][$idx] ?? 100,
            'enabled' => isset($_POST['offer_enabled'][$idx]) && $_POST['offer_enabled'][$idx] === '1',
        ];
    }
}
if (empty($offerRows)) {
    $offerRows = [['id' => '', 'weight' => 100, 'enabled' => true]];
}

// LP rows
$lpRows = [];
if (!empty($_POST['lp_id']) && is_array($_POST['lp_id'])) {
    foreach ($_POST['lp_id'] as $idx => $lid) {
        $lpRows[] = [
            'id' => $lid,
            'weight' => $_POST['lp_weight'][$idx] ?? 100,
            'enabled' => isset($_POST['lp_enabled'][$idx]) && $_POST['lp_enabled'][$idx] === '1',
        ];
    }
}
if (empty($lpRows)) {
    $lpRows = [['id' => '', 'weight' => 100, 'enabled' => true]];
}

$splitLPPercent = (int)cc_input('split_traffic_to_lp', '50');

$selectedCustomPostbackIds = [];
if (!empty($_POST['custom_postback_ids']) && is_array($_POST['custom_postback_ids'])) {
    $selectedCustomPostbackIds = array_map('intval', $_POST['custom_postback_ids']);
}

// Redirect rule tokens for JS
$builtInTokensForRules = [
    ['name' => 'Location (Country)', 'parameter' => 'country', 'source' => 'Built-in'],
    ['name' => 'State/Region', 'parameter' => 'region', 'source' => 'Built-in'],
    ['name' => 'City', 'parameter' => 'city', 'source' => 'Built-in'],
    ['name' => 'Device', 'parameter' => 'device', 'source' => 'Built-in'],
    ['name' => 'Operating System', 'parameter' => 'os', 'source' => 'Built-in'],
    ['name' => 'Browser', 'parameter' => 'browser', 'source' => 'Built-in'],
];

$trafficSourceTokensForRules = [];
$tsIdForRules = !empty($_POST['traffic_source_id']) ? (int)$_POST['traffic_source_id'] : (int)$defaultTrafficSourceId;
if ($tsIdForRules > 0) {
    foreach ($trafficSources as $ts) {
        if ((int)$ts['id'] === $tsIdForRules) {
            $tsTokens = is_array($ts['tokens_json']) ? $ts['tokens_json'] : json_decode($ts['tokens_json'] ?? '[]', true) ?? [];
            foreach ($tsTokens as $tsToken) {
                if (!empty($tsToken['name']) && !empty($tsToken['parameter'])) {
                    $trafficSourceTokensForRules[] = [
                        'name' => $tsToken['name'],
                        'parameter' => $tsToken['parameter'],
                        'source' => $ts['name'] ?? 'Traffic Source',
                    ];
                }
            }
            break;
        }
    }
}

$customTokensForRules = [];
if (!empty($_POST['custom_token_parameter']) && is_array($_POST['custom_token_parameter'])) {
    foreach ($_POST['custom_token_parameter'] as $idx => $param) {
        if (trim($param) !== '') {
            $customTokensForRules[] = ['name' => trim($_POST['custom_token_name'][$idx] ?? ''), 'parameter' => trim($param)];
        }
    }
}

$wizardRedirectRulesFromPost = [];
if (!empty($_POST['redirect_rule_token']) && is_array($_POST['redirect_rule_token'])) {
    foreach ($_POST['redirect_rule_token'] as $idx => $tokenIdentifier) {
        if (empty($tokenIdentifier)) {
            continue;
        }
        $executeOnKey = 'redirect_rule_execute_on_' . $idx;
        $wizardRedirectRulesFromPost[] = [
            'token_identifier' => (string)$tokenIdentifier,
            'operator' => $_POST['redirect_rule_operator'][$idx] ?? '',
            'value' => $_POST['redirect_rule_value'][$idx] ?? '',
            'redirect_url' => $_POST['redirect_rule_url'][$idx] ?? '',
            'case_sensitive' => isset($_POST['redirect_rule_case_sensitive'][$idx])
                && (string)$_POST['redirect_rule_case_sensitive'][$idx] === '1',
            'execute_on' => $_POST[$executeOnKey] ?? [],
        ];
    }
}

// Traffic source tokens keyed by id for wizard JS
$trafficSourceTokensById = [];
foreach ($trafficSources as $ts) {
    $tsId = (int)($ts['id'] ?? 0);
    if ($tsId <= 0) {
        continue;
    }
    $tsTokens = is_array($ts['tokens_json'] ?? null)
        ? $ts['tokens_json']
        : json_decode($ts['tokens_json'] ?? '[]', true) ?? [];
    $list = [];
    foreach ($tsTokens as $tsToken) {
        if (!empty($tsToken['name']) && !empty($tsToken['parameter'])) {
            $list[] = [
                'name' => $tsToken['name'],
                'parameter' => $tsToken['parameter'],
                'source' => $ts['name'] ?? 'Traffic Source',
            ];
        }
    }
    $trafficSourceTokensById[(string)$tsId] = $list;
}

$tokenCount = max(3, count($_POST['custom_token_name'] ?? []));
