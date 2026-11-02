<?php

namespace Optee\Plugin\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/interface-module.php';

/**
 * Base class for tracker modules.
 */
abstract class AbstractTracker implements ModuleInterface {

    protected array $credentials = [];

    public function get_type(): string {
        return 'tracker';
    }

    public function set_credentials( array $credentials ): void {
        $this->credentials = $credentials;
    }

    public function get_guide_url(): string {
        return '';
    }

    /**
     * Whether the logo needs a dark background to render readably
     * (e.g. logos with white text on transparent background).
     * Used by the configure page + integrations grid.
     */
    public function get_logo_dark_bg(): bool {
        return false;
    }

    /**
     * Supported API methods for the configure page.
     * Subclasses should override to reflect actual implementation.
     */
    public function get_capabilities(): array {
        return [];
    }

    public function get_balance(): array {
        return [ 'supported' => false ];
    }

    /**
     * Get list of campaigns from the tracker.
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
     * Get stats broken down by zone/placement.
     *
     * @param string $campaign_id
     * @param string $date_from
     * @param string $date_to
     * @return \Optee\Plugin\Models\StandardizedZoneData[]
     */
    abstract public function get_stats_by_zone( string $campaign_id, string $date_from, string $date_to ): array;

    /**
     * Get available zone/source field options for a campaign.
     *
     * Each tracker defines "zone" differently. For BeMob, zones map to
     * custom parameter slots (custom1–custom10) defined on the traffic source
     * linked to the campaign.
     *
     * @param string $campaign_id Remote campaign ID.
     * @return array Array of ['zone_id' => 'custom1', 'zone_name' => 'Zone ID'].
     */
    public function get_zone_fields( string $campaign_id ): array {
        return [];
    }

    /**
     * Get statistics rows for a campaign.
     *
     * @param string $campaign_id Remote campaign ID.
     * @param string $date_from   YYYY-MM-DD.
     * @param string $date_to     YYYY-MM-DD.
     * @param string $group_by    Grouping dimension (e.g. 'days', 'zones').
     * @return array Array of associative row arrays.
     */
    public function get_statistics( string $campaign_id, string $date_from, string $date_to, string $group_by = 'days' ): array {
        return [];
    }

    /**
     * Get aggregated stats for multiple campaigns in a single API call.
     * Returns data keyed by remote campaign ID.
     *
     * Trackers that support a global report grouped by campaign should override
     * this method. The default falls back to calling get_statistics() per campaign.
     *
     * @param array  $campaign_ids Array of remote campaign IDs.
     * @param string $date_from    YYYY-MM-DD.
     * @param string $date_to      YYYY-MM-DD.
     * @return array Remote campaign ID => [ 'tr_visits' => int, 'tr_clicks' => int, 'tr_conv' => int, 'tr_cost' => float, 'tr_revenue' => float ]
     */
    public function get_dashboard_statistics( array $campaign_ids, string $date_from, string $date_to ): array {
        $results = [];
        foreach ( $campaign_ids as $cid ) {
            try {
                $rows = $this->get_statistics( $cid, $date_from, $date_to, 'days' );
                $agg  = [ 'tr_visits' => 0, 'tr_clicks' => 0, 'tr_conv' => 0, 'tr_cost' => 0.0, 'tr_revenue' => 0.0 ];
                foreach ( $rows as $row ) {
                    $agg['tr_visits']  += (int) ( $row['tr_visits'] ?? 0 );
                    $agg['tr_clicks']  += (int) ( $row['tr_clicks'] ?? 0 );
                    $agg['tr_conv']    += (int) ( $row['tr_conv'] ?? 0 );
                    $agg['tr_cost']    += (float) ( $row['tr_cost'] ?? 0 );
                    $agg['tr_revenue'] += (float) ( $row['tr_revenue'] ?? 0 );
                }
                $results[ $cid ] = $agg;
            } catch ( \Exception $e ) {
                $results[ $cid ] = null;
            }
        }
        return $results;
    }
}
