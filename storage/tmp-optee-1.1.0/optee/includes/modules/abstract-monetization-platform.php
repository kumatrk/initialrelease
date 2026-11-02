<?php

namespace Optee\Plugin\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/interface-module.php';

/**
 * Base class for monetization platform modules.
 */
abstract class AbstractMonetizationPlatform implements ModuleInterface {

    protected array $credentials = [];

    public function get_type(): string {
        return 'monetization_platform';
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
     * Get account-level daily earnings (revenue) for a date range.
     *
     * @return array ['supported' => bool, 'data' => [['date' => 'Y-m-d', 'amount' => float], ...]]
     */
    public function get_account_earnings( string $date_from, string $date_to ): array {
        return [ 'supported' => false ];
    }

    /**
     * Get earnings for a date range.
     *
     * @param string $date_from YYYY-MM-DD.
     * @param string $date_to   YYYY-MM-DD.
     * @return \Optee\Plugin\Models\StandardizedEarningsData
     */
    abstract public function get_earnings( string $date_from, string $date_to ): \Optee\Plugin\Models\StandardizedEarningsData;
}
