<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AIISO_Logger {
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'aiiso_logs'; }

    public static function install(): void {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL DEFAULT 'info',
            provider VARCHAR(32) NOT NULL DEFAULT '',
            model VARCHAR(191) NOT NULL DEFAULT '',
            message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};" );
    }

    public static function add( int $attachment_id, string $status, string $message, string $provider = '', string $model = '' ): void {
        global $wpdb;
        $wpdb->insert( self::table(), array(
            'attachment_id' => $attachment_id,
            'status'        => sanitize_key( $status ),
            'provider'      => sanitize_text_field( $provider ),
            'model'         => sanitize_text_field( $model ),
            'message'       => wp_strip_all_tags( $message ),
            'created_at'    => current_time( 'mysql' ),
        ), array( '%d','%s','%s','%s','%s','%s' ) );
    }

    public static function recent( int $limit = 50 ): array {
        global $wpdb;
        $limit = max( 1, min( 200, $limit ) );
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ) ?: array();
    }

    public static function prune(): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
    }
}
