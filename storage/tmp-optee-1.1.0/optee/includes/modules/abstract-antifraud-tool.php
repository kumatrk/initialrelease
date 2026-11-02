<?php

namespace Optee\Plugin\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/interface-module.php';

/**
 * Base class for antifraud tool modules.
 */
abstract class AbstractAntifraudTool implements ModuleInterface {

    protected array $credentials = [];

    public function get_type(): string {
        return 'antifraud_tool';
    }

    public function set_credentials( array $credentials ): void {
        $this->credentials = $credentials;
    }

    public function get_guide_url(): string {
        return '';
    }

    public function get_balance(): array {
        return [ 'supported' => false ];
    }

    /**
     * Get list of campaigns/sub-zones from the antifraud tool.
     *
     * @return \Optee\Plugin\Models\StandardizedCampaignData[]
     */
    public function get_campaigns(): array {
        return [];
    }

    /**
     * Get fraud data for a specific campaign/date range.
     *
     * @param string $campaign_id
     * @param string $date_from YYYY-MM-DD.
     * @param string $date_to   YYYY-MM-DD.
     * @return \Optee\Plugin\Models\StandardizedFraudData
     */
    abstract public function get_fraud_data( string $campaign_id, string $date_from, string $date_to ): \Optee\Plugin\Models\StandardizedFraudData;

    /**
     * Get statistics rows for a campaign/zone.
     *
     * @param string $campaign_id Zone/campaign ID on the antifraud tool.
     * @param string $date_from   YYYY-MM-DD.
     * @param string $date_to     YYYY-MM-DD.
     * @param string $group_by    Grouping dimension (e.g. 'days', 'zones').
     * @return array Array of associative row arrays.
     */
    public function get_statistics( string $campaign_id, string $date_from, string $date_to, string $group_by = 'days' ): array {
        return [];
    }
}
