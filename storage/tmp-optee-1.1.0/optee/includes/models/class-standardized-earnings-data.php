<?php

namespace Optee\Plugin\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Unified monetization earnings data model.
 */
class StandardizedEarningsData {

    public float  $total_earnings  = 0.0;
    public float  $impressions     = 0;
    public float  $clicks          = 0;
    public float  $ecpm            = 0.0;
    public float  $rpm             = 0.0;
    public string $currency        = 'USD';
    public string $date_from       = '';
    public string $date_to         = '';
    public array  $earnings_by_day = [];
    public array  $raw_data        = [];

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
