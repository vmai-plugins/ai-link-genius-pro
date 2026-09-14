<?php
/**
 * Uninstall AI Link Genius Pro
 *
 * Fired when the plugin is uninstalled via WordPress admin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// 1. Clear all scheduled cron events
$cron_hooks = [
    'ailg_cron_daily',
    'ailg_autolink_on_publish_event',
    'ailg_scan_single_post',
    'ailg_run_automation_post',
    'ailg_daily_decay_check',
    'ailg_weekly_report',
    'ailg_hourly_broken_check',
    'ailg_daily_cleanup',
];

foreach ( $cron_hooks as $hook ) {
    $timestamp = wp_next_scheduled( $hook );
    while ( $timestamp ) {
        wp_unschedule_event( $timestamp, $hook );
        $timestamp = wp_next_scheduled( $hook );
    }
}

// 2. Drop all custom plugin tables
$tables = [
    "{$wpdb->prefix}ailg_suggestions",
    "{$wpdb->prefix}ailg_links",
    "{$wpdb->prefix}ailg_automation_rules",
    "{$wpdb->prefix}ailg_broken_links",
    "{$wpdb->prefix}ailg_link_analytics",
    "{$wpdb->prefix}ailg_revisions",
    "{$wpdb->prefix}ailg_link_index",
    "{$wpdb->prefix}ailg_logs",
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// 3. Delete all plugin options and transients
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ailg_%' OR option_name LIKE '_transient_ailg_%' OR option_name LIKE '_transient_timeout_ailg_%'" );

// Clear object cache if available
wp_cache_flush();
