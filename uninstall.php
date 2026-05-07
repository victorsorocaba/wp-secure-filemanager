<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$prefix = $wpdb->prefix;
if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
    return;
}

$tables = [
    $prefix . 'wpsfm_access_rules',
    $prefix . 'wpsfm_folders',
    $prefix . 'wpsfm_audit_log',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}
