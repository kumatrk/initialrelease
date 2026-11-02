<?php

namespace Optee\Plugin\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Unified zone/placement stats model.
 */
class StandardizedZoneData {

    public string $zone_id     = '';
    public string $zone_name   = '';
    public float  $impressions = 0;
    public float  $clicks      = 0;
    public float  $conversions = 0;
    public float  $cost        = 0.0;
    public float  $revenue     = 0.0;
    public float  $profit      = 0.0;
    public float  $roi         = 0.0;
    public float  $ctr         = 0.0;
    public float  $cpc         = 0.0;
    public float  $cpa         = 0.0;
    public string $currency    = 'USD';
    public array  $raw_data    = [];

    public static function from_array( array $data ): self {
        $instance = new self();
        foreach ( $data as $key => $value ) {
            if ( property_exists( $instance, $key ) ) {
                $instance->$key = $value;
            }
        }
        return $instance;
    }

    public function to_array(): array {
        return get_object_vars( $this );
    }
}
