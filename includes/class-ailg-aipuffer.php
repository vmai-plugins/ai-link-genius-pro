<?php
/**
 * AI Puffer Integration Class
 */

defined( 'ABSPATH' ) || exit;

class AILG_AIPuffer {

    /**
     * Discover bots from local or remote AI Puffer instance.
     */
    public static function discover_bots( $url = null, $key = null ) {
        $url = $url ?? get_option( 'ailg_aipuffer_url' );
        $key = $key ?? AILG_Secrets::get( 'ailg_aipuffer_key' );

        $local_bots = self::discover_local();
        $remote_bots = [];

        if ( $url && untrailingslashit($url) !== untrailingslashit(home_url()) ) {
            $remote_bots = self::discover_remote( $url, $key );
        }

        $all = array_merge( $local_bots, $remote_bots );

        // Deduplicate by ID
        $unique = [];
        foreach ( $all as $bot ) {
            $unique[ $bot['id'] ] = $bot;
        }

        return array_values( $unique );
    }

    /**
     * True when this site has a local AI Power / AI Engine instance available.
     */
    public static function has_local_bots(): bool {
        return ! empty( self::discover_local() );
    }

    private static function discover_local() {
        $bots = [];

        // Try AI Power (WPAICG)
        $raw_bots = get_posts( [ 'post_type' => 'wpaicg_chatbots', 'posts_per_page' => 50, 'post_status' => 'any' ] );
        if ( empty($raw_bots) ) {
            // Fallback for different versions/slugs
            $raw_bots = get_posts( [ 'post_type' => 'ai_power_bot', 'posts_per_page' => 50, 'post_status' => 'any' ] );
        }

        foreach ( $raw_bots as $rb ) {
            $bots[] = [ 'id' => $rb->ID, 'name' => $rb->post_title . ' (Local AI Power)' ];
        }

        // Try AI Engine (Meow Apps)
        $mwai_opts = get_option( 'mwai_options' );
        if ( $mwai_opts && ! empty( $mwai_opts['chatbots'] ) ) {
            foreach ( $mwai_opts['chatbots'] as $bot ) {
                $bots[] = [ 'id' => $bot['id'], 'name' => ( $bot['name'] ?? $bot['id'] ) . ' (Local AI Engine)' ];
            }
        }

        // If still empty, check if we can query via class
        if ( empty($bots) && class_exists('\WPAICG\Chat\Storage\BotStorage') ) {
             try {
                  $storage = new \WPAICG\Chat\Storage\BotStorage();
                  $list = method_exists($storage, 'get_chatbots') ? $storage->get_chatbots(false) : [];
                  foreach ($list as $bot) {
                      $bots[] = [ 'id' => $bot->ID, 'name' => $bot->post_title . ' (Storage Bot)' ];
                  }
             } catch(\Throwable $e) {}
        }

        return $bots;
    }

    private static function discover_remote( $url, $key ) {
        $endpoints = [
            '/wp-json/aipkit/v1/chat/list',
            '/wp-json/wpaicg/v1/chat/list',
            '/wp-json/mwai/v1/bots',
            '/wp-json/aipuffer/v1/bots',
            '/wp-json/aipuffer/v1/chat/list'
        ];

        $url = untrailingslashit( $url );
        $found_bots = [];

        foreach ( $endpoints as $path ) {
            $endpoint = $url . $path;
            $headers = [ 'Content-Type' => 'application/json' ];
            if ( $key ) {
                $headers['Authorization'] = 'Bearer ' . $key;
                $headers['X-API-KEY'] = $key;
            }

            $response = wp_remote_get( $endpoint, [
                'headers' => $headers,
                'timeout' => 15,
                'sslverify' => AILG_Core::ssl_verify(), // Only relaxed on local/dev installs
            ] );

            if ( is_wp_error( $response ) ) {
                error_log( 'AILG AIPuffer Discovery Error: ' . $response->get_error_message() );
                continue;
            }

            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            $list = $data['bots'] ?? $data['data'] ?? ( $data['chatbots'] ?? [] );

            if ( is_array( $list ) ) {
                foreach ( $list as $bot ) {
                    if ( isset($bot['id']) ) {
                        $found_bots[] = [ 'id' => $bot['id'], 'name' => ( $bot['name'] ?? $bot['id'] ) . ' (Remote)' ];
                    }
                }
                if ( ! empty($found_bots) ) break;
            }
        }

        return $found_bots;
    }

    public static function ajax_sync_bots() {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $url = isset($_POST['aipuffer_url']) ? esc_url_raw($_POST['aipuffer_url']) : null;
        $key = isset($_POST['aipuffer_key']) ? sanitize_text_field($_POST['aipuffer_key']) : null;

        $bots = self::discover_bots( $url, $key );
        if ( empty( $bots ) ) {
             $msg = 'No bots found.';
             if ( $url ) $msg .= ' Check if REST API is enabled on ' . $url;
             else $msg .= ' AI Power or AI Engine not detected locally.';
             wp_send_json_error( $msg );
        }

        wp_send_json_success( [ 'bots' => $bots ] );
    }
}
