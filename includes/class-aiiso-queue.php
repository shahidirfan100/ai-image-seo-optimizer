<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AIISO_Queue {
    const HOOK = 'aiiso_process_attachment';
    public static function init(): void { add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 2 ); }

    public static function enqueue( int $attachment_id, bool $force = false ): bool {
        if ( $attachment_id <= 0 ) { return false; }
        update_post_meta( $attachment_id, '_aiiso_status', 'queued' );
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            $id = as_enqueue_async_action( self::HOOK, array( $attachment_id, $force ), 'aiiso', true );
            return ! empty( $id );
        }
        $args = array( $attachment_id, $force );
        if ( ! wp_next_scheduled( self::HOOK, $args ) ) { return (bool) wp_schedule_single_event( time() + 5, self::HOOK, $args ); }
        return true;
    }

    public static function run( int $attachment_id, bool $force = false ): void { AIISO_Processor::process( $attachment_id, $force ); }

    public static function bulk_enqueue( string $mode = 'missing', bool $force = false, int $limit = 5000 ): int {
        global $wpdb;
        $limit = max( 1, min( 10000, $limit ) );

        if ( 'missing' === $mode ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk attachment ID lookup; results are immediately queued and not reused.
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT p.ID FROM %i p LEFT JOIN %i a ON (p.ID=a.post_id AND a.meta_key=%s) WHERE p.post_type=%s AND p.post_mime_type LIKE %s AND (a.meta_value IS NULL OR TRIM(a.meta_value)='') ORDER BY p.ID ASC LIMIT %d",
                $wpdb->posts,
                $wpdb->postmeta,
                '_wp_attachment_image_alt',
                'attachment',
                'image/%',
                $limit
            ) );
        } elseif ( 'errors' === $mode ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk attachment ID lookup; results are immediately queued and not reused.
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT p.ID FROM %i p INNER JOIN %i s ON (p.ID=s.post_id) WHERE p.post_type=%s AND p.post_mime_type LIKE %s AND s.meta_key=%s AND s.meta_value=%s ORDER BY p.ID ASC LIMIT %d",
                $wpdb->posts,
                $wpdb->postmeta,
                'attachment',
                'image/%',
                '_aiiso_status',
                'error',
                $limit
            ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk attachment ID lookup; results are immediately queued and not reused.
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM %i WHERE post_type=%s AND post_mime_type LIKE %s ORDER BY ID ASC LIMIT %d",
                $wpdb->posts,
                'attachment',
                'image/%',
                $limit
            ) );
        }

        $count = 0;
        foreach ( $ids as $id ) { if ( self::enqueue( (int) $id, $force ) ) { $count++; } }
        return $count;
    }
}
