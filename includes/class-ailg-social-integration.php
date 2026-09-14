<?php
/**
 * VM Social AI Integration Class
 *
 * Connects AI Link Genius Pro with VM Social AI to turn social traffic into
 * internal link equity, funnel social referral visitors to high-converting pages,
 * and enforce shared brand voice & banned words.
 */

defined( 'ABSPATH' ) || exit;

class AILG_Social_Integration {

    /**
     * Check if VM Social AI is installed and active.
     */
    public static function is_active(): bool {
        return defined( 'VMSAI_VERSION' ) || class_exists( 'VMSAI_Core' ) || class_exists( 'VMSAI_Brain' );
    }

    /**
     * Get post IDs actively or recently promoted across social media channels by VM Social AI.
     *
     * @return array<int> List of post IDs.
     */
    public static function get_promoted_post_ids(): array {
        if ( ! self::is_active() ) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vmsai_posts';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            return [];
        }

        // Query posts with status 'published' or 'scheduled' that link to WordPress content
        $urls = $wpdb->get_col(
            "SELECT DISTINCT url FROM {$table} 
             WHERE status IN ('published', 'scheduled') 
               AND url IS NOT NULL AND url != '' 
             ORDER BY id DESC LIMIT 100"
        );

        if ( empty( $urls ) ) {
            return [];
        }

        $post_ids = [];
        $site_url = home_url();

        foreach ( $urls as $url ) {
            if ( ! str_starts_with( $url, $site_url ) ) {
                continue;
            }
            $pid = url_to_postid( $url );
            if ( $pid > 0 ) {
                $post_ids[] = $pid;
            }
        }

        return array_values( array_unique( array_filter( $post_ids ) ) );
    }

    /**
     * Get social campaign audience and brand voice DNA from VM Social AI Brain.
     */
    public static function get_social_voice_dna(): string {
        if ( ! self::is_active() ) {
            return '';
        }

        $dna = '';
        if ( class_exists( 'VMSAI_Brain' ) ) {
            try {
                $brain = new VMSAI_Brain();
                $voice = $brain->recall( 'voice', 'brand' );
                if ( ! empty( $voice ) ) {
                    $dna .= is_array( $voice ) ? json_encode( $voice ) : (string) $voice;
                }
            } catch ( \Throwable $e ) {}
        }

        if ( empty( $dna ) && class_exists( 'VMSAI_Settings' ) && method_exists( 'VMSAI_Settings', 'get' ) ) {
            $dna = (string) VMSAI_Settings::get( 'brand_voice' );
        }

        return trim( $dna );
    }

    /**
     * Verify whether a social landing page has sufficient internal links to core money pages.
     */
    public static function check_social_landing_page( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [ 'status' => 'missing', 'links_count' => 0 ];
        }

        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ailg_links WHERE post_id = %d AND link_type = 'internal'",
            $post_id
        ) );

        return [
            'post_id'     => $post_id,
            'title'       => $post->post_title,
            'url'         => get_permalink( $post_id ),
            'links_count' => $count,
            'needs_links' => $count < 2,
        ];
    }
}
