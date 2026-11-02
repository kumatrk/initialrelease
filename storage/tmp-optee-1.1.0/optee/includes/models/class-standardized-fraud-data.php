<?php

namespace Optee\Plugin\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Unified antifraud data model.
 */
class StandardizedFraudData {

    public string $campaign_id    = '';
    public float  $total_visits   = 0;
    public float  $fraud_visits   = 0;
    public float  $clean_visits   = 0;
    public float  $fraud_percent  = 0.0;
    public string $date_from      = '';
    public string $date_to        = '';
    public array  $fraud_by_type  = [];
    public array  $fraud_by_zone  = [];
    public array  $raw_data       = [];

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
