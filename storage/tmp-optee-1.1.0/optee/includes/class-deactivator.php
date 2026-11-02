<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Deactivator {

    /**
     * Run on plugin deactivation.
     * Only flushes rewrite rules — no data is removed.
     */
    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}
