<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Logger {

    /**
     * Write a log entry.
     *
     * @param string      $log_type info|warning|error|rule_execution|api_call
     * @param string      $category rule|campaign|cron|module|general
     * @param string      $message  Human-readable message.
     * @param array|null  $context  Additional data (JSON-encoded).
     */
    public static function log( string $log_type, string $category, string $message, ?array $context = null ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'optee_logs';

        $wpdb->insert( $table, [
            'user_id'    => get_current_user_id(),
            'log_type'   => $log_type,
            'category'   => $category,
            'message'    => $message,
            'context'    => $context ? wp_json_encode( self::redact_secrets( $context ) ) : null,
            'created_at' => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%s', '%s', '%s' ] );
    }

    /**
     * Recursively redact values whose key looks like a secret, so a credential,
     * API key or token accidentally passed in a log context never persists in the
     * DB (logs are exportable and visible to any co-admin). Key-name based, so it
     * cannot mangle legitimate non-secret values.
     */
    private static function redact_secrets( array $context ): array {
        static $pattern = '/(password|passwd|secret|api[_-]?key|access[_-]?key|secret[_-]?key|access[_-]?token|refresh[_-]?token|auth[_-]?token|authorization|bearer|private[_-]?key|client[_-]?secret|credential|^key$|^token$|^apikey$)/i';

        foreach ( $context as $key => $value ) {
            if ( is_array( $value ) ) {
                $context[ $key ] = self::redact_secrets( $value );
            } elseif ( is_string( $key ) && preg_match( $pattern, $key ) ) {
                $context[ $key ] = '[redacted]';
            }
        }

        return $context;
    }

    /**
     * Query logs with filters.
     *
     * @param array $args {
     *     @type int    $per_page Default 50.
     *     @type int    $page     Default 1.
     *     @type string $log_type Optional filter.
     *     @type string $category Optional filter.
     * }
     * @return array ['logs' => [...], 'total' => int, 'pages' => int]
     */
    public static function query( array $args = [] ): array {
        global $wpdb;

        $table    = $wpdb->prefix . 'optee_logs';
        $per_page = max( 1, min( 200, intval( $args['per_page'] ?? 50 ) ) );
        $page     = max( 1, intval( $args['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where  = [ '(user_id = %d OR user_id = 0)' ];
        $values = [ get_current_user_id() ];

        if ( ! empty( $args['log_type'] ) ) {
            $where[]  = 'log_type = %s';
            $values[] = $args['log_type'];
        }

        if ( ! empty( $args['category'] ) ) {
            $where[]  = 'category = %s';
            $values[] = $args['category'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where[]  = 'message LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        }

        // Filter to a single alert by its id stored in the context JSON. Bounded
        // by a non-digit lookahead so alert 12 doesn't also match 123.
        if ( ! empty( $args['alert_id'] ) ) {
            $where[]  = 'context REGEXP %s';
            $values[] = '"alert_id":' . (int) $args['alert_id'] . '([^0-9]|$)';
        }

        $where_sql = implode( ' AND ', $where );

        // Total count.
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total     = ! empty( $values )
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$values ) )
            : (int) $wpdb->get_var( $count_sql );

        // Fetch rows.
        $query_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
        $params    = array_merge( $values, [ $per_page, $offset ] );
        $rows      = $wpdb->get_results( $wpdb->prepare( $query_sql, ...$params ), ARRAY_A );

        // Decode context JSON and HTML entities in message (WP stores post_title
        // with `<` encoded as `&lt;`, which leaks into log messages built from it).
        foreach ( $rows as &$row ) {
            if ( ! empty( $row['message'] ) ) {
                $row['message'] = html_entity_decode( $row['message'], ENT_QUOTES, 'UTF-8' );
            }
            if ( ! empty( $row['context'] ) ) {
                $row['context'] = json_decode( $row['context'], true );
            }
        }

        return [
            'logs'  => $rows ?: [],
            'total' => $total,
            'pages' => (int) ceil( $total / $per_page ),
        ];
    }

    /**
     * Total number of log rows visible to the current user (own + global),
     * ignoring type/category/search filters. Cached briefly so the logs-page
     * size warning doesn't COUNT a multi-million-row table on every request.
     */
    public static function total_for_current_user(): int {
        global $wpdb;
        $uid = get_current_user_id();
        $key = 'optee_logs_total_' . $uid;

        $cached = get_transient( $key );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        $table = $wpdb->prefix . 'optee_logs';
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE (user_id = %d OR user_id = 0)",
            $uid
        ) );

        set_transient( $key, $total, 5 * MINUTE_IN_SECONDS );
        return $total;
    }

    /**
     * Clear all logs for the current user in one statement.
     *
     * NOTE: on a multi-million-row table this single DELETE runs for a long time
     * and holds locks — prefer clear_batch() from a request loop. Kept for any
     * programmatic/CLI caller where a blocking full clear is acceptable.
     */
    public static function clear_all(): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}optee_logs WHERE user_id = %d OR user_id = 0",
            get_current_user_id()
        ) );
        delete_transient( 'optee_logs_total_' . get_current_user_id() );
    }

    /**
     * Delete up to $limit of the current user's log rows and return how many were
     * removed. The UI calls this repeatedly until it returns fewer than $limit,
     * keeping each request short and each transaction small on huge tables.
     */
    public static function clear_batch( int $limit = 100000 ): int {
        global $wpdb;
        $limit   = max( 1, $limit );
        $deleted = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}optee_logs WHERE (user_id = %d OR user_id = 0) LIMIT %d",
            get_current_user_id(),
            $limit
        ) );
        delete_transient( 'optee_logs_total_' . get_current_user_id() );
        return $deleted;
    }
}
