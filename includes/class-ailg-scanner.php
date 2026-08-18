<?php
/**
 * Scanner Class
 */

defined( 'ABSPATH' ) || exit;

class AILG_Scanner {

    public static function ajax_bulk_scan(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $post_types = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        $posts      = get_posts( [
            'post_type'      => $post_types,
            'posts_per_page' => 10, // Even lower for this legacy method
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ] );

        $provider          = (string) get_option( 'ailg_default_provider', 'openai' );
        $total_suggestions = 0;
        global $wpdb;

        foreach ( $posts as $post_id ) {
            $suggestions = AILG_AI::generate_suggestions( $post_id, $provider );
            foreach ( $suggestions as $s ) {
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ailg_suggestions WHERE post_id=%d AND target_id=%d AND status='pending' LIMIT 1",
                    $s['post_id'], $s['target_id']
                ) );
                if ( $exists ) continue;

                $wpdb->insert( "{$wpdb->prefix}ailg_suggestions", [
                    'post_id'     => $s['post_id'],
                    'target_id'   => $s['target_id'],
                    'anchor_text' => $s['anchor_text'],
                    'context'     => $s['context'],
                    'score'       => $s['score'],
                    'provider'    => $s['provider'],
                    'model_used'  => $s['model_used'],
                    'status'      => 'pending',
                ] );
                $total_suggestions++;
            }
        }

        wp_send_json_success( [
            'posts_scanned'     => count( $posts ),
            'total_suggestions' => $total_suggestions,
        ] );
    }

    /**
     * Get IDs of posts that need scanning.
     */
    public static function ajax_get_scan_queue(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $post_types = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        $ids = get_posts( [
            'post_type'      => $post_types,
            'posts_per_page' => -1, // Fetch ALL IDs for enterprise-scale scan
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ] );

        wp_send_json_success( [ 'ids' => $ids ] );
    }

    /**
     * Scan a single post via AJAX.
     */
    public static function ajax_scan_single_item(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( 'Invalid post ID' );

        self::scan_post( $post_id );

        wp_send_json_success();
    }

    public static function scan_post( int $post_id ): void {
        global $wpdb;
        $suggestions = AILG_AI::generate_suggestions( $post_id );
        foreach ( $suggestions as $s ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ailg_suggestions WHERE post_id=%d AND target_id=%d AND status='pending' LIMIT 1",
                $s['post_id'], $s['target_id']
            ) );
            if ( $exists ) continue;

            $wpdb->insert( "{$wpdb->prefix}ailg_suggestions", [
                'post_id'     => $s['post_id'],
                'target_id'   => $s['target_id'],
                'anchor_text' => $s['anchor_text'],
                'context'     => $s['context'],
                'score'       => $s['score'],
                'provider'    => $s['provider'],
                'model_used'  => $s['model_used'],
                'status'      => 'pending',
            ] );
        }
    }

    /**
     * Get candidate posts to scan to find link opportunities TO a specific post.
     */
    public static function get_orphan_fix_candidates( int $post_id ): array {
        global $wpdb;
        $post = get_post( $post_id );
        if ( ! $post ) return [];

        $post_types = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        // Strategy 1: Find posts in the same category
        $categories = wp_get_post_categories( $post_id );
        $cat_ids = ! empty( $categories ) ? implode( ',', array_map( 'intval', $categories ) ) : '0';

        $cat_posts = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tt.term_id IN ($cat_ids)
               AND p.post_status = 'publish'
               AND p.post_type IN ({$placeholders})
               AND p.ID != %d
             LIMIT 15",
            ...array_merge( $post_types, [ $post_id ] )
        ) );

        // Strategy 2: Find posts that mention keywords from this post's title
        $title_words = explode( ' ', preg_replace( '/[^a-z0-9 ]/i', '', $post->post_title ) );
        $title_words = array_filter( $title_words, fn($w) => strlen($w) > 4 );

        $kw_posts = [];
        if ( ! empty( $title_words ) ) {
            $search = '%' . $wpdb->esc_like( array_shift( $title_words ) ) . '%';
            $kw_posts = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_content LIKE %s
                   AND post_status = 'publish'
                   AND post_type IN ({$placeholders})
                   AND ID != %d
                 LIMIT 15",
                ...array_merge( [ $search ], $post_types, [ $post_id ] )
            ) );
        }

        $all_candidates = array_unique( array_merge( $cat_posts ?: [], $kw_posts ?: [] ) );
        // Limit to top 10 candidates
        return array_slice( $all_candidates, 0, 10 );
    }
}