<?php

namespace Optee\Plugin\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/interface-module.php';

/**
 * Base class for affiliate network modules.
 */
abstract class AbstractAffiliateNetwork implements ModuleInterface {

    protected array $credentials = [];

    public function get_type(): string {
        return 'affiliate_network';
    }

    public function set_credentials( array $credentials ): void {
        $this->credentials = $credentials;
    }

    public function get_guide_url(): string {
        return '';
    }

    /**
     * Whether this module's logo needs a dark background (white/light-on-transparent logos).
     * Override to return true for networks like MyLead whose logo has white text.
     */
    public function get_logo_dark_bg(): bool {
        return false;
    }

    public function get_balance(): array {
        return [ 'supported' => false ];
    }

    /**
     * Get account-level daily earnings (revenue) for a date range.
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
            [ 'label' => 'Test Connection',  'implemented' => false ],
            [ 'label' => 'Get Offer Status', 'implemented' => false ],
            [ 'label' => 'Get Balance',      'implemented' => false ],
            [ 'label' => 'Get Earnings',     'implemented' => false ],
            [ 'label' => 'Show Insights',    'implemented' => false ],
        ];
    }

    /**
     * Get the status of an offer on the network.
     *
     * @param string $offer_id The remote offer ID.
     * @return array [ 'active' => bool, 'name' => string, 'status' => string ]
     */
    public function get_offer_status( string $offer_id ): array {
        return [ 'active' => false, 'name' => '', 'status' => 'unknown' ];
    }

    /**
     * Get available offers from the network.
     *
     * @return array List of offers.
     */
    abstract public function get_offers(): array;

    /**
     * Get conversions for a date range.
     *
     * @param string $date_from YYYY-MM-DD.
     * @param string $date_to   YYYY-MM-DD.
     * @return \Optee\Plugin\Models\StandardizedConversionData[]
     */
    abstract public function get_conversions( string $date_from, string $date_to ): array;
}
