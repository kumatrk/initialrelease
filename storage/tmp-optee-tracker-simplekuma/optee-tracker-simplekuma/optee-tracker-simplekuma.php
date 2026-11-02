<?php
/**
 * Plugin Name: Optee - Simple KUMA
 * Plugin URI:  https://optee.co
 * Description: Simple KUMA (self-hosted) tracker integration for Optee.
 * Version:     1.0.2
 * Author:      Optee
 * Author URI:  https://optee.co
 * License:     GPL-2.0-or-later
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'optee_register_modules', function ( $registry ) {
    require_once __DIR__ . '/class-simplekuma-tracker.php';
    $registry->register( new \Optee\Tracker\SimpleKuma\SimpleKumaTracker() );
} );
