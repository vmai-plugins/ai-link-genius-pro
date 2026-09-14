<?php
/**
 * High-Quality System Logger
 */

defined( 'ABSPATH' ) || exit;

class AILG_Log {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ailg_logs';
    }

    /**
     * Add a log entry.
     */
    public static function add( string $message, string $level = 'info', string $component = 'system', $data = null ): void {
        global $wpdb;

        $entry = [
            'level'     => $level,
            'component' => $component,
            'message'   => $message,
            'data'      => $data ? wp_json_encode( $data ) : null,
            'created_at' => current_time( 'mysql' )
        ];

        $wpdb->insert( self::table(), $entry );

        // If it's an error, also send to PHP error log for high visibility
        if ( 'error' === $level ) {
            error_log( "AILG ERROR [$component]: $message" );
        }
    }

    public static function info( string $msg, string $comp = 'system', $data = null ) { self::add($msg, 'info', $comp, $data); }
    public static function error( string $msg, string $comp = 'system', $data = null ) { self::add($msg, 'error', $comp, $data); }
    public static function warn( string $msg, string $comp = 'system', $data = null ) { self::add($msg, 'warning', $comp, $data); }

    /**
     * Get recent logs.
     */
    public static function get_recent( int $limit = 100 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Clear all logs.
     */
    public static function clear(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE " . self::table() );
    }

    /**
     * Prune logs older than X days.
     */
    public static function prune( int $days = 7 ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }

    /**
     * AJAX handler to get log data.
     */
    public static function ajax_get_logs(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        wp_send_json_success( [ 'logs' => self::get_recent(150) ] );
    }

    /**
     * AJAX handler to clear logs.
     */
    public static function ajax_clear_logs(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        self::clear();
        wp_send_json_success();
    }
}
