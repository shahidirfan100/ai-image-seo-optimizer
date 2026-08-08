<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
$settings = get_option( 'aiiso_settings', array() );
if ( empty( $settings['delete_data_uninstall'] ) ) { return; }
global $wpdb;
delete_option( 'aiiso_settings' );
delete_option( 'aiiso_provider_index' );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_aiiso_%'" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aiiso_logs" );
