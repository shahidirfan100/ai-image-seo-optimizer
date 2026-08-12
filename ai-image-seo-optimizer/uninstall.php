<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
$aiiso_settings = get_option( 'aiiso_settings', array() );
if ( empty( $aiiso_settings['delete_data_uninstall'] ) ) { return; }

delete_option( 'aiiso_settings' );
delete_option( 'aiiso_provider_index' );

$aiiso_meta_keys = array(
    '_aiiso_backup_v1',
    '_aiiso_decorative',
    '_aiiso_filename_suggestion',
    '_aiiso_keywords',
    '_aiiso_last_error',
    '_aiiso_model',
    '_aiiso_processed_at',
    '_aiiso_provider',
    '_aiiso_status',
);
foreach ( $aiiso_meta_keys as $aiiso_meta_key ) {
    delete_post_meta_by_key( $aiiso_meta_key );
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- User explicitly opted to delete plugin data on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'aiiso_logs' ) );
