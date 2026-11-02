<?php

namespace Optee\Plugin\Rules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Optee\Plugin\ModuleRegistry;

class RuleAction {

    /**
     * Execute a rule action.
     *
     * @param string $action      Action name.
     * @param int    $campaign_id Optee campaign post ID.
     * @param array  $zone_ids    Zone IDs (zone actions) or country codes (remove_countries), dynamically discovered.
     * @param array  $params      Extra action parameters (e.g. adjust_bid: percent/min/max).
     * @return array ['success' => bool, 'message' => string]
     */
    public static function execute( string $action, int $campaign_id, array $zone_ids = [], array $params = [] ): array {
        $ts_module_slug = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $ts_campaign_id = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );

        if ( ! $ts_module_slug || ! $ts_campaign_id ) {
            return [
                'success' => false,
                'message' => 'Campaign has no traffic source configured.',
            ];
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module_with_credentials( $ts_module_slug );

        if ( ! $module ) {
            return [
                'success' => false,
                'message' => "Traffic source module \"{$ts_module_slug}\" not found or no credentials.",
            ];
        }

        try {
            $result = match ( $action ) {
                'pause_traffic',
                'pause_campaign'  => self::pause_campaign( $module, $ts_campaign_id, $campaign_id ),
                'resume_traffic',
                'start_campaign'  => self::start_campaign( $module, $ts_campaign_id, $campaign_id ),
                'block_zone',
                'block_zones'     => self::block_zones( $module, $ts_campaign_id, $zone_ids ),
                'unblock_zone',
                'unblock_zones'   => self::unblock_zones( $module, $ts_campaign_id, $zone_ids ),
                'remove_countries' => self::remove_countries( $module, $ts_campaign_id, $zone_ids ),
                'remove_targeting' => self::remove_targeting( $module, $ts_campaign_id, $zone_ids, $params ),
                'adjust_bid'      => self::adjust_bid( $module, $ts_campaign_id, $params ),
                default           => [ 'success' => false, 'message' => "Unknown action: {$action}" ],
            };

            return $result;
        } catch ( \Throwable $e ) {
            return [
                'success' => false,
                'message' => "Action failed: " . $e->getMessage(),
            ];
        }
    }

    private static function pause_campaign( $module, string $remote_id, int $local_id ): array {
        $success = $module->pause_campaign( $remote_id );
        if ( $success ) {
            update_post_meta( $local_id, '_optee_status', 'paused' );
        }
        return [
            'success' => $success,
            'message' => $success ? 'Campaign paused.' : 'Failed to pause campaign.',
        ];
    }

    private static function start_campaign( $module, string $remote_id, int $local_id ): array {
        $success = $module->start_campaign( $remote_id );
        if ( $success ) {
            update_post_meta( $local_id, '_optee_status', 'active' );
        }
        return [
            'success' => $success,
            'message' => $success ? 'Campaign started.' : 'Failed to start campaign.',
        ];
    }

    private static function block_zones( $module, string $remote_id, array $zone_ids ): array {
        if ( empty( $zone_ids ) ) {
            return [ 'success' => true, 'message' => 'No zones matched conditions.' ];
        }

        $matched_count = count( $zone_ids );

        // Filter out already-blocked zones so we only report truly new blocks.
        $already_blocked = [];
        if ( method_exists( $module, 'get_blocked_zones' ) ) {
            try {
                $already_blocked = array_map( 'strval', $module->get_blocked_zones( $remote_id ) );
            } catch ( \Throwable $e ) {
                // If fetching fails, proceed with all zones — worst case we re-report existing ones.
            }
        }

        $new_zone_ids = array_values( array_diff( $zone_ids, $already_blocked ) );
        $new_count    = count( $new_zone_ids );

        if ( $new_count === 0 ) {
            return [
                'success'        => true,
                'no_change'      => true,
                'message'        => "{$matched_count} zone(s) matched conditions but all were already blocked.",
                'affected_zones' => [],
            ];
        }

        if ( $new_count === 1 && method_exists( $module, 'block_zone' ) ) {
            $success = $module->block_zone( $remote_id, $new_zone_ids[0] );
        } else {
            $success = $module->block_zones( $remote_id, $new_zone_ids );
        }

        $skipped      = $matched_count - $new_count;
        $zone_short   = implode( ', ', array_slice( $new_zone_ids, 0, 10 ) ) . ( $new_count > 10 ? '...' : '' );
        $zone_full    = implode( ', ', $new_zone_ids );
        $skipped_msg  = $skipped > 0 ? " ({$skipped} already blocked)" : '';

        return [
            'success'      => $success,
            'message'      => $success
                ? "{$new_count} zone(s) blocked: {$zone_short}{$skipped_msg}"
                : "Failed to block {$new_count} zone(s).",
            'message_full' => $success
                ? "{$new_count} zone(s) blocked: {$zone_full}{$skipped_msg}"
                : "Failed to block {$new_count} zone(s).",
            // Only the zones we actually newly blocked — so the activity timeline
            // lists a zone the first time it's blocked, not on every re-run where
            // it was already on the blacklist.
            'affected_zones' => $success ? $new_zone_ids : [],
        ];
    }

    private static function unblock_zones( $module, string $remote_id, array $zone_ids ): array {
        if ( empty( $zone_ids ) ) {
            return [ 'success' => true, 'message' => 'No zones matched conditions.' ];
        }

        $matched_count = count( $zone_ids );

        // Filter to only zones that are actually blocked — skip zones not on the blacklist.
        $already_blocked = [];
        if ( method_exists( $module, 'get_blocked_zones' ) ) {
            try {
                $already_blocked = array_map( 'strval', $module->get_blocked_zones( $remote_id ) );
            } catch ( \Throwable $e ) {
                // If fetching fails, proceed with all zones.
            }
        }

        // Only unblock zones that are currently blocked.
        $to_unblock = ! empty( $already_blocked )
            ? array_values( array_intersect( $zone_ids, $already_blocked ) )
            : $zone_ids;
        $unblock_count = count( $to_unblock );

        if ( $unblock_count === 0 ) {
            return [
                'success'        => true,
                'no_change'      => true,
                'message'        => "{$matched_count} zone(s) matched conditions but none were blocked.",
                'affected_zones' => [],
            ];
        }

        if ( $unblock_count === 1 && method_exists( $module, 'unblock_zone' ) ) {
            $success = $module->unblock_zone( $remote_id, $to_unblock[0] );
        } else {
            $success = $module->unblock_zones( $remote_id, $to_unblock );
        }

        $skipped      = $matched_count - $unblock_count;
        $zone_short   = implode( ', ', array_slice( $to_unblock, 0, 10 ) ) . ( $unblock_count > 10 ? '...' : '' );
        $zone_full    = implode( ', ', $to_unblock );
        $skipped_msg  = $skipped > 0 ? " ({$skipped} were not blocked)" : '';

        return [
            'success'      => $success,
            'message'      => $success
                ? "{$unblock_count} zone(s) unblocked: {$zone_short}{$skipped_msg}"
                : "Failed to unblock {$unblock_count} zone(s).",
            'message_full' => $success
                ? "{$unblock_count} zone(s) unblocked: {$zone_full}{$skipped_msg}"
                : "Failed to unblock {$unblock_count} zone(s).",
            // Only the zones we actually unblocked (were on the blacklist).
            'affected_zones' => $success ? $to_unblock : [],
        ];
    }

    /**
     * Remove the given country codes from the campaign's traffic-source targeting.
     * $codes are the matched (under-performing) country codes discovered by the
     * country-level condition evaluation. The module guards against removing the
     * last targeted country (which would convert the campaign to RON).
     */
    private static function remove_countries( $module, string $remote_id, array $codes ): array {
        if ( ! method_exists( $module, 'remove_countries' ) ) {
            return [ 'success' => false, 'message' => 'This traffic source does not support removing countries via API.' ];
        }

        $codes = array_values( array_filter( array_map( 'strval', $codes ) ) );
        if ( empty( $codes ) ) {
            return [ 'success' => true, 'message' => 'No countries matched conditions.' ];
        }

        $res      = $module->remove_countries( $remote_id, $codes );
        $removed  = isset( $res['removed'] ) && is_array( $res['removed'] ) ? array_values( array_map( 'strval', $res['removed'] ) ) : [];

        return [
            'success'      => (bool) ( $res['success'] ?? false ),
            'no_change'    => ! empty( $res['no_change'] ),
            'message'      => $res['message'] ?? 'Countries updated.',
            'message_full' => $res['message'] ?? 'Countries updated.',
            // Only the countries actually removed — drives the optimization timeline
            // (a country is listed the first time it's removed, not on every re-run).
            'affected_zones' => ! empty( $res['success'] ) ? $removed : [],
        ];
    }

    /**
     * Remove targeting options (category / OS / browser / form_factor / ISP /
     * resolution …) from the campaign, based on the values discovered by the
     * targeting condition evaluation. The dimension comes from the rule params;
     * the module maps tracker values → PopAds targeting and PATCHes the campaign.
     */
    private static function remove_targeting( $module, string $remote_id, array $values, array $params ): array {
        $dim = $params['dimension'] ?? '';
        if ( ! $dim ) {
            return [ 'success' => false, 'message' => 'No targeting dimension set on the rule.' ];
        }
        if ( ! method_exists( $module, 'remove_targeting' ) ) {
            return [ 'success' => false, 'message' => "This traffic source does not support removing {$dim} via API." ];
        }

        $values = array_values( array_filter( array_map( 'strval', $values ) ) );
        if ( empty( $values ) ) {
            return [ 'success' => true, 'message' => "No {$dim} matched conditions." ];
        }

        $res     = $module->remove_targeting( $remote_id, $dim, $values );
        $removed = isset( $res['removed'] ) && is_array( $res['removed'] ) ? array_values( array_map( 'strval', $res['removed'] ) ) : [];
        $labels  = isset( $res['removed_labels'] ) && is_array( $res['removed_labels'] ) ? array_values( array_map( 'strval', $res['removed_labels'] ) ) : [];

        return [
            'success'      => (bool) ( $res['success'] ?? false ),
            'no_change'    => ! empty( $res['no_change'] ),
            'message'      => $res['message'] ?? 'Targeting updated.',
            'message_full' => $res['message'] ?? 'Targeting updated.',
            // Only the values actually removed — drives the optimization timeline.
            'affected_zones'  => ! empty( $res['success'] ) ? $removed : [],
            // Readable names of the removed values — for the result modal display.
            'affected_labels' => ! empty( $res['success'] ) ? $labels : [],
        ];
    }

    /**
     * Adjust the campaign's traffic-source bid by a percentage, honouring optional
     * min/max guard bounds. Campaign-level action (no discovered items).
     */
    private static function adjust_bid( $module, string $remote_id, array $params ): array {
        if ( ! method_exists( $module, 'adjust_bid' ) ) {
            return [ 'success' => false, 'message' => 'This traffic source does not support bid adjustment via API.' ];
        }

        $percent = (float) ( $params['percent'] ?? 0 );
        $min     = ( isset( $params['min'] ) && $params['min'] !== '' && $params['min'] !== null ) ? (float) $params['min'] : null;
        $max     = ( isset( $params['max'] ) && $params['max'] !== '' && $params['max'] !== null ) ? (float) $params['max'] : null;

        $res = $module->adjust_bid( $remote_id, $percent, $min, $max );

        return [
            'success'      => (bool) ( $res['success'] ?? false ),
            'no_change'    => ! empty( $res['no_change'] ),
            'message'      => $res['message'] ?? 'Bid updated.',
            'message_full' => $res['message'] ?? 'Bid updated.',
        ];
    }
}
