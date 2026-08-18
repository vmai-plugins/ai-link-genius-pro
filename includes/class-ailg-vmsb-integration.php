<?php
/**
 * VM SEO Brain Integration Class
 */

defined( 'ABSPATH' ) || exit;

class AILG_VMSB_Integration {

    /**
     * Check if VM SEO Brain (or VMAI Autopilot / VM AI SEO) is active and classes are available.
     */
    public static function is_active(): bool {
        return (defined( 'VMSB_VERSION' ) && class_exists( 'VMSB_Brain' )) || defined('VMAI_ASP_VERSION') || defined('WEBXSEO_VERSION');
    }

    /**
     * Get the silo mapping for a post.
     */
    public static function get_post_silo( int $post_id ): ?array {
        if ( ! self::is_active() ) return null;

        $map = null;
        if ( class_exists('VMSB_Brain') ) {
            $brain = new VMSB_Brain();
            $map   = $brain->recall( 'silo', 'map' );
        } elseif ( defined('VMAI_ASP_VERSION') ) {
            // Support for VMAI Autopilot silo map
            $silo_map = get_option('vmai_asp_silo_map', []);
            if ( ! empty($silo_map) ) {
                $map = ['silos' => []];
                foreach ($silo_map as $s) {
                    $map['silos'][] = [
                        'name' => $s['name'],
                        'pillar' => ['existing_post_id' => $s['id']],
                        'supporting' => []
                    ];
                }
            }
        } elseif ( defined('WEBXSEO_VERSION') ) {
            // Support for VM AI SEO (WebxSEO)
            if ( class_exists('WebxSEO_AILG_Bridge') ) {
                $silo_map = WebxSEO_AILG_Bridge::get_silo_map();
                if ( ! empty($silo_map) ) {
                    $map = ['silos' => []];
                    foreach ($silo_map as $s) {
                        $map['silos'][] = [
                            'name' => $s['name'],
                            'pillar' => ['existing_post_id' => $s['id']],
                            'supporting' => []
                        ];
                    }
                }
            }
        }

        if ( ! $map || empty( $map['silos'] ) ) return null;

        foreach ( $map['silos'] as $silo ) {
            // Check if it's the pillar
            if ( isset( $silo['pillar']['existing_post_id'] ) && (int) $silo['pillar']['existing_post_id'] === $post_id ) {
                return $silo;
            }
            // Check supporting posts
            if ( isset( $silo['supporting'] ) ) {
                foreach ( $silo['supporting'] as $child ) {
                    if ( isset( $child['existing_post_id'] ) && (int) $child['existing_post_id'] === $post_id ) {
                        return $silo;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if VM SEO Brain has a GSC connection.
     */
    public static function has_gsc_connection(): bool {
        if ( ! self::is_active() || ! class_exists('VMSB_Settings') ) return false;
        return (bool) VMSB_Settings::get( 'google_refresh_token' );
    }

    /**
     * Get GSC credentials from VM SEO Brain.
     */
    public static function get_gsc_creds(): array {
        if ( ! self::is_active() || ! class_exists('VMSB_Settings') ) return [];
        return [
            'client_id'     => VMSB_Settings::get( 'google_client_id' ),
            'client_secret' => VMSB_Settings::get( 'google_client_secret' ),
            'refresh_token' => VMSB_Settings::get( 'google_refresh_token' ),
            'property'      => VMSB_Settings::get( 'gsc_property' ),
        ];
    }

    public static function ajax_sync_gsc() {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $creds = self::get_gsc_creds();
        if ( empty($creds['refresh_token']) ) wp_send_json_error( 'VM SEO Brain is not connected to GSC.' );

        update_option( 'ailg_gsc_client_id',     $creds['client_id'] );
        update_option( 'ailg_gsc_client_secret', $creds['client_secret'] );
        update_option( 'ailg_gsc_refresh_token', $creds['refresh_token'] );
        update_option( 'ailg_gsc_property',      $creds['property'] );

        wp_send_json_success( [ 'message' => 'GSC connection synced from VM SEO Brain!' ] );
    }

    /**
     * Get keywords associated with this post from VMSB or VMAI.
     */
    public static function get_post_keywords( int $post_id ): array {
        if ( ! self::is_active() ) return [];

        global $wpdb;
        $kws = [];

        if ( defined('VMSB_VERSION') ) {
            $table = $wpdb->prefix . 'vmsb_keywords';
            $kws   = $wpdb->get_results( $wpdb->prepare(
                "SELECT keyword, cluster FROM {$table} WHERE post_id = %d",
                $post_id
            ), ARRAY_A );
        }

        if ( empty($kws) && defined('VMAI_ASP_VERSION') ) {
            $table = $wpdb->prefix . 'vmai_asp_keywords';
            $kws   = $wpdb->get_results( $wpdb->prepare(
                "SELECT keyword, silo_name as cluster FROM {$table} WHERE target_post_id = %d",
                $post_id
            ), ARRAY_A );
        }

        if ( empty($kws) && defined('WEBXSEO_VERSION') ) {
            // WebxSEO Bridge support
            if ( class_exists('WebxSEO_AILG_Bridge') ) {
                $found = WebxSEO_AILG_Bridge::get_post_keywords($post_id);
                foreach ($found as $kw) {
                    $kws[] = ['keyword' => $kw, 'cluster' => ''];
                }
            }
        }

        return $kws ?: [];
    }

    /**
     * Get posts that are in "Striking Distance" (Ranking 4-10).
     */
    public static function get_striking_distance_posts(): array {
        if ( ! self::is_active() ) return [];

        global $wpdb;
        $posts = [];

        if ( defined('VMSB_VERSION') ) {
            $table = $wpdb->prefix . 'vmsb_keywords';
            $posts = $wpdb->get_col( "SELECT DISTINCT post_id FROM $table WHERE position BETWEEN 4 AND 12 AND post_id IS NOT NULL" );
        }

        if ( empty($posts) && defined('VMAI_ASP_VERSION') ) {
            $table = $wpdb->prefix . 'vmai_asp_keywords';
            $posts = $wpdb->get_col( "SELECT DISTINCT target_post_id FROM $table WHERE avg_position BETWEEN 4 AND 12 AND target_post_id IS NOT NULL" );
        }

        if ( empty($posts) && defined('WEBXSEO_VERSION') ) {
            // Support for WebxSEO via RankMath tables
            $table = $wpdb->prefix . 'rank_math_analytics_gsc';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table ) {
                $posts = $wpdb->get_col( "SELECT DISTINCT object_id FROM {$wpdb->prefix}rank_math_analytics_objects WHERE page IN (SELECT page FROM $table WHERE position BETWEEN 4 AND 12)" );
            }
        }

        return $posts ?: [];
    }

    /**
     * Augment the AI prompt with VMSB/VMAI data.
     */
    public static function augment_prompt( string $prompt, int $post_id ): string {
        if ( ! self::is_active() ) return $prompt;

        $silo = self::get_post_silo( $post_id );
        $context_source = 'VM AI SEO';
        if ( defined('VMAI_ASP_VERSION') ) $context_source = 'VMAI AUTOPILOT';
        elseif ( defined('VMSB_VERSION') ) $context_source = 'VM SEO BRAIN';

        $vmsb_context = "\n\n--- SEO CONTEXT FROM $context_source ---\n";

        if ( defined('VMAI_ASP_VERSION') ) {
            $dna = get_option('vmai_asp_settings')['brand_voice'] ?? '';
            if ( $dna ) {
                $vmsb_context .= "SITE BRAND VOICE / DNA: \"$dna\"\n";
                $vmsb_context .= "INSTRUCTION: Ensure anchor texts and link placements match this brand voice.\n";
            }
        } elseif ( defined('WEBXSEO_VERSION') ) {
            $dna = get_option('webxseo_settings')['business_brief'] ?? '';
            if ( $dna ) {
                $vmsb_context .= "BUSINESS DNA: \"$dna\"\n";
                $vmsb_context .= "INSTRUCTION: Ensure all suggested internal links align with this business profile and voice.\n";
            }
        }

        if ( $silo ) {
            $vmsb_context .= "This post belongs to the SILO: " . $silo['name'] . "\n";
            $vmsb_context .= "The Pillar Page for this silo is ID: " . ( $silo['pillar']['existing_post_id'] ?? 'unknown' ) . "\n";
            if ( ! empty( $silo['money_page'] ) ) {
                $vmsb_context .= "The target Money Page for this silo is: " . $silo['money_page'] . "\n";
            }
            $vmsb_context .= "INSTRUCTION: Prioritize suggestions that link to other posts in the SAME SILO or the PILLAR page. These are high-priority targets for SEO cluster health.\n";
        }

        $keywords = self::get_post_keywords( $post_id );
        if ( ! empty( $keywords ) ) {
            $kw_list = implode( ', ', array_column( $keywords, 'keyword' ) );
            $vmsb_context .= "Target Keywords for this post: " . $kw_list . "\n";
            $vmsb_context .= "INSTRUCTION: Try to find these keywords in the content to use as anchor text where appropriate.\n";
        }

        return $prompt . $vmsb_context;
    }
}
