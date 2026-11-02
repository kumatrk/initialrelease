<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Notifications {

    private const META_KEY = 'optee_notifications';

    /**
     * Add a notification for a user.
     *
     * Writes to the DB table (permanent archive) and also keeps user-meta
     * for the real-time bell dropdown (capped at 50).
     *
     * @param int   $user_id
     * @param array $notification ['type' => ..., 'title' => ..., 'message' => ..., 'time' => ...]
     */
    public static function add( int $user_id, array $notification ): void {
        global $wpdb;

        // WP sometimes stores post titles with HTML entities (e.g. "<" → "&lt;").
        // Decode here so downstream display layers (which run their own escape pass)
        // don't render the literal entity text.
        $title = html_entity_decode( (string) ( $notification['title'] ?? '' ), ENT_QUOTES, 'UTF-8' );
        $notification['title'] = $title;

        do_action( 'optee_debug_event', 'notification.add', [
            'user_id'     => $user_id,
            'type'        => $notification['type'] ?? 'info',
            'title'       => $title,
            'message'     => $notification['message'] ?? '',
            'campaign_id' => (int) ( $notification['campaign_id'] ?? 0 ),
            'time'        => $notification['time'] ?? current_time( 'mysql' ),
        ] );

        // Write to DB table (permanent archive) — use full message if available.
        $db_message  = $notification['message_full'] ?? $notification['message'] ?? '';
        $campaign_id = (int) ( $notification['campaign_id'] ?? 0 );
        $wpdb->insert(
            $wpdb->prefix . 'optee_notifications',
            [
                'user_id'     => $user_id,
                'campaign_id' => $campaign_id,
                'type'        => sanitize_text_field( $notification['type'] ?? 'info' ),
                'title'       => sanitize_text_field( $title ),
                'message'     => wp_kses_post( $db_message ),
                'is_read'     => 0,
                'created_at'  => $notification['time'] ?? current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%d', '%s' ]
        );

        // Bell dropdown (user meta) — use short message, strip message_full.
        $meta_notification = $notification;
        unset( $meta_notification['message_full'] );
        // Resolve campaign title once so the dropdown can render without an extra query.
        // WordPress stores titles with HTML entities (e.g. "–" → "&#8211;"); decode so
        // the bell dropdown shows real characters, not the entity text.
        if ( $campaign_id ) {
            $meta_notification['campaign_id']    = $campaign_id;
            $meta_notification['campaign_title'] = html_entity_decode( (string) ( get_the_title( $campaign_id ) ?: '' ), ENT_QUOTES, 'UTF-8' );
        }
        $notifications   = self::get_meta( $user_id );
        $notifications[] = $meta_notification;

        if ( count( $notifications ) > 50 ) {
            $notifications = array_slice( $notifications, -50 );
        }

        update_user_meta( $user_id, self::META_KEY, $notifications );
    }

    /**
     * Get all notifications for the current user (bell dropdown — user meta).
     */
    public static function get_all(): array {
        $items = self::get_meta( get_current_user_id() );
        foreach ( $items as &$item ) {
            if ( isset( $item['title'] ) ) {
                $item['title'] = html_entity_decode( (string) $item['title'], ENT_QUOTES, 'UTF-8' );
            }
            if ( isset( $item['campaign_title'] ) ) {
                $item['campaign_title'] = html_entity_decode( (string) $item['campaign_title'], ENT_QUOTES, 'UTF-8' );
            }
        }
        unset( $item );
        return $items;
    }

    /**
     * Dismiss a single notification by index (bell dropdown).
     */
    public static function dismiss( int $index ): void {
        $user_id       = get_current_user_id();
        $notifications = self::get_meta( $user_id );

        if ( isset( $notifications[ $index ] ) ) {
            array_splice( $notifications, $index, 1 );
            update_user_meta( $user_id, self::META_KEY, $notifications );
        }
    }

    /**
     * Dismiss all notifications (bell dropdown).
     */
    public static function dismiss_all(): void {
        update_user_meta( get_current_user_id(), self::META_KEY, [] );
    }

    // ── DB archive methods ──────────────────────────────────

    /**
     * Query paginated notifications from the DB table.
     *
     * @param array $args {
     *     @type int    $per_page  Default 50.
     *     @type int    $page      Default 1.
     *     @type string $type      Optional type filter.
     * }
     * @return array { notifications: array, total: int, pages: int }
     */
    public static function query( array $args = [] ): array {
        global $wpdb;

        $table    = $wpdb->prefix . 'optee_notifications';
        $per_page = min( max( intval( $args['per_page'] ?? 50 ), 1 ), 200 );
        $page     = max( intval( $args['page'] ?? 1 ), 1 );
        $offset   = ( $page - 1 ) * $per_page;
        $user_id  = get_current_user_id();

        $where = [ $wpdb->prepare( 'user_id = %d', $user_id ) ];

        if ( ! empty( $args['type'] ) ) {
            $where[] = $wpdb->prepare( 'type = %s', $args['type'] );
        }

        $where_sql = implode( ' AND ', $where );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
        $pages = $total ? (int) ceil( $total / $per_page ) : 1;

        // LEFT JOIN wp_posts to resolve campaign_id → title in a single query.
        $posts = $wpdb->posts;
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT n.*, p.post_title AS campaign_title
                 FROM {$table} n
                 LEFT JOIN {$posts} p ON p.ID = n.campaign_id AND p.post_type = 'optee_campaign'
                 WHERE {$where_sql}
                 ORDER BY n.created_at DESC, n.id DESC
                 LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        // Decode HTML entities in title + campaign_title — WordPress stores titles
        // with entities ("<" → "&lt;", "–" → "&#8211;") and sanitize_text_field
        // re-encodes "<" on insert, so we always need to decode on read.
        foreach ( $rows as &$row ) {
            if ( ! empty( $row['title'] ) ) {
                $row['title'] = html_entity_decode( (string) $row['title'], ENT_QUOTES, 'UTF-8' );
            }
            if ( ! empty( $row['campaign_title'] ) ) {
                $row['campaign_title'] = html_entity_decode( (string) $row['campaign_title'], ENT_QUOTES, 'UTF-8' );
            }
        }
        unset( $row );

        return [
            'notifications' => $rows ?: [],
            'total'         => $total,
            'pages'         => $pages,
        ];
    }

    /**
     * Get count of unread notifications in the DB table.
     */
    public static function unread_count(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'optee_notifications';
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0", get_current_user_id() )
        );
    }

    /**
     * Mark a single DB notification as read.
     */
    public static function mark_read( int $id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'optee_notifications',
            [ 'is_read' => 1 ],
            [ 'id' => $id, 'user_id' => get_current_user_id() ],
            [ '%d' ],
            [ '%d', '%d' ]
        );
    }

    /**
     * Mark all DB notifications as read for current user.
     */
    public static function mark_all_read(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'optee_notifications';
        $wpdb->query(
            $wpdb->prepare( "UPDATE {$table} SET is_read = 1 WHERE user_id = %d AND is_read = 0", get_current_user_id() )
        );
    }

    /**
     * Clear all archived notifications for the current user.
     */
    public static function clear_all(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'optee_notifications';
        $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", get_current_user_id() )
        );
    }

    /**
     * Get notifications from user meta (bell dropdown).
     */
    private static function get_meta( int $user_id ): array {
        $data = get_user_meta( $user_id, self::META_KEY, true );
        return is_array( $data ) ? $data : [];
    }
}
