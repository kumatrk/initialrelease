<?php

namespace Optee\Plugin\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Unified conversion data model.
 */
class StandardizedConversionData {

    public string $conversion_id = '';
    public string $offer_id      = '';
    public string $offer_name    = '';
    public float  $payout        = 0.0;
    public string $currency      = 'USD';
    public string $status        = '';
    public string $sub_id        = '';
    public string $click_id      = '';
    public string $converted_at  = '';
    public array  $raw_data      = [];

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
