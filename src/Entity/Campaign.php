<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;
use SimpleKuma\Tracking\ClickPath;

/**
 * Campaign Entity
 * Handles CRUD operations for campaigns
 */
class Campaign
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT c.*, ts.name as traffic_source_name, td.domain as tracking_domain, 
                    cg.name as campaign_group_name
             FROM campaigns c
             LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id
             LEFT JOIN tracking_domains td ON c.tracking_domain_id = td.id
             LEFT JOIN campaign_groups cg ON c.campaign_group_id = cg.id
             ORDER BY c.created_at DESC"
        );

        $campaigns = [];
        while ($row = $result->fetch_assoc()) {
            $row['rotation_json'] = $row['rotation_json'] ? json_decode($row['rotation_json'], true) : [];
            $row['pass_through_json'] = $row['pass_through_json'] ? json_decode($row['pass_through_json'], true) : [];
            $row['facebook_capi_json'] = $row['facebook_capi_json'] ? json_decode($row['facebook_capi_json'], true) : [];
            $row['custom_tokens_json'] = $row['custom_tokens_json'] ? json_decode($row['custom_tokens_json'], true) : [];
            $row['redirect_rules_json'] = $row['redirect_rules_json'] ? json_decode($row['redirect_rules_json'], true) : [];
            $row['traffic_source_postbacks_json'] = isset($row['traffic_source_postbacks_json']) && $row['traffic_source_postbacks_json'] ? json_decode($row['traffic_source_postbacks_json'], true) : [];
            $campaigns[] = $row;
        }

        return $campaigns;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, ts.name as traffic_source_name, td.domain as tracking_domain,
                    cg.name as campaign_group_name
             FROM campaigns c
             LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id
             LEFT JOIN tracking_domains td ON c.tracking_domain_id = td.id
             LEFT JOIN campaign_groups cg ON c.campaign_group_id = cg.id
             WHERE c.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            $row['rotation_json'] = $row['rotation_json'] ? json_decode($row['rotation_json'], true) : [];
            $row['pass_through_json'] = $row['pass_through_json'] ? json_decode($row['pass_through_json'], true) : [];
            $row['facebook_capi_json'] = $row['facebook_capi_json'] ? json_decode($row['facebook_capi_json'], true) : [];
            $row['custom_tokens_json'] = $row['custom_tokens_json'] ? json_decode($row['custom_tokens_json'], true) : [];
            $row['redirect_rules_json'] = $row['redirect_rules_json'] ? json_decode($row['redirect_rules_json'], true) : [];
            $row['traffic_source_postbacks_json'] = isset($row['traffic_source_postbacks_json']) && $row['traffic_source_postbacks_json'] ? json_decode($row['traffic_source_postbacks_json'], true) : [];
        }

        return $row ?: null;
    }

    public function create(array $data): int
    {
        // Generate unique campaign key
        $campaignKey = $this->generateUniqueCampaignKey();

        $rotationJson = $this->safeJsonEncode($data['rotation'] ?? []);
        $passThroughJson = $this->safeJsonEncode($data['pass_through'] ?? []);
        $facebookCapiIntegrationId = !empty($data['facebook_capi_integration_id']) ? (int)$data['facebook_capi_integration_id'] : null;
        $facebookMarketingIntegrationId = !empty($data['facebook_marketing_integration_id']) ? (int)$data['facebook_marketing_integration_id'] : null;
        $facebookMarketingAdAccountId = !empty($data['facebook_marketing_ad_account_id']) ? (int)$data['facebook_marketing_ad_account_id'] : null;
        $facebookMarketingCampaignId = !empty($data['facebook_marketing_campaign_id']) ? (int)$data['facebook_marketing_campaign_id'] : null;
        $googleAdsIntegrationId = !empty($data['google_ads_integration_id'])
            ? (int)$data['google_ads_integration_id']
            : null;
        $trafficSourcePostbacksJson = $this->safeJsonEncode($data['traffic_source_postbacks'] ?? []);
        $customTokensJson = $this->safeJsonEncode($data['custom_tokens'] ?? []);
        $redirectRulesJson = $this->safeJsonEncode($data['redirect_rules'] ?? []);
        $campaignGroupId = !empty($data['campaign_group_id']) ? (int)$data['campaign_group_id'] : null;
        $trackingDomainId = !empty($data['tracking_domain_id']) ? (int)$data['tracking_domain_id'] : null;
        $defaultCpc = !empty($data['default_cpc']) ? (float)$data['default_cpc'] : null;
        $trafficSourceId = !empty($data['traffic_source_id']) ? (int)$data['traffic_source_id'] : null;
        $fallbackOfferId = !empty($data['fallback_offer_id']) ? (int)$data['fallback_offer_id'] : null;

        if ($facebookMarketingAdAccountId !== null) {
            $this->validateFacebookMarketingAdAccount($facebookMarketingAdAccountId);
        }
        $this->applyFacebookMarketingLinkage(
            $facebookMarketingIntegrationId,
            $facebookMarketingAdAccountId,
            $facebookMarketingCampaignId
        );
        $this->validateFacebookMarketingIntegration($facebookMarketingIntegrationId);
        
        // Validate Google Ads integration if provided
        if ($googleAdsIntegrationId !== null) {
            $this->validateGoogleAdsIntegration($googleAdsIntegrationId);
        }

        $redirectlessTracking = !empty($data['redirectless_tracking']) ? 1 : 0;
        
        $referrerMode = (string) ($data['referrer_mode'] ?? $data['cloaking_mode'] ?? '');
        
        // Defensive: ensure required fields have defaults (avoids undefined array key issues)
        $name = $data['name'] ?? '';
        $flowType = $data['flow_type'] ?? 'DTO';
        $status = $data['status'] ?? 'active';
        
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('=== CAMPAIGN CREATE ===');
            error_log('Rotation data (array): ' . print_r($data['rotation'] ?? [], true));
            error_log('Rotation JSON (encoded): ' . $rotationJson);
            error_log('Rotation JSON length: ' . strlen($rotationJson));
            error_log('Traffic Source ID: ' . ($trafficSourceId ?? 'NULL'));
            error_log('Referrer mode: ' . ($referrerMode ?: 'EMPTY'));
        }

        // Final safety: MySQL JSON columns reject empty string - ensure all JSON vars are valid
        $rotationJson = $this->ensureValidJson($rotationJson);
        $passThroughJson = $this->ensureValidJson($passThroughJson);
        $trafficSourcePostbacksJson = $this->ensureValidJson($trafficSourcePostbacksJson);
        $customTokensJson = $this->ensureValidJson($customTokensJson);
        $redirectRulesJson = $this->ensureValidJson($redirectRulesJson);

        // CRITICAL: pass_through is not implemented in UI - always use empty array to avoid MySQL JSON errors
        $passThroughJson = '[]';

        $hasFallbackOfferColumn = $this->campaignsTableHasFallbackOfferId();
        $hasMetaCampaignColumn = $this->campaignsTableHasFacebookMarketingCampaignId();

        if ($hasFallbackOfferColumn && $hasMetaCampaignColumn) {
            $stmt = $this->db->prepare(
                "INSERT INTO campaigns 
                (campaign_key, name, campaign_group_id, traffic_source_id, flow_type, rotation_json, fallback_offer_id, tracking_domain_id, 
                 referrer_mode, redirectless_tracking, pass_through_json, facebook_capi_integration_id, facebook_marketing_integration_id, facebook_marketing_ad_account_id, facebook_marketing_campaign_id, google_ads_integration_id, traffic_source_postbacks_json, custom_tokens_json, redirect_rules_json, 
                 status, default_cpc, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $bindTypes = 'ssiissiisisiiiiissssd';
            $bindVars = [
                $campaignKey, $name, $campaignGroupId, $trafficSourceId, $flowType, $rotationJson,
                $fallbackOfferId, $trackingDomainId, $referrerMode, $redirectlessTracking, $passThroughJson,
                $facebookCapiIntegrationId, $facebookMarketingIntegrationId, $facebookMarketingAdAccountId,
                $facebookMarketingCampaignId,
                $googleAdsIntegrationId, $trafficSourcePostbacksJson, $customTokensJson, $redirectRulesJson,
                $status, $defaultCpc
            ];
        } elseif ($hasFallbackOfferColumn) {
            $stmt = $this->db->prepare(
                "INSERT INTO campaigns 
                (campaign_key, name, campaign_group_id, traffic_source_id, flow_type, rotation_json, fallback_offer_id, tracking_domain_id, 
                 referrer_mode, redirectless_tracking, pass_through_json, facebook_capi_integration_id, facebook_marketing_integration_id, facebook_marketing_ad_account_id, google_ads_integration_id, traffic_source_postbacks_json, custom_tokens_json, redirect_rules_json, 
                 status, default_cpc, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $bindTypes = 'ssiissiisisiiiissssd';
            $bindVars = [
                $campaignKey, $name, $campaignGroupId, $trafficSourceId, $flowType, $rotationJson,
                $fallbackOfferId, $trackingDomainId, $referrerMode, $redirectlessTracking, $passThroughJson,
                $facebookCapiIntegrationId, $facebookMarketingIntegrationId, $facebookMarketingAdAccountId,
                $googleAdsIntegrationId, $trafficSourcePostbacksJson, $customTokensJson, $redirectRulesJson,
                $status, $defaultCpc
            ];
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO campaigns 
                (campaign_key, name, campaign_group_id, traffic_source_id, flow_type, rotation_json, tracking_domain_id, 
                 referrer_mode, redirectless_tracking, pass_through_json, facebook_capi_integration_id, facebook_marketing_integration_id, facebook_marketing_ad_account_id, google_ads_integration_id, traffic_source_postbacks_json, custom_tokens_json, redirect_rules_json, 
                 status, default_cpc, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $bindTypes = 'ssiissisisiiiissssd';
            $bindVars = [
                $campaignKey, $name, $campaignGroupId, $trafficSourceId, $flowType, $rotationJson,
                $trackingDomainId, $referrerMode, $redirectlessTracking, $passThroughJson,
                $facebookCapiIntegrationId, $facebookMarketingIntegrationId, $facebookMarketingAdAccountId,
                $googleAdsIntegrationId, $trafficSourcePostbacksJson, $customTokensJson, $redirectRulesJson,
                $status, $defaultCpc
            ];
        }
        
        if ($stmt === false) {
            error_log('Campaign CREATE prepare failed: ' . $this->db->error);
            throw new \RuntimeException('Database error: ' . $this->db->error);
        }
        
        if (strlen($bindTypes) !== count($bindVars)) {
            error_log('ERROR: Bind types (' . strlen($bindTypes) . ') != bind vars (' . count($bindVars) . ')');
            throw new \RuntimeException('Bind parameter count mismatch: types=' . strlen($bindTypes) . ', vars=' . count($bindVars));
        }
        
        $stmt->bind_param($bindTypes, ...$bindVars);

        if (!$stmt->execute()) {
            $sqlError = $stmt->error ?: $this->db->error;
            error_log('Campaign CREATE SQL Error: ' . $sqlError);
            throw new \RuntimeException($sqlError);
        }

        $newId = $stmt->insert_id;
        if ($newId > 0) {
            $this->saveMinPostbackPayout((int)$newId, $data);
        }
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('Campaign created with ID: ' . $newId);
        }
        
        // Verify what was actually saved (debug only)
        if ($newId > 0 && defined('DEBUG_MODE') && DEBUG_MODE) {
            $verifyStmt = $this->db->prepare("SELECT rotation_json FROM campaigns WHERE id = ?");
            $verifyStmt->bind_param('i', $newId);
            $verifyStmt->execute();
            $verifyResult = $verifyStmt->get_result();
            $verifyRow = $verifyResult->fetch_assoc();
            if ($verifyRow) {
                error_log('Rotation JSON saved in DB: ' . ($verifyRow['rotation_json'] ?? 'NULL'));
                error_log('Rotation JSON length in DB: ' . strlen($verifyRow['rotation_json'] ?? ''));
            }
        }
        
        return $newId;
    }

    /**
     * Ensure string is valid JSON for MySQL JSON columns. Rejects empty string and invalid JSON.
     */
    private function ensureValidJson(string $json): string
    {
        $trimmed = trim($json);
        if ($trimmed === '') {
            return '[]';
        }
        json_decode($trimmed);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return '[]';
        }
        return $trimmed;
    }

    /**
     * Encode value as JSON for MySQL JSON columns. Ensures valid JSON is always returned.
     * MySQL JSON columns reject empty string - use "[]" as fallback.
     */
    private function safeJsonEncode(mixed $value): string
    {
        // Force arrays/objects - if it's already a string, validate it's JSON
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || !in_array($trimmed[0] ?? '', ['[', '{', '"', '-', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 't', 'f', 'n'])) {
                return '[]';
            }
            return $trimmed;
        }
        $json = json_encode($value);
        if ($json === false || $json === '' || !is_string($json)) {
            return '[]';
        }
        return $json;
    }

    /**
     * Check if campaigns table has fallback_offer_id column (migration 056)
     */
    private function campaignsTableHasFallbackOfferId(): bool
    {
        $result = $this->db->query("SHOW COLUMNS FROM campaigns LIKE 'fallback_offer_id'");
        return $result && $result->num_rows > 0;
    }

    private function campaignsTableHasFacebookMarketingCampaignId(): bool
    {
        $result = $this->db->query("SHOW COLUMNS FROM campaigns LIKE 'facebook_marketing_campaign_id'");
        return $result && $result->num_rows > 0;
    }

    private function campaignsTableHasMinPostbackPayout(): bool
    {
        $result = $this->db->query("SHOW COLUMNS FROM campaigns LIKE 'min_postback_payout'");
        return $result && $result->num_rows > 0;
    }

    /**
     * Optional minimum payout to fire outbound postbacks (null = no filter).
     */
    private function parseMinPostbackPayout(array $data): ?float
    {
        if (!array_key_exists('min_postback_payout', $data)) {
            return null;
        }
        $raw = $data['min_postback_payout'];
        if ($raw === null || $raw === '') {
            return null;
        }
        $val = (float)$raw;
        return $val >= 0 ? $val : null;
    }

    private function saveMinPostbackPayout(int $campaignId, array $data): void
    {
        if (!$this->campaignsTableHasMinPostbackPayout()) {
            if (array_key_exists('min_postback_payout', $data)
                && $data['min_postback_payout'] !== null
                && $data['min_postback_payout'] !== ''
            ) {
                error_log(
                    'Campaign min_postback_payout not saved: column missing on campaigns table '
                    . '(run migration 058_add_min_postback_payout_to_campaigns.sql). campaign_id=' . $campaignId
                );
            }
            return;
        }
        $minPayout = $this->parseMinPostbackPayout($data);
        if ($minPayout === null) {
            $stmt = $this->db->prepare('UPDATE campaigns SET min_postback_payout = NULL WHERE id = ?');
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('i', $campaignId);
        } else {
            $stmt = $this->db->prepare('UPDATE campaigns SET min_postback_payout = ? WHERE id = ?');
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('di', $minPayout, $campaignId);
        }
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Auto-set marketing integration from ad account; validate Meta campaign belongs to ad account.
     */
    private function applyFacebookMarketingLinkage(
        ?int &$facebookMarketingIntegrationId,
        ?int &$facebookMarketingAdAccountId,
        ?int &$facebookMarketingCampaignId
    ): void {
        if ($facebookMarketingAdAccountId === null) {
            $facebookMarketingCampaignId = null;
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT facebook_marketing_integration_id FROM facebook_marketing_ad_accounts WHERE id = ?'
        );
        $stmt->bind_param('i', $facebookMarketingAdAccountId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && !empty($row['facebook_marketing_integration_id'])) {
            $facebookMarketingIntegrationId = (int)$row['facebook_marketing_integration_id'];
        }

        if ($facebookMarketingCampaignId === null) {
            return;
        }

        if (!$this->campaignsTableHasFacebookMarketingCampaignId()) {
            $facebookMarketingCampaignId = null;
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM facebook_marketing_campaigns
             WHERE id = ? AND facebook_marketing_ad_account_id = ?'
        );
        $stmt->bind_param('ii', $facebookMarketingCampaignId, $facebookMarketingAdAccountId);
        $stmt->execute();
        $valid = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$valid) {
            throw new \RuntimeException('Selected Meta campaign does not belong to the chosen ad account');
        }
    }

    /**
     * Generate unique campaign key
     */
    private function generateUniqueCampaignKey(): string
    {
        $attempts = 0;
        $maxAttempts = 10;

        do {
            // Generate 8-character alphanumeric key (case-sensitive for more combinations)
            $key = $this->generateRandomKey(8);
            
            // Check if unique (campaign_key and custom slugs share /km/ routing)
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM campaigns WHERE campaign_key = ?");
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $keyTaken = (int)($result['count'] ?? 0) > 0;

            if (!$keyTaken) {
                $slugStmt = $this->db->prepare("SELECT COUNT(*) as count FROM campaign_slugs WHERE slug = ?");
                if ($slugStmt) {
                    $slugStmt->bind_param('s', $key);
                    $slugStmt->execute();
                    $slugResult = $slugStmt->get_result()->fetch_assoc();
                    $keyTaken = (int)($slugResult['count'] ?? 0) > 0;
                    $slugStmt->close();
                }
            }

            if (!$keyTaken) {
                return $key;
            }
            
            $attempts++;
        } while ($attempts < $maxAttempts);

        // Fallback to timestamp-based key if collision persists
        return substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }

    /**
     * Generate random alphanumeric key
     */
    private function generateRandomKey(int $length = 8): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $key = '';
        $max = strlen($characters) - 1;

        for ($i = 0; $i < $length; $i++) {
            $key .= $characters[random_int(0, $max)];
        }

        return $key;
    }

    /**
     * Validate that a Facebook Marketing integration exists and is active
     * 
     * @param int|null $integrationId The integration ID to validate
     * @return bool True if valid (or null), false if invalid
     * @throws \RuntimeException If integration doesn't exist or is not active
     */
    private function validateFacebookMarketingIntegration(?int $integrationId): bool
    {
        // Null is allowed (optional field)
        if ($integrationId === null) {
            return true;
        }

        // Check if integration exists and is active
        $stmt = $this->db->prepare(
            "SELECT id, status FROM facebook_marketing_integrations WHERE id = ?"
        );
        $stmt->bind_param('i', $integrationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $integration = $result->fetch_assoc();

        if (!$integration) {
            error_log("Campaign validation failed: Facebook Marketing integration ID {$integrationId} does not exist");
            throw new \RuntimeException("Facebook Marketing integration not found");
        }

        if ($integration['status'] !== 'active') {
            error_log("Campaign validation failed: Facebook Marketing integration ID {$integrationId} is not active (status: {$integration['status']})");
            throw new \RuntimeException("Facebook Marketing integration is not active");
        }

        return true;
    }

    /**
     * Validate that a Facebook Marketing ad account exists
     * 
     * @param int|null $adAccountId The ad account ID to validate
     * @return bool True if valid (or null), false if invalid
     * @throws \RuntimeException If ad account doesn't exist
     */
    private function validateFacebookMarketingAdAccount(?int $adAccountId): bool
    {
        // Null is allowed (optional field)
        if ($adAccountId === null) {
            return true;
        }

        // Check if ad account exists (table doesn't have a status column)
        $stmt = $this->db->prepare(
            "SELECT id FROM facebook_marketing_ad_accounts WHERE id = ?"
        );
        $stmt->bind_param('i', $adAccountId);
        $stmt->execute();
        $result = $stmt->get_result();
        $adAccount = $result->fetch_assoc();

        if (!$adAccount) {
            error_log("Campaign validation failed: Facebook Marketing ad account ID {$adAccountId} does not exist");
            throw new \RuntimeException("Facebook Marketing ad account not found");
        }

        return true;
    }

    /**
     * Validate that a Google Ads integration exists
     * 
     * @param int|null $integrationId The integration ID to validate
     * @return bool True if valid (or null), false if invalid
     * @throws \RuntimeException If integration doesn't exist
     */
    private function validateGoogleAdsIntegration(?int $integrationId): bool
    {
        // Null is allowed (optional field)
        if ($integrationId === null) {
            return true;
        }

        // Check if integration exists
        $stmt = $this->db->prepare(
            "SELECT id FROM google_ads_integrations WHERE id = ?"
        );
        $stmt->bind_param('i', $integrationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $integration = $result->fetch_assoc();

        if (!$integration) {
            error_log("Campaign validation failed: Google Ads integration ID {$integrationId} does not exist");
            throw new \RuntimeException("Google Ads integration not found");
        }

        return true;
    }

    public function update(int $id, array $data): bool
    {
        // FIRST: Check if existing campaign has an invalid google_ads_integration_id and clear it if needed
        $checkStmt = $this->db->prepare("SELECT traffic_source_id, google_ads_integration_id FROM campaigns WHERE id = ?");
        $checkStmt->bind_param('i', $id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $existingCampaign = $checkResult->fetch_assoc();
        
        if ($existingCampaign && !empty($existingCampaign['google_ads_integration_id'])) {
            // Check if the existing value is valid
            $existingGoogleAdsId = (int)$existingCampaign['google_ads_integration_id'];
            $validateStmt = $this->db->prepare("SELECT id FROM google_ads_integrations WHERE id = ?");
            $validateStmt->bind_param('i', $existingGoogleAdsId);
            $validateStmt->execute();
            $validateResult = $validateStmt->get_result();
            $isValid = $validateResult->fetch_assoc() !== null;
            
            if (!$isValid) {
                // Existing value is invalid - clear it immediately
                error_log("WARNING: Campaign {$id} has invalid google_ads_integration_id ({$existingGoogleAdsId}). Clearing it.");
                $clearStmt = $this->db->prepare("UPDATE campaigns SET google_ads_integration_id = NULL WHERE id = ?");
                $clearStmt->bind_param('i', $id);
                $clearStmt->execute();
                $clearStmt->close();
            }
            $validateStmt->close();
        }
        $checkStmt->close();
        
        // SQL UPDATE with 19 placeholders:
        // 1.name(s) 2.campaign_group_id(i) 3.traffic_source_id(i) 4.flow_type(s) 5.rotation_json(s)
        // 6.tracking_domain_id(i) 7.referrer_mode(s) 8.redirectless_tracking(i) 9.pass_through_json(s)
        // 10.facebook_capi_integration_id(i) 11.facebook_marketing_integration_id(i) 12.facebook_marketing_ad_account_id(i) 13.google_ads_integration_id(i)
        // 14.traffic_source_postbacks_json(s) 15.custom_tokens_json(s) 16.redirect_rules_json(s) 17.status(s)
        // 18.default_cpc(d) 19.id(i)
        $hasMetaCampaignColumn = $this->campaignsTableHasFacebookMarketingCampaignId();
        $updateSql = "UPDATE campaigns 
            SET name = ?, campaign_group_id = ?, traffic_source_id = ?, flow_type = ?, rotation_json = ?, fallback_offer_id = ?,
                tracking_domain_id = ?, referrer_mode = ?, redirectless_tracking = ?, pass_through_json = ?, 
                facebook_capi_integration_id = ?, facebook_marketing_integration_id = ?, facebook_marketing_ad_account_id = ?";
        if ($hasMetaCampaignColumn) {
            $updateSql .= ", facebook_marketing_campaign_id = ?";
        }
        $updateSql .= ", google_ads_integration_id = ?, traffic_source_postbacks_json = ?, custom_tokens_json = ?, redirect_rules_json = ?, status = ?, 
                default_cpc = ?, updated_at = NOW()
            WHERE id = ?";
        $stmt = $this->db->prepare($updateSql);

        $rotationJson = $this->safeJsonEncode($data['rotation'] ?? []);
        $passThroughJson = $this->ensureValidJson($this->safeJsonEncode($data['pass_through'] ?? []));
        // pass_through UI not implemented — always persist empty array for valid MySQL JSON
        $passThroughJson = '[]';
        $facebookCapiIntegrationId = !empty($data['facebook_capi_integration_id']) ? (int)$data['facebook_capi_integration_id'] : null;
        $facebookMarketingIntegrationId = !empty($data['facebook_marketing_integration_id']) ? (int)$data['facebook_marketing_integration_id'] : null;
        $facebookMarketingAdAccountId = !empty($data['facebook_marketing_ad_account_id']) ? (int)$data['facebook_marketing_ad_account_id'] : null;
        $facebookMarketingCampaignId = !empty($data['facebook_marketing_campaign_id']) ? (int)$data['facebook_marketing_campaign_id'] : null;
        
        $trafficSourceId = !empty($data['traffic_source_id']) ? (int)$data['traffic_source_id'] : null;
        $googleAdsIntegrationId = !empty($data['google_ads_integration_id'])
            ? (int)$data['google_ads_integration_id']
            : null;

        if ($googleAdsIntegrationId !== null) {
            $this->validateGoogleAdsIntegration($googleAdsIntegrationId);
        }
        
        $trafficSourcePostbacksJson = $this->safeJsonEncode($data['traffic_source_postbacks'] ?? []);
        $customTokensJson = $this->safeJsonEncode($data['custom_tokens'] ?? []);
        $redirectRulesJson = $this->safeJsonEncode($data['redirect_rules'] ?? []);
        
        if ($facebookMarketingAdAccountId !== null) {
            $this->validateFacebookMarketingAdAccount($facebookMarketingAdAccountId);
        }
        $this->applyFacebookMarketingLinkage(
            $facebookMarketingIntegrationId,
            $facebookMarketingAdAccountId,
            $facebookMarketingCampaignId
        );
        $this->validateFacebookMarketingIntegration($facebookMarketingIntegrationId);

        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('=== CAMPAIGN UPDATE ===');
            error_log("Final google_ads_integration_id value: " . ($googleAdsIntegrationId ?? 'NULL'));
            error_log('Custom tokens in data: ' . print_r($data['custom_tokens'] ?? [], true));
            error_log('Custom tokens JSON (type: ' . gettype($customTokensJson) . ', length: ' . strlen($customTokensJson) . '): ' . $customTokensJson);
            error_log('Redirect rules in data: ' . print_r($data['redirect_rules'] ?? [], true));
            error_log('Redirect rules JSON (type: ' . gettype($redirectRulesJson) . ', length: ' . strlen($redirectRulesJson) . '): ' . $redirectRulesJson);
        }
        
        $trackingDomainId = !empty($data['tracking_domain_id']) ? (int)$data['tracking_domain_id'] : null;
        $defaultCpc = !empty($data['default_cpc']) ? (float)$data['default_cpc'] : null;
        $campaignGroupId = !empty($data['campaign_group_id']) ? (int)$data['campaign_group_id'] : null;
        $fallbackOfferId = !empty($data['fallback_offer_id']) ? (int)$data['fallback_offer_id'] : null;
        // Note: $trafficSourceId is already set above when determining Google Ads integration

        $redirectlessTracking = !empty($data['redirectless_tracking']) ? 1 : 0;

        // Ensure JSON strings are valid
        if ($customTokensJson === false || $customTokensJson === null) {
            error_log('ERROR: json_encode failed for custom_tokens: ' . json_last_error_msg());
            $customTokensJson = '[]';
        }
        if ($redirectRulesJson === false || $redirectRulesJson === null) {
            error_log('ERROR: json_encode failed for redirect_rules: ' . json_last_error_msg());
            $redirectRulesJson = '[]';
        }
        
        $bindVars = [
            $data['name'],
            $campaignGroupId,
            $trafficSourceId,
            $data['flow_type'],
            $rotationJson,
            $fallbackOfferId,
            $trackingDomainId,
            (string) ($data['referrer_mode'] ?? $data['cloaking_mode'] ?? ''),
            $redirectlessTracking,
            $passThroughJson,
            $facebookCapiIntegrationId,
            $facebookMarketingIntegrationId,
            $facebookMarketingAdAccountId,
        ];
        if ($hasMetaCampaignColumn) {
            $bindVars[] = $facebookMarketingCampaignId;
        }
        $bindVars = array_merge($bindVars, [
            $googleAdsIntegrationId,
            $trafficSourcePostbacksJson,
            $customTokensJson,
            $redirectRulesJson,
            $data['status'],
            $defaultCpc,
            $id,
        ]);
        $bindTypes = ($hasMetaCampaignColumn ? 'siissiisisiiiiissssdi' : 'siissiisisiiiissssdi');
        if (strlen($bindTypes) !== count($bindVars)) {
            throw new \RuntimeException('bindTypes length mismatch: types=' . strlen($bindTypes) . ', vars=' . count($bindVars));
        }
        
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('DEBUG UPDATE: bindTypes length: ' . strlen($bindTypes));
            error_log('DEBUG UPDATE: bindVars count: ' . count($bindVars));
            error_log('DEBUG UPDATE: bindTypes string: ' . $bindTypes);
        }
        
        if (strlen($bindTypes) !== count($bindVars)) {
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log('ERROR: Bind types (' . strlen($bindTypes) . ') != bind vars (' . count($bindVars) . ')');
                error_log('ERROR: bindVars: ' . print_r(array_map(function($v) { return gettype($v) . ':' . (is_string($v) ? substr($v, 0, 50) : (string)$v); }, $bindVars), true));
            }
            throw new \RuntimeException('Bind parameter count mismatch: types=' . strlen($bindTypes) . ', vars=' . count($bindVars));
        }
        
        $googleIdx = $hasMetaCampaignColumn ? 14 : 13;
        $postbacksIdx = $googleIdx + 1;
        $customTokensIdx = $googleIdx + 2;
        $redirectRulesIdx = $googleIdx + 3;

        if (!is_string($bindVars[$postbacksIdx])) {
            $bindVars[$postbacksIdx] = json_encode($bindVars[$postbacksIdx]);
        }
        if (!is_string($bindVars[$customTokensIdx])) {
            $bindVars[$customTokensIdx] = json_encode($bindVars[$customTokensIdx]);
        }
        if (!is_string($bindVars[$redirectRulesIdx])) {
            $bindVars[$redirectRulesIdx] = json_encode($bindVars[$redirectRulesIdx]);
        }
        
        $stmt->bind_param($bindTypes, ...$bindVars);

        $result = $stmt->execute();
        
        if ($result) {
            $this->saveMinPostbackPayout($id, $data);
        }
        
        if (!$result) {
            error_log('Campaign UPDATE SQL Error: ' . $stmt->error);
            error_log('Campaign UPDATE Error Info: ' . print_r($stmt->error_list ?? [], true));
        } else if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('Campaign UPDATE successful for ID: ' . $id);
            // Verify what was actually saved - use CAST to see raw value
            $verifyStmt = $this->db->prepare("SELECT CAST(custom_tokens_json AS CHAR(10000)) as custom_tokens_raw, CAST(redirect_rules_json AS CHAR(10000)) as redirect_rules_raw, custom_tokens_json, redirect_rules_json FROM campaigns WHERE id = ?");
            $verifyStmt->bind_param('i', $id);
            $verifyStmt->execute();
            $verifyResult = $verifyStmt->get_result();
            $verifyRow = $verifyResult->fetch_assoc();
            if ($verifyRow) {
                error_log('Custom tokens JSON saved in DB (raw): ' . ($verifyRow['custom_tokens_raw'] ?? 'NULL'));
                error_log('Custom tokens JSON saved in DB (JSON column): ' . (is_string($verifyRow['custom_tokens_json']) ? substr($verifyRow['custom_tokens_json'], 0, 200) : var_export($verifyRow['custom_tokens_json'], true)));
                error_log('Redirect rules JSON saved in DB (raw): ' . ($verifyRow['redirect_rules_raw'] ?? 'NULL'));
                error_log('Redirect rules JSON saved in DB (JSON column): ' . (is_string($verifyRow['redirect_rules_json']) ? substr($verifyRow['redirect_rules_json'], 0, 200) : var_export($verifyRow['redirect_rules_json'], true)));
            }
        }
        
        return $result;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM campaigns WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    public function validate(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Campaign name is required';
        }

        // Traffic source is optional - NULL or 0 means auto-detect mode
        // No validation needed - it's optional

        if (empty($data['flow_type'])) {
            $errors['flow_type'] = 'Flow type is required';
        } elseif (!in_array($data['flow_type'], ['DTO', 'LP', 'Split'], true)) {
            $errors['flow_type'] = 'Invalid flow type';
        }

        // Validate rotation has at least one destination
        if (empty($data['rotation'])) {
            $errors['rotation'] = 'At least one destination (offer or landing page) is required';
        } elseif ($data['flow_type'] === 'LP') {
            // For LP flow, check both landing pages and offers (only enabled ones)
            $enabledLPs = array_filter($data['rotation']['landing_pages'] ?? [], function($lp) {
                return !isset($lp['enabled']) || $lp['enabled'] !== false;
            });
            $enabledOffers = array_filter($data['rotation']['offers'] ?? [], function($offer) {
                return !isset($offer['enabled']) || $offer['enabled'] !== false;
            });
            
            if (empty($enabledLPs)) {
                $errors['rotation'] = 'At least one enabled landing page is required';
            } elseif (empty($enabledOffers)) {
                $errors['rotation'] = 'At least one enabled offer is required';
            } else {
                // Validate LP weights (only for enabled LPs)
                $lpWeight = 0;
                foreach ($enabledLPs as $lp) {
                    $lpWeight += (int)($lp['weight'] ?? 0);
                }
                // Validate offer weights (only for enabled offers)
                $offerWeight = 0;
                foreach ($enabledOffers as $offer) {
                    $offerWeight += (int)($offer['weight'] ?? 0);
                }
                if ($lpWeight !== 100) {
                    $errors['rotation'] = 'Enabled landing page weights must sum to 100%';
                } elseif ($offerWeight !== 100) {
                    $errors['rotation'] = 'Enabled offer weights must sum to 100%';
                }
            }
        } elseif ($data['flow_type'] === 'DTO' && is_array($data['rotation'])) {
            // For DTO, rotation is direct array of offers (only enabled ones)
            $enabledOffers = array_filter($data['rotation'], function($item) {
                return !isset($item['enabled']) || $item['enabled'] !== false;
            });
            
            if (empty($enabledOffers)) {
                $errors['rotation'] = 'At least one enabled offer is required';
            } else {
                $totalWeight = 0;
                foreach ($enabledOffers as $item) {
                    $totalWeight += (int)($item['weight'] ?? 0);
                }
                if ($totalWeight !== 100) {
                    $errors['rotation'] = 'Enabled offer weights must sum to 100%';
                }
            }
        } elseif ($data['flow_type'] === 'Split' && is_array($data['rotation'])) {
            // For Split, check both paths' offer weights and LP weights (only enabled ones)
            $lpPathOffers = array_filter($data['rotation']['lp_path']['offers'] ?? [], function($offer) {
                return !isset($offer['enabled']) || $offer['enabled'] !== false;
            });
            $directPathOffers = array_filter($data['rotation']['direct_path']['offers'] ?? [], function($offer) {
                return !isset($offer['enabled']) || $offer['enabled'] !== false;
            });
            $lpPathLPs = array_filter($data['rotation']['lp_path']['landing_pages'] ?? [], function($lp) {
                return !isset($lp['enabled']) || $lp['enabled'] !== false;
            });
            
            // Check that at least one enabled offer exists
            if (empty($lpPathOffers) && empty($directPathOffers)) {
                $errors['rotation'] = 'At least one enabled offer is required';
            }
            // Check that at least one enabled LP exists in LP path
            if (empty($lpPathLPs) && !empty($data['rotation']['split_traffic']['lp_percent']) && (int)$data['rotation']['split_traffic']['lp_percent'] > 0) {
                $errors['rotation'] = 'At least one enabled landing page is required for LP path';
            }
            
            if (empty($errors['rotation'])) {
                // Validate LP path offer weights if offers exist
                if (!empty($lpPathOffers)) {
                    $lpOfferWeight = 0;
                    foreach ($lpPathOffers as $offer) {
                        $lpOfferWeight += (int)($offer['weight'] ?? 0);
                    }
                    if ($lpOfferWeight !== 100) {
                        $errors['rotation'] = 'Enabled LP path offer weights must sum to 100%';
                    }
                }
                
                // Validate direct path offer weights
                if (!empty($directPathOffers)) {
                    $directOfferWeight = 0;
                    foreach ($directPathOffers as $offer) {
                        $directOfferWeight += (int)($offer['weight'] ?? 0);
                    }
                    if ($directOfferWeight !== 100) {
                        $errors['rotation'] = 'Enabled direct path offer weights must sum to 100%';
                    }
                }
                
                // Validate LP path landing page weights
                if (!empty($lpPathLPs)) {
                    $lpPathLPWeight = 0;
                    foreach ($lpPathLPs as $lp) {
                        $lpPathLPWeight += (int)($lp['weight'] ?? 0);
                    }
                    if ($lpPathLPWeight !== 100) {
                        $errors['rotation'] = 'Enabled LP path landing page weights must sum to 100%';
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Generate tracking link for campaign
     */
    public function generateTrackingLink(string $campaignKey, ?string $domain = null): string
    {
        if ($domain !== null) {
            $base = preg_match('#^https?://#i', $domain) ? rtrim($domain, '/') : 'https://' . rtrim($domain, '/');
        } else {
            $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : 'https://localhost';
        }

        return ClickPath::url($base, $campaignKey);
    }

    /**
     * Get campaign by key
     */
    public function getByKey(string $key): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, ts.name as traffic_source_name, td.domain as tracking_domain,
                    cg.name as campaign_group_name
             FROM campaigns c
             LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id
             LEFT JOIN tracking_domains td ON c.tracking_domain_id = td.id
             LEFT JOIN campaign_groups cg ON c.campaign_group_id = cg.id
             WHERE c.campaign_key = ?"
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            $row['rotation_json'] = $row['rotation_json'] ? json_decode($row['rotation_json'], true) : [];
            $row['pass_through_json'] = $row['pass_through_json'] ? json_decode($row['pass_through_json'], true) : [];
            $row['facebook_capi_json'] = $row['facebook_capi_json'] ? json_decode($row['facebook_capi_json'], true) : [];
            $row['custom_tokens_json'] = $row['custom_tokens_json'] ? json_decode($row['custom_tokens_json'], true) : [];
            $row['redirect_rules_json'] = $row['redirect_rules_json'] ? json_decode($row['redirect_rules_json'], true) : [];
        }

        return $row ?: null;
    }
}

