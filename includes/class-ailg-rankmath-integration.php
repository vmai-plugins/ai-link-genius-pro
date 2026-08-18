<?php
/**
 * Rank Math Integration Class
 */

defined( 'ABSPATH' ) || exit;

class AILG_RankMath_Integration {

    /**
     * Check if Rank Math is active.
     */
    public static function is_active(): bool {
        return defined( 'RANK_MATH_VERSION' );
        /**
     * Notify Rank Math to re-process links for a post.
     */
    public static function update_link_counts( int $post_id ): void {
        if ( ! self::is_active() ) return;

        // Rank Math usually processes this on save_post, which we already trigger via wp_update_post.
        // But we can explicitly trigger their link processor if needed.
        if ( class_exists( '\RankMath\Links\ContentProcessor' ) ) {
            $processor = \RankMath\Links\ContentProcessor::get();
            $post = get_post( $post_id );
            if ( $post ) {
                $processor->process( $post_id, $post->post_content );
            }
        }
    }
}

    /**
     * Get focus keywords for a post.
     */
    public static function get_focus_keywords( int $post_id ): array {
        if ( ! self::is_active() ) return [];

        $keywords = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        if ( empty( $keywords ) ) return [];

        return array_map( 'trim', explode( ',', $keywords ) );
        /**
     * Notify Rank Math to re-process links for a post.
     */
    public static function update_link_counts( int $post_id ): void {
        if ( ! self::is_active() ) return;

        // Rank Math usually processes this on save_post, which we already trigger via wp_update_post.
        // But we can explicitly trigger their link processor if needed.
        if ( class_exists( '\RankMath\Links\ContentProcessor' ) ) {
            $processor = \RankMath\Links\ContentProcessor::get();
            $post = get_post( $post_id );
            if ( $post ) {
                $processor->process( $post_id, $post->post_content );
            }
        }
    }
}

    /**
     * Check if a post is a pillar page in Rank Math.
     */
    public static function is_pillar_page( int $post_id ): bool {
        if ( ! self::is_active() ) return false;

        $is_pillar = get_post_meta( $post_id, 'rank_math_pillar_content', true );
        return $is_pillar === 'on';
        /**
     * Notify Rank Math to re-process links for a post.
     */
    public static function update_link_counts( int $post_id ): void {
        if ( ! self::is_active() ) return;

        // Rank Math usually processes this on save_post, which we already trigger via wp_update_post.
        // But we can explicitly trigger their link processor if needed.
        if ( class_exists( '\RankMath\Links\ContentProcessor' ) ) {
            $processor = \RankMath\Links\ContentProcessor::get();
            $post = get_post( $post_id );
            if ( $post ) {
                $processor->process( $post_id, $post->post_content );
            }
        }
    }
}

    /**
     * Augment the AI prompt with Rank Math data.
     */
    public static function augment_prompt( string $prompt, int $post_id ): string {
        if ( ! self::is_active() ) return $prompt;

        $context = "\n\n--- SEO CONTEXT FROM RANK MATH ---\n";

        $keywords = self::get_focus_keywords( $post_id );
        if ( ! empty( $keywords ) ) {
            $context .= "Target Focus Keywords: " . implode( ', ', $keywords ) . "\n";
            $context .= "INSTRUCTION: These are the primary SEO targets for this post. Ensure link suggestions use these keywords as anchors if they appear naturally.\n";
            /**
     * Notify Rank Math to re-process links for a post.
     */
    public static function update_link_counts( int $post_id ): void {
        if ( ! self::is_active() ) return;

        // Rank Math usually processes this on save_post, which we already trigger via wp_update_post.
        // But we can explicitly trigger their link processor if needed.
        if ( class_exists( '\RankMath\Links\ContentProcessor' ) ) {
            $processor = \RankMath\Links\ContentProcessor::get();
            $post = get_post( $post_id );
            if ( $post ) {
                $processor->process( $post_id, $post->post_content );
            }
        }
    }
}

        if ( self::is_pillar_page( $post_id ) ) {
            $context .= "This post is marked as a PILLAR PAGE (Cornerstone Content).\n";
            $context .= "INSTRUCTION: This is a high-authority hub. It should primarily receive inbound links from supporting content and link out to key money pages.\n";
            /**
     * Notify Rank Math to re-process links for a post.
     */
    public static function update_link_counts( int $post_id ): void {
        if ( ! self::is_active() ) return;

        // Rank Math usually processes this on save_post, which we already trigger via wp_update_post.
        // But we can explicitly trigger their link processor if needed.
        if ( class_exists( '\RankMath\Links\ContentProcessor' ) ) {
            $processor = \RankMath\Links\ContentProcessor::get();
            $post = get_post( $post_id );
            if ( $post ) {
                $processor->process( $post_id, $post->post_content );
            }
        }
    }
}

        return $prompt . $context;
        /**
     * Notify Rank Math to re-process links for a post.
     */
    public static function update_link_counts( int $post_id ): void {
        if ( ! self::is_active() ) return;

        // Rank Math usually processes this on save_post, which we already trigger via wp_update_post.
        // But we can explicitly trigger their link processor if needed.
        if ( class_exists( '\RankMath\Links\ContentProcessor' ) ) {
            $processor = \RankMath\Links\ContentProcessor::get();
            $post = get_post( $post_id );
            if ( $post ) {
                $processor->process( $post_id, $post->post_content );
            }
        }
    }
}
    /**
     * Notify Rank Math to re-process links for a post.
     */
    public static function update_link_counts( int $post_id ): void {
        if ( ! self::is_active() ) return;

        // Rank Math usually processes this on save_post, which we already trigger via wp_update_post.
        // But we can explicitly trigger their link processor if needed.
        if ( class_exists( '\RankMath\Links\ContentProcessor' ) ) {
            $processor = \RankMath\Links\ContentProcessor::get();
            $post = get_post( $post_id );
            if ( $post ) {
                $processor->process( $post_id, $post->post_content );
            }
        }
    }
}
