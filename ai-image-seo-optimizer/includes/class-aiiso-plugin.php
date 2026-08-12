<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AIISO_Plugin {
    private static $instance;
    public static function instance(): self { return self::$instance ??= new self(); }
    private function __construct() {
        AIISO_Queue::init();
        if ( is_admin() ) { AIISO_Admin::init(); }
        add_action( 'add_attachment', array( $this, 'on_attachment_added' ) );
        add_filter( 'wp_handle_upload_prefilter', array( $this, 'upload_prefilter' ), 20 );
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'sync_attachment_alt' ), 20, 3 );
        add_filter( 'render_block', array( $this, 'sync_core_image_block_alt' ), 20, 2 );
        add_action( 'aiiso_daily_maintenance', array( 'AIISO_Logger', 'prune' ) );
    }

    public static function activate(): void {
        AIISO_Logger::install();
        if ( ! wp_next_scheduled( 'aiiso_daily_maintenance' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aiiso_daily_maintenance' ); }
    }
    public static function deactivate(): void { wp_clear_scheduled_hook( 'aiiso_daily_maintenance' ); }

    public function upload_prefilter( array $file ): array {
        if ( empty( AIISO_Settings::get( 'auto_new_uploads', 1 ) ) || empty( AIISO_Settings::get( 'safe_rename_new_uploads', 0 ) ) ) { return $file; }
        if ( empty( $file['tmp_name'] ) || empty( $file['type'] ) || ! str_starts_with( (string) $file['type'], 'image/' ) ) { return $file; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only upload context supplied by core; it only improves AI context and changes no permissions.
        $request = wp_unslash( $_REQUEST );
        $parent = absint( $request['post_id'] ?? $request['post'] ?? 0 );
        $meta = AIISO_Processor::generate_for_upload( $file['tmp_name'], $file['name'], $parent );
        if ( is_wp_error( $meta ) ) { AIISO_Logger::add( 0, 'error', 'Upload-time AI rename skipped: ' . $meta->get_error_message() ); return $file; }
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $slug = sanitize_title( $meta['filename'] ?? '' );
        if ( $slug ) { $file['name'] = $slug . ( $ext ? '.' . $ext : '' ); }
        $key = $this->upload_cache_key( basename( $file['name'] ) );
        set_transient( $key, $meta, 10 * MINUTE_IN_SECONDS );
        return $file;
    }

    public function on_attachment_added( int $attachment_id ): void {
        $post = get_post( $attachment_id );
        if ( ! $post || ! str_starts_with( (string) $post->post_mime_type, 'image/' ) || empty( AIISO_Settings::get( 'auto_new_uploads', 1 ) ) ) { return; }
        $basename = basename( (string) get_attached_file( $attachment_id ) );
        $key = $this->upload_cache_key( $basename );
        $meta = get_transient( $key );
        if ( is_array( $meta ) ) {
            delete_transient( $key );
            AIISO_Processor::apply( $attachment_id, $meta, false );
            AIISO_Logger::add( $attachment_id, 'success', 'Optimized during upload prefilter.', $meta['_provider'] ?? '', $meta['_model'] ?? '' );
            return;
        }
        AIISO_Queue::enqueue( $attachment_id, false );
    }

    private function upload_cache_key( string $basename ): string { return 'aiiso_up_' . get_current_user_id() . '_' . md5( strtolower( $basename ) ); }

    public function sync_attachment_alt( array $attr, WP_Post $attachment, $size ): array {
        if ( empty( AIISO_Settings::get( 'sync_frontend_alt', 1 ) ) || get_post_meta( $attachment->ID, '_aiiso_decorative', true ) ) { return $attr; }
        $alt = trim( (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
        if ( '' !== $alt ) { $attr['alt'] = $alt; }
        return $attr;
    }

    public function sync_core_image_block_alt( string $block_content, array $block ): string {
        if ( empty( AIISO_Settings::get( 'sync_frontend_alt', 1 ) ) || ( $block['blockName'] ?? '' ) !== 'core/image' ) { return $block_content; }
        $id = absint( $block['attrs']['id'] ?? 0 ); if ( ! $id || get_post_meta( $id, '_aiiso_decorative', true ) ) { return $block_content; }
        $alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ); if ( '' === $alt || ! class_exists( 'WP_HTML_Tag_Processor' ) ) { return $block_content; }
        $p = new WP_HTML_Tag_Processor( $block_content ); if ( $p->next_tag( 'img' ) ) { $p->set_attribute( 'alt', $alt ); return $p->get_updated_html(); }
        return $block_content;
    }
}
