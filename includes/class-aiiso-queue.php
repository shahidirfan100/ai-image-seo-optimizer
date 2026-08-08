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
        $sql = "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} a ON (p.ID=a.post_id AND a.meta_key='_wp_attachment_image_alt') WHERE p.post_type='attachment' AND p.post_mime_type LIKE 'image/%'";
        if ( 'missing' === $mode ) { $sql .= " AND (a.meta_value IS NULL OR TRIM(a.meta_value)='')"; }
        elseif ( 'errors' === $mode ) { $sql .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id=p.ID AND s.meta_key='_aiiso_status' AND s.meta_value='error')"; }
        $sql .= $wpdb->prepare( ' ORDER BY p.ID ASC LIMIT %d', $limit );
        $ids = $wpdb->get_col( $sql );
        $count = 0;
        foreach ( $ids as $id ) { if ( self::enqueue( (int) $id, $force ) ) { $count++; } }
        return $count;
    }
}
