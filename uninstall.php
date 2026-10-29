<?php
/**
 * Deleting the plugin removes its table and options. Posts, terms and menus stay as they are.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}langosh" ); // phpcs:ignore WordPress.DB.PreparedSQL

delete_option( 'langosh' );
delete_option( 'langosh_strings' );
delete_option( 'langosh_registry' );
delete_metadata( 'user', 0, 'langosh_admin_lang', '', true );
