<?php
/**
 * Native Google Search Console Integration
 */

defined( 'ABSPATH' ) || exit;

class AILG_Google {

    /**
     * Check if Google Search Console is connected.
     */
    public static function is_connected(): bool {
        if ( '' !== AILG_Secrets::get( 'ailg_gsc_refresh_token' ) ) return true;

        // Fallback to VM SEO Brain
        if ( class_exists('AILG_VMSB_Integration') ) {
            return AILG_VMSB_Integration::has_gsc_connection();
        }

        return false;
    }

    /**
     * Get the GSC Auth URL.
     */
    public static function get_auth_url(): string {
        $client_id = get_option( 'ailg_gsc_client_id' );
        if ( ! $client_id && class_exists('AILG_VMSB_Integration') ) {
             $creds = AILG_VMSB_Integration::get_gsc_creds();
             $client_id = $creds['client_id'] ?? '';
        }

        if ( ! $client_id ) return '';

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $client_id,
            'redirect_uri'  => admin_url( 'admin.php?page=ailg-settings' ),
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/webmasters.readonly',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => wp_create_nonce( 'ailg_gsc_oauth' ),
        ]);
    }

    /**
     * Get Access Token (with automatic refresh).
     */
    public static function get_access_token() {
        $cached = get_transient( 'ailg_gsc_access_token' );
        if ( $cached ) return $cached;

        $refresh = AILG_Secrets::get( 'ailg_gsc_refresh_token' );
        $client_id     = get_option( 'ailg_gsc_client_id' );
        $client_secret = AILG_Secrets::get( 'ailg_gsc_client_secret' );

        // Fallback to VM SEO Brain
        if ( ! $refresh && class_exists('AILG_VMSB_Integration') ) {
            $creds = AILG_VMSB_Integration::get_gsc_creds();
            $refresh       = $creds['refresh_token'] ?? '';
            $client_id     = $creds['client_id'] ?? '';
            $client_secret = $creds['client_secret'] ?? '';
        }

        if ( ! $refresh || ! $client_id ) return false;

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh,
                'grant_type'    => 'refresh_token',
            ],
        ] );

        if ( is_wp_error( $response ) ) return false;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) return false;

        set_transient( 'ailg_gsc_access_token', $data['access_token'], 3500 );
        return $data['access_token'];
    }

    /**
     * Fetch "Striking Distance" keywords from GSC.
     */
    public static function get_striking_distance_pages(): array {
        $token = self::get_access_token();
        $property = get_option( 'ailg_gsc_property' );

        if ( ! $property && class_exists('AILG_VMSB_Integration') ) {
             $creds = AILG_VMSB_Integration::get_gsc_creds();
             $property = $creds['property'] ?? '';
        }

        if ( ! $token || ! $property ) return [];

        $url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode( $property ) . '/searchAnalytics/query';

        $body = [
            'startDate'  => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
            'endDate'    => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
            'dimensions' => [ 'page' ],
            'rowLimit'   => 500,
        ];

        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) return [];

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['rows'] ) ) return [];

        $target_pages = [];
        foreach ( $data['rows'] as $row ) {
            $pos = (float) $row['position'];
            // Striking Distance: 4-15
            if ( $pos >= 4 && $pos <= 15 ) {
                $post_id = url_to_postid( $row['keys'][0] );
                if ( $post_id ) {
                    $target_pages[] = $post_id;
                }
            }
        }

        return array_unique( $target_pages );
    }
}
