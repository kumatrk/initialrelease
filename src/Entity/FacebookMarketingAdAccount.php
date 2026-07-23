<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;

/**
 * Facebook Marketing Ad Account Entity
 * Handles CRUD operations for Facebook ad accounts accessible via integrations
 */
class FacebookMarketingAdAccount
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get all ad accounts for a specific integration
     */
    public function getByIntegrationId(int $integrationId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM facebook_marketing_ad_accounts 
             WHERE facebook_marketing_integration_id = ? 
             ORDER BY ad_account_name ASC"
        );
        $stmt->bind_param('i', $integrationId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $accounts = [];
        while ($row = $result->fetch_assoc()) {
            $accounts[] = $row;
        }
        
        return $accounts;
    }

    /**
     * Get all ad accounts across all integrations
     */
    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT faa.*, fmi.name as integration_name, fmi.status as integration_status
             FROM facebook_marketing_ad_accounts faa
             INNER JOIN facebook_marketing_integrations fmi 
                ON faa.facebook_marketing_integration_id = fmi.id
             WHERE fmi.status = 'active'
             ORDER BY faa.ad_account_name ASC"
        );
        
        $accounts = [];
        while ($row = $result->fetch_assoc()) {
            $accounts[] = $row;
        }
        
        return $accounts;
    }

    /**
     * Get a specific ad account by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT faa.*, fmi.name as integration_name
             FROM facebook_marketing_ad_accounts faa
             INNER JOIN facebook_marketing_integrations fmi 
                ON faa.facebook_marketing_integration_id = fmi.id
             WHERE faa.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc() ?: null;
    }

    /**
     * Sync ad accounts for an integration (delete old, insert new)
     */
    public function syncForIntegration(int $integrationId, array $adAccounts): bool
    {
        $this->db->begin_transaction();
        
        try {
            // Delete existing ad accounts for this integration
            $deleteStmt = $this->db->prepare(
                "DELETE FROM facebook_marketing_ad_accounts 
                 WHERE facebook_marketing_integration_id = ?"
            );
            $deleteStmt->bind_param('i', $integrationId);
            $deleteStmt->execute();
            $deleteStmt->close();
            
            // Insert new ad accounts
            // Check if timezone column exists (for backward compatibility)
            $checkColumn = $this->db->query("SHOW COLUMNS FROM facebook_marketing_ad_accounts LIKE 'timezone'");
            $timezoneColumnExists = $checkColumn && $checkColumn->num_rows > 0;
            
            if ($timezoneColumnExists) {
                $insertStmt = $this->db->prepare(
                    "INSERT INTO facebook_marketing_ad_accounts 
                     (facebook_marketing_integration_id, ad_account_id, ad_account_name, account_id, currency, business_id, business_name, timezone, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                );
            } else {
                $insertStmt = $this->db->prepare(
                    "INSERT INTO facebook_marketing_ad_accounts 
                     (facebook_marketing_integration_id, ad_account_id, ad_account_name, account_id, currency, business_id, business_name, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
                );
            }
            
            foreach ($adAccounts as $account) {
                $adAccountId = $account['ad_account_id'] ?? $account['id'] ?? '';
                $adAccountName = $account['ad_account_name'] ?? $account['name'] ?? 'Unknown';
                $accountId = $account['account_id'] ?? null;
                $currency = $account['currency'] ?? null;
                $businessId = $account['business_id'] ?? null;
                $businessName = $account['business_name'] ?? null;
                $timezone = $account['timezone'] ?? null;
                
                if ($timezoneColumnExists) {
                    $insertStmt->bind_param(
                        'isssssss',
                        $integrationId,
                        $adAccountId,
                        $adAccountName,
                        $accountId,
                        $currency,
                        $businessId,
                        $businessName,
                        $timezone
                    );
                } else {
                    $insertStmt->bind_param(
                        'issssss',
                        $integrationId,
                        $adAccountId,
                        $adAccountName,
                        $accountId,
                        $currency,
                        $businessId,
                        $businessName
                    );
                }
                
                if (!$insertStmt->execute()) {
                    throw new \Exception("Failed to insert ad account: " . $this->db->error);
                }
            }
            
            $insertStmt->close();
            $this->db->commit();
            
            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            error_log("Error syncing ad accounts: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete all ad accounts for an integration
     */
    public function deleteByIntegrationId(int $integrationId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM facebook_marketing_ad_accounts 
             WHERE facebook_marketing_integration_id = ?"
        );
        $stmt->bind_param('i', $integrationId);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
}

