<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'wpsfm_access_rules',
    $wpdb->prefix . 'wpsfm_folders',
    $wpdb->prefix . 'wpsfm_audit_log',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
