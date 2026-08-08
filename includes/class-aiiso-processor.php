<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AIISO_Processor {
    public static function process( int $attachment_id, bool $force = false ) {
        $post = get_post( $attachment_id );
        if ( ! $post || 'attachment' !== $post->post_type || ! str_starts_with( (string) $post->post_mime_type, 'image/' ) ) {
            return new WP_Error( 'aiiso_invalid_attachment', 'Not a valid image attachment.' );
        }
        if ( get_post_meta( $attachment_id, '_aiiso_decorative', true ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', '' );
            update_post_meta( $attachment_id, '_aiiso_status', 'decorative' );
            return true;
        }
        if ( ! $force && 'done' === get_post_meta( $attachment_id, '_aiiso_status', true ) ) { return true; }

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! is_readable( $file ) ) { return new WP_Error( 'aiiso_file_missing', 'Attachment file is missing.' ); }
        $dims = @getimagesize( $file );
        if ( $dims && ( $dims[0] < (int) AIISO_Settings::get( 'min_width', 80 ) || $dims[1] < (int) AIISO_Settings::get( 'min_height', 80 ) ) ) {
            update_post_meta( $attachment_id, '_aiiso_status', 'skipped_small' );
            return true;
        }

        update_post_meta( $attachment_id, '_aiiso_status', 'processing' );
        $context = self::context( $attachment_id );
        $meta = AIISO_Provider::generate( $file, $context );
        if ( is_wp_error( $meta ) ) {
            update_post_meta( $attachment_id, '_aiiso_status', 'error' );
            update_post_meta( $attachment_id, '_aiiso_last_error', $meta->get_error_message() );
            AIISO_Logger::add( $attachment_id, 'error', $meta->get_error_message() );
            return $meta;
        }
        self::apply( $attachment_id, $meta, $force );
        AIISO_Logger::add( $attachment_id, 'success', 'Image SEO metadata generated and saved.', $meta['_provider'] ?? '', $meta['_model'] ?? '' );
        return $meta;
    }

    public static function generate_for_upload( string $tmp_path, string $original_name, int $parent_id = 0 ) {
        $context = array( 'original_filename' => $original_name, 'site_name' => get_bloginfo( 'name' ), 'site_locale' => get_locale() );
        if ( $parent_id > 0 ) { $context = array_merge( $context, self::context_from_parent( $parent_id ) ); }
        return AIISO_Provider::generate( $tmp_path, $context );
    }

    public static function apply( int $attachment_id, array $meta, bool $force = false ): void {
        $s = AIISO_Settings::get_all();
        if ( ! empty( $s['store_backup'] ) && ! get_post_meta( $attachment_id, '_aiiso_backup_v1', true ) ) {
            update_post_meta( $attachment_id, '_aiiso_backup_v1', array(
                'alt' => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
                'title' => (string) get_post_field( 'post_title', $attachment_id ),
                'caption' => (string) get_post_field( 'post_excerpt', $attachment_id ),
                'description' => (string) get_post_field( 'post_content', $attachment_id ),
            ) );
        }

        $current_alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
        if ( $force || ! empty( $s['overwrite_alt'] ) || '' === trim( $current_alt ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $meta['alt'] );
        }

        $postarr = array( 'ID' => $attachment_id ); $changed = false;
        $map = array( 'title' => array( 'post_title', 'overwrite_title' ), 'caption' => array( 'post_excerpt', 'overwrite_caption' ), 'description' => array( 'post_content', 'overwrite_description' ) );
        foreach ( $map as $mk => [ $field, $setting ] ) {
            if ( 'caption' === $mk && empty( $s['generate_caption'] ) ) { continue; }
            if ( 'description' === $mk && empty( $s['generate_description'] ) ) { continue; }
            $existing = (string) get_post_field( $field, $attachment_id );
            if ( $force || ! empty( $s[ $setting ] ) || '' === trim( $existing ) ) { $postarr[ $field ] = $meta[ $mk ] ?? ''; $changed = true; }
        }
        if ( $changed ) { wp_update_post( wp_slash( $postarr ) ); }
        if ( ! empty( $s['generate_keywords'] ) ) { update_post_meta( $attachment_id, '_aiiso_keywords', array_slice( $meta['keywords'] ?? array(), 0, 30 ) ); }
        update_post_meta( $attachment_id, '_aiiso_filename_suggestion', $meta['filename'] ?? '' );
        update_post_meta( $attachment_id, '_aiiso_provider', $meta['_provider'] ?? '' );
        update_post_meta( $attachment_id, '_aiiso_model', $meta['_model'] ?? '' );
        update_post_meta( $attachment_id, '_aiiso_processed_at', current_time( 'mysql' ) );
        update_post_meta( $attachment_id, '_aiiso_status', 'done' );
        delete_post_meta( $attachment_id, '_aiiso_last_error' );
    }

    public static function restore( int $attachment_id ): bool {
        $b = get_post_meta( $attachment_id, '_aiiso_backup_v1', true );
        if ( ! is_array( $b ) ) { return false; }
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $b['alt'] ?? '' );
        wp_update_post( wp_slash( array( 'ID' => $attachment_id, 'post_title' => $b['title'] ?? '', 'post_excerpt' => $b['caption'] ?? '', 'post_content' => $b['description'] ?? '' ) ) );
        update_post_meta( $attachment_id, '_aiiso_status', 'restored' );
        AIISO_Logger::add( $attachment_id, 'info', 'Previous Media Library metadata restored.' );
        return true;
    }

    public static function context( int $attachment_id ): array {
        $post = get_post( $attachment_id );
        $ctx = array(
            'attachment_id' => $attachment_id,
            'original_filename' => basename( (string) get_attached_file( $attachment_id ) ),
            'existing_alt' => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
            'existing_title' => (string) $post->post_title,
            'site_name' => get_bloginfo( 'name' ),
            'site_locale' => get_locale(),
        );
        if ( ! empty( AIISO_Settings::get( 'use_page_context', 1 ) ) ) {
            $parent = (int) $post->post_parent;
            if ( ! $parent && AIISO_Settings::get( 'use_woo_context', 1 ) ) { $parent = self::find_woo_product( $attachment_id ); }
            if ( $parent ) { $ctx = array_merge( $ctx, self::context_from_parent( $parent ) ); }
        }
        return $ctx;
    }

    private static function context_from_parent( int $parent_id ): array {
        $p = get_post( $parent_id ); if ( ! $p ) { return array(); }
        $ctx = array( 'parent_id' => $parent_id, 'page_title' => $p->post_title, 'post_type' => $p->post_type );
        $excerpt = has_excerpt( $p ) ? get_the_excerpt( $p ) : '';
        $ctx['page_summary'] = mb_substr( wp_strip_all_tags( $excerpt ?: $p->post_content ), 0, 900 );
        if ( ! empty( AIISO_Settings::get( 'use_seo_keywords', 1 ) ) ) {
            $keys = array(
                get_post_meta( $parent_id, '_yoast_wpseo_focuskw', true ),
                get_post_meta( $parent_id, 'rank_math_focus_keyword', true ),
                get_post_meta( $parent_id, '_seopress_analysis_target_kw', true ),
                get_post_meta( $parent_id, '_aioseo_keywords', true ),
            );
            $ctx['focus_keywords'] = array_values( array_filter( array_map( static function( $v ) { return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : ''; }, $keys ) ) );
        }
        if ( 'product' === $p->post_type && function_exists( 'wc_get_product' ) && AIISO_Settings::get( 'use_woo_context', 1 ) ) {
            $product = wc_get_product( $parent_id );
            if ( $product ) {
                $ctx['product'] = array(
                    'name' => $product->get_name(), 'sku' => $product->get_sku(),
                    'categories' => wp_strip_all_tags( wc_get_product_category_list( $parent_id, ', ' ) ),
                    'short_description' => mb_substr( wp_strip_all_tags( $product->get_short_description() ), 0, 600 ),
                );
            }
        }
        return $ctx;
    }

    private static function find_woo_product( int $attachment_id ): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted WooCommerce attachment relationship lookup.
        $id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key=%s AND meta_value=%d LIMIT 1', $wpdb->postmeta, '_thumbnail_id', $attachment_id ) );
        if ( $id ) { return $id; }
        $needle = '%' . $wpdb->esc_like( (string) $attachment_id ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted WooCommerce gallery relationship lookup, limited to 10 candidates.
        $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key=%s AND meta_value LIKE %s LIMIT 10', $wpdb->postmeta, '_product_image_gallery', $needle ) );
        foreach ( $ids as $pid ) {
            $gallery = array_map( 'absint', explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) );
            if ( in_array( $attachment_id, $gallery, true ) ) { return (int) $pid; }
        }
        return 0;
    }
}
