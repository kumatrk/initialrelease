<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;

/**
 * Campaign Manager
 * Handles CRUD operations for campaigns
 */
class CampaignManager
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT c.*, ts.name as traffic_source_name, td.domain as tracking_domain
             FROM campaigns c
             LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id
             LEFT JOIN tracking_domains td ON c.tracking_domain_id = td.id
             ORDER BY c.created_at DESC"
        );

        if (!$result) {
            return [];
        }

        $campaigns = [];
        while ($row = $result->fetch_assoc()) {
            $row['rotation_json'] = json_decode($row['rotation_json'] ?? '{}', true);
            $row['pass_through_json'] = json_decode($row['pass_through_json'] ?? '{}', true);
            $row['facebook_capi_json'] = json_decode($row['facebook_capi_json'] ?? '{}', true);
            $campaigns[] = $row;
        }

        return $campaigns;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, ts.name as traffic_source_name, td.domain as tracking_domain
             FROM campaigns c
             LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id
             LEFT JOIN tracking_domains td ON c.tracking_domain_id = td.id
             WHERE c.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $campaign = $result->fetch_assoc();

        if ($campaign) {
            $campaign['rotation_json'] = json_decode($campaign['rotation_json'] ?? '{}', true);
            $campaign['pass_through_json'] = json_decode($campaign['pass_through_json'] ?? '{}', true);
            $campaign['facebook_capi_json'] = json_decode($campaign['facebook_capi_json'] ?? '{}', true);
        }

        return $campaign ?: null;
    }

    public function create(array $data): int|false
    {
        $rotationJson = json_encode($data['rotation'] ?? []);
        $passThroughJson = json_encode($data['pass_through'] ?? []);
        $facebookCapiJson = json_encode($data['facebook_capi'] ?? []);
        
        $stmt = $this->db->prepare(
            "INSERT INTO campaigns 
             (name, traffic_source_id, flow_type, rotation_json, tracking_domain_id, 
              referrer_mode, pass_through_json, facebook_capi_json, timezone, currency, 
              status, default_cpc, created_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        
        $trackingDomainId = !empty($data['tracking_domain_id']) ? (int)$data['tracking_domain_id'] : null;
        $defaultCpc = !empty($data['default_cpc']) ? (float)$data['default_cpc'] : null;
        
        $stmt->bind_param(
            'sissississsd',
            $data['name'],
            $data['traffic_source_id'],
            $data['flow_type'],
            $rotationJson,
            $trackingDomainId,
            (string) ($data['referrer_mode'] ?? $data['cloaking_mode'] ?? ''),
            $passThroughJson,
            $facebookCapiJson,
            $data['timezone'],
            $data['currency'],
            $data['status'],
            $defaultCpc
        );

        return $stmt->execute() ? $this->db->insert_id : false;
    }

    public function update(int $id, array $data): bool
    {
        $rotationJson = json_encode($data['rotation'] ?? []);
        $passThroughJson = json_encode($data['pass_through'] ?? []);
        $facebookCapiJson = json_encode($data['facebook_capi'] ?? []);
        
        $stmt = $this->db->prepare(
            "UPDATE campaigns 
             SET name = ?, traffic_source_id = ?, flow_type = ?, rotation_json = ?, 
                 tracking_domain_id = ?, referrer_mode = ?, pass_through_json = ?, 
                 facebook_capi_json = ?, timezone = ?, currency = ?, status = ?, 
                 default_cpc = ?, updated_at = NOW() 
             WHERE id = ?"
        );
        
        $trackingDomainId = !empty($data['tracking_domain_id']) ? (int)$data['tracking_domain_id'] : null;
        $defaultCpc = !empty($data['default_cpc']) ? (float)$data['default_cpc'] : null;
        
        $stmt->bind_param(
            'sissississsdi',
            $data['name'],
            $data['traffic_source_id'],
            $data['flow_type'],
            $rotationJson,
            $trackingDomainId,
            (string) ($data['referrer_mode'] ?? $data['cloaking_mode'] ?? ''),
            $passThroughJson,
            $facebookCapiJson,
            $data['timezone'],
            $data['currency'],
            $data['status'],
            $defaultCpc,
            $id
        );

        return $stmt->execute();
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

        if (empty($data['traffic_source_id'])) {
            $errors['traffic_source_id'] = 'Traffic source is required';
        }

        if (empty($data['flow_type'])) {
            $errors['flow_type'] = 'Flow type is required';
        } elseif (!in_array($data['flow_type'], ['DTO', 'LP', 'Split'])) {
            $errors['flow_type'] = 'Invalid flow type';
        }

        // Validate rotations based on flow type
        if (!empty($data['rotation'])) {
            $rotation = $data['rotation'];
            
            if ($data['flow_type'] === 'DTO' && empty($rotation['offers'])) {
                $errors['rotation'] = 'At least one offer is required for Direct-to-Offer flow';
            }
            
            if ($data['flow_type'] === 'LP' && (empty($rotation['landing_pages']) || empty($rotation['offers']))) {
                $errors['rotation'] = 'Landing pages and offers are required for LP flow';
            }
            
            if ($data['flow_type'] === 'Split' && (empty($rotation['landing_pages']) || empty($rotation['offers']))) {
                $errors['rotation'] = 'Landing pages and offers are required for Split flow';
            }

            // Validate weights sum to 100%
            if (!empty($rotation['offers'])) {
                $totalWeight = array_sum(array_column($rotation['offers'], 'weight'));
                if (abs($totalWeight - 100) > 0.01) {
                    $errors['rotation_weights'] = 'Offer weights must sum to 100%';
                }
            }
        }

        if (!empty($data['default_cpc']) && $data['default_cpc'] < 0) {
            $errors['default_cpc'] = 'Default CPC must be 0 or greater';
        }

        return $errors;
    }

    /**
     * Generate tracking link for campaign
     */
    public function generateTrackingLink(int $campaignId, ?string $domain = null): string
    {
        $campaign = $this->getById($campaignId);
        
        if (!$campaign) {
            return '';
        }

        $domain = $domain ?? $campaign['tracking_domain'] ?? parse_url(BASE_URL, PHP_URL_HOST);
        $protocol = 'https';
        
        // Create slug from campaign name
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $campaign['name']));
        
        return "{$protocol}://{$domain}/km/{$campaignId}/{$slug}";
    }
}

