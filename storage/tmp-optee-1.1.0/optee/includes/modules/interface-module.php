<?php

namespace Optee\Plugin\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Common interface for all Optee modules.
 */
interface ModuleInterface {

    /**
     * Unique slug for this module (e.g., 'propellerads').
     */
    public function get_slug(): string;

    /**
     * Display name (e.g., 'PropellerAds').
     */
    public function get_name(): string;

    /**
     * Module type: traffic_source, tracker, affiliate_network, antifraud_tool, monetization_platform.
     */
    public function get_type(): string;

    /**
     * Returns an array describing required credential fields.
     * Each field: ['key' => 'api_key', 'label' => 'API Key', 'type' => 'text|password']
     */
    public function get_credential_fields(): array;

    /**
     * Test the connection with the given credentials.
     *
     * @param array $credentials Key-value pairs matching get_credential_fields() keys.
     * @return array ['success' => bool, 'message' => string]
     */
    public function test_connection( array $credentials ): array;

    /**
     * Set credentials on this module instance.
     *
     * @param array $credentials
     */
    public function set_credentials( array $credentials ): void;

    /**
     * Optional guide/tutorial URL for this module.
     * Return empty string if none.
     */
    public function get_guide_url(): string;

    /**
     * Get the current account balance.
     *
     * @return array ['supported' => bool, 'balance' => float, 'currency' => string]
     */
    public function get_balance(): array;
}
