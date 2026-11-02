<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Filters tracker token placeholders from zone ID lists.
 *
 * Tracker reports may include zone ID tokens (e.g. {zoneid}, [siteid]) that are
 * not real zone IDs. Sending these to traffic source APIs causes errors.
 */
class ZoneFilter {

    /**
     * Known zone ID token placeholders across all traffic sources.
     * Must be kept in sync when new traffic source integrations are added.
     */
    private const TOKENS = [
        '{zoneid}',       // PropellerAds, Galaksion
        '{{zoneid}}',     // HilltopAds
        '{zoneId}',       // RollerAds
        '[zoneid]',       // Mondiad
        '##ZONE_ID##',    // Adsterra
        '[siteid]',       // PopCash
        '[zone]',         // Adcash
        '[WEBSITEID]',    // PopAds
        '{site}',         // PushHouse
        '{BV_SRCID}',     // BidVertiser
        '[[partner_id]]', // Advertizer
        '[[pid]]',        // Advertizer
    ];

    /**
     * Remove known token placeholders from a list of zone IDs.
     *
     * @param array $zone_ids List of zone IDs (strings or ints).
     * @return array Filtered list with tokens removed (re-indexed).
     */
    public static function clean( array $zone_ids ): array {
        return array_values( array_filter( $zone_ids, function ( $id ) {
            return ! in_array( (string) $id, self::TOKENS, true );
        } ) );
    }

    /**
     * Check if a single zone ID is a known token placeholder.
     *
     * @param string $zone_id
     * @return bool True if it's a token (should be skipped).
     */
    public static function is_token( string $zone_id ): bool {
        return in_array( $zone_id, self::TOKENS, true );
    }
}
