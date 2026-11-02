<?php

namespace Optee\Plugin\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/interface-module.php';

/**
 * Base class for traffic source modules.
 */
abstract class AbstractTrafficSource implements ModuleInterface {

    protected array $credentials = [];

    public function get_type(): string {
        return 'traffic_source';
    }

    /**
     * Whether one creative = one campaign on this source, so multiple of its
     * campaigns can be grouped under a single Optee campaign.
     *
     * When true:
     *  - the campaign create/edit form offers a multi-select of campaigns,
     *  - the stored remote campaign ID may be a comma-separated list,
     *  - the campaign report shows an "All campaigns / single" dropdown.
     *
     * Modules that opt in MUST handle a comma-separated $campaign_id in
     * get_statistics(), get_campaign_status(), pause/start, and the zone methods.
     */
    public function campaigns_are_creatives(): bool {
        return false;
    }

    public function set_credentials( array $credentials ): void {
        $this->credentials = $credentials;
    }

    public function get_guide_url(): string {
        return '';
    }

    public function get_campaign_edit_url( string $campaign_id ): string {
        return '';
    }

    public function get_campaign_stats_url( string $campaign_id ): string {
        return '';
    }

    public function get_balance(): array {
        return [ 'supported' => false ];
    }

    /**
     * Get account-level daily earnings (spend) for a date range.
     *
     * @return array ['supported' => bool, 'data' => [['date' => 'Y-m-d', 'amount' => float], ...]]
     */
    public function get_account_earnings( string $date_from, string $date_to ): array {
        return [ 'supported' => false ];
    }

    /**
     * Return a list of supported API capabilities with implementation status.
     *
     * Each entry: [ 'label' => string, 'implemented' => bool ]
     * Subclasses should override to reflect actual implementation.
     */
    public function get_capabilities(): array {
        return [
            [ 'label' => 'Block Zone',              'implemented' => false ],
            [ 'label' => 'Block Zones (bulk)',       'implemented' => false ],
            [ 'label' => 'Get Balance',              'implemented' => false ],
            [ 'label' => 'Get Blocked Zones',        'implemented' => false ],
            [ 'label' => 'Get Campaign Status',      'implemented' => false ],
            [ 'label' => 'Get Campaigns',            'implemented' => false ],
            [ 'label' => 'Get Statistics',           'implemented' => false ],
            [ 'label' => 'Get Whitelisted Zones',   'implemented' => false ],
            [ 'label' => 'Pause Campaign',           'implemented' => false ],
            [ 'label' => 'Start Campaign',           'implemented' => false ],
            [ 'label' => 'Test Connection',          'implemented' => false ],
            [ 'label' => 'Unblock Zone',             'implemented' => false ],
            [ 'label' => 'Unblock Zones (bulk)',     'implemented' => false ],
        ];
    }

    /**
     * Get campaign status from the traffic source.
     *
     * @param string $campaign_id Remote campaign ID.
     * @return string 'active' or 'paused'.
     */
    public function get_campaign_status( string $campaign_id ): string {
        return 'unknown';
    }

    /**
     * Get list of campaigns from the traffic source.
     *
     * @return \Optee\Plugin\Models\StandardizedCampaignData[]
     */
    abstract public function get_campaigns(): array;

    /**
     * Get stats for a specific campaign.
     *
     * @param string $campaign_id Remote campaign ID.
     * @param string $date_from   YYYY-MM-DD.
     * @param string $date_to     YYYY-MM-DD.
     * @return \Optee\Plugin\Models\StandardizedCampaignData
     */
    abstract public function get_campaign_stats( string $campaign_id, string $date_from, string $date_to ): \Optee\Plugin\Models\StandardizedCampaignData;

    /**
     * Get daily statistics for a campaign.
     *
     * @param string $campaign_id Remote campaign ID.
     * @param string $date_from   YYYY-MM-DD.
     * @param string $date_to     YYYY-MM-DD.
     * @param string $group_by    Grouping dimension (e.g. 'days', 'zones', 'browsers').
     * @return array Array of associative row arrays.
     */
    public function get_statistics( string $campaign_id, string $date_from, string $date_to, string $group_by = 'days' ): array {
        return [];
    }

    /**
     * Pause a campaign on the traffic source.
     *
     * @param string $campaign_id
     * @return bool
     */
    abstract public function pause_campaign( string $campaign_id ): bool;

    /**
     * Start/resume a campaign on the traffic source.
     *
     * @param string $campaign_id
     * @return bool
     */
    abstract public function start_campaign( string $campaign_id ): bool;

    /**
     * Get currently blocked (blacklisted) zone IDs for a campaign.
     *
     * @param string $campaign_id Remote campaign ID.
     * @return array List of zone ID strings.
     */
    public function get_blocked_zones( string $campaign_id ): array {
        return [];
    }

    /**
     * Block a zone/placement on a campaign.
     *
     * @param string $campaign_id
     * @param string $zone_id
     * @return bool
     */
    abstract public function block_zone( string $campaign_id, string $zone_id ): bool;

    /**
     * Unblock a zone/placement on a campaign.
     *
     * @param string $campaign_id
     * @param string $zone_id
     * @return bool
     */
    abstract public function unblock_zone( string $campaign_id, string $zone_id ): bool;

    /**
     * Block multiple zones on a campaign.
     *
     * @param string $campaign_id
     * @param array  $zone_ids
     * @return bool
     */
    public function block_zones( string $campaign_id, array $zone_ids ): bool {
        $success = true;
        foreach ( $zone_ids as $zone_id ) {
            if ( ! $this->block_zone( $campaign_id, $zone_id ) ) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Unblock multiple zones on a campaign.
     *
     * @param string $campaign_id
     * @param array  $zone_ids
     * @return bool
     */
    public function unblock_zones( string $campaign_id, array $zone_ids ): bool {
        $success = true;
        foreach ( $zone_ids as $zone_id ) {
            if ( ! $this->unblock_zone( $campaign_id, $zone_id ) ) {
                $success = false;
            }
        }
        return $success;
    }
}
