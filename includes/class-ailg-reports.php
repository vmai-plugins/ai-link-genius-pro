<?php
/**
 * Reports Class
 */

defined( 'ABSPATH' ) || exit;

class AILG_Reports {

    public static function ajax_dashboard_stats(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;

        // Counted from the site's actual links, not only the ones this plugin
        // inserted. The old figures came from ailg_links, which holds our own
        // insertions - so on any site with existing content "orphaned" meant
        // "we have not linked to it yet", i.e. everything.
        $index               = AILG_LinkIndex::stats();
        $total_links         = $index['internal'];
        $pending_suggestions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ailg_suggestions WHERE status='pending'" );
        $broken              = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ailg_broken_links WHERE status='broken'" )
                             + count( AILG_LinkIndex::unresolved( 500 ) );

        $orphaned = AILG_LinkIndex::is_ready() ? count( AILG_LinkIndex::orphans( 1000 ) ) : 0;
        $progress = $index['progress'];

        // Weekly chart (Optimized one-query fetch)
        $chart_data = [];
        $weekly_stats = $wpdb->get_results(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM {$wpdb->prefix}ailg_links
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)",
            OBJECT_K
        );

        for ( $i = 6; $i >= 0; $i-- ) {
            $date  = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
            $label = gmdate( 'D', strtotime( "-{$i} days" ) );
            $count = isset( $weekly_stats[ $date ] ) ? (int) $weekly_stats[ $date ]->count : 0;
            $chart_data[] = [ 'l' => $label, 'v' => $count ];
        }

        wp_send_json_success( compact( 'total_links', 'pending_suggestions', 'orphaned', 'broken', 'chart_data', 'progress' ) );
    }

    public static function ajax_report_data(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $post_types   = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        $index = AILG_LinkIndex::table();

        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type,
                    (SELECT COUNT(*) FROM {$index} WHERE target_id = p.ID AND is_internal = 1) AS inbound,
                    (SELECT COUNT(*) FROM {$index} WHERE source_id = p.ID AND is_internal = 1) AS outbound,
                    (SELECT SUM(clicks) FROM {$wpdb->prefix}ailg_link_analytics WHERE post_id = p.ID) AS clicks
             FROM {$wpdb->posts} p
             WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders})
             ORDER BY inbound DESC, outbound DESC
             LIMIT 100",
            ...$post_types
        ), ARRAY_A );

        // Real internal authority, not a ratio. The old link_score was
        //   ( inbound * 60 + outbound * 40 ) / ( inbound + outbound )
        // which is a weighted average of two numbers and therefore always
        // lands between 40 and 60 whatever the values - a page with one
        // inbound link scored within a few points of a page with five hundred.
        $authority = AILG_LinkIndex::authority();

        foreach ( $posts as &$p ) {
            $id            = (int) $p['ID'];
            $p['edit_url'] = get_edit_post_link( $id, 'raw' );
            $p['clicks']   = (int) ( $p['clicks'] ?? 0 );
            $p['inbound']  = (int) $p['inbound'];
            $p['outbound'] = (int) $p['outbound'];
            $p['link_score'] = (int) round( $authority[ $id ] ?? 0 );
        }
        unset( $p );

        wp_send_json_success( [ 'posts' => $posts, 'ready' => AILG_LinkIndex::is_ready() ] );
    }

    public static function ajax_orphaned(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        // Until the index has read the site once, this question cannot be
        // answered - and answering it from ailg_links (as it used to) reports
        // every post on the site as orphaned.
        if ( ! AILG_LinkIndex::is_ready() ) {
            wp_send_json_success( [
                'posts'    => [],
                'ready'    => false,
                'progress' => AILG_LinkIndex::progress(),
                'message'  => 'The link index is still reading your site. Orphan detection needs a full pass before it can be trusted.',
            ] );
            return;
        }

        $posts = AILG_LinkIndex::orphans( 200 );

        foreach ( $posts as &$p ) {
            $p['edit_url'] = get_edit_post_link( (int) $p['ID'], 'raw' );
        }
        unset( $p );

        wp_send_json_success( [
            'posts'       => $posts,
            'ready'       => true,
            'underlinked' => AILG_LinkIndex::underlinked( 25 ),
        ] );
    }

    public static function ajax_fix_orphaned(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( 'Invalid ID' );

        $ids = AILG_Scanner::get_orphan_fix_candidates( $post_id );

        wp_send_json_success( [ 'ids' => $ids ] );
    }

    public static function ajax_broken_links(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $post_types   = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title, post_content FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ({$placeholders})
             LIMIT 10", // Legacy fallback
            ...$post_types
        ) );

        $broken = [];
        foreach ( $posts as $post ) {
            $post_broken = self::scan_broken_in_post( $post );
            $broken = array_merge( $broken, $post_broken );
        }

        wp_send_json_success( [ 'links' => $broken, 'checked' => count( $posts ) ] );
    }

    /**
     * Helper to scan a single post object for broken links, including deep scan of metadata.
     */
    private static function scan_broken_in_post( $post ): array {
        global $wpdb;
        $broken = [];

        // 1. Scan Main Content
        $content = $post->post_content;

        // 2. Deep Scan: Scan all Custom Fields (ACF, etc)
        $meta = get_post_meta( $post->ID );
        foreach ( $meta as $key => $values ) {
            foreach ( $values as $value ) {
                if ( is_string( $value ) && ( str_contains( $value, 'http' ) || str_contains( $value, '<a' ) ) ) {
                    $content .= ' ' . $value;
                }
            }
        }

        preg_match_all( '/<a\s[^>]*href\s*=\s*([\'"])(.*?)\1/is', $content, $matches );
        $urls = array_unique( array_filter( array_map( 'trim', $matches[2] ?? [] ) ) );

        foreach ( $urls as $url ) {
            if ( empty( $url ) || str_starts_with( $url, '#' ) || str_starts_with( $url, 'mailto:' ) || str_starts_with( $url, 'tel:' ) ) continue;

            // Resolve relative URLs
            $full_url = $url;
            if ( str_starts_with( $url, '/' ) ) {
                $full_url = home_url( $url );
            } elseif ( ! str_starts_with( $url, 'http' ) ) {
                // Handle cases like "contact" (if it's a relative path from current page)
                // For simplicity in a global scan, we'll assume relative to root if it starts with a char
                $full_url = home_url( '/' . $url );
            }

            // Clean URL from trailing punctuation
            $full_url = rtrim( $full_url, '.,;)' );

            $code = self::check_url( $full_url );

            // Identify Broken or Redirect chains
            if ( $code === 0 || $code >= 400 || ( $code >= 300 && $code < 400 ) ) {
                $item = [
                    'post_id'    => $post->ID,
                    'post_title' => $post->post_title,
                    'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
                    'url'        => $url,
                    'http_code'  => $code,
                ];
                $broken[] = $item;
                $wpdb->replace( "{$wpdb->prefix}ailg_broken_links", [
                    'post_id'  => $post->ID,
                    'url'      => $url,
                    'http_code'=> $code,
                    'status'   => $code >= 400 ? 'broken' : ( $code >= 300 ? 'redirect' : 'pending' ),
                ] );
            }
        }
        return $broken;
    }

    /**
     * HTTP status for a URL, cached across the whole scan.
     *
     * A site-wide scan walks every post, and the same footer, nav, sponsor and
     * documentation links appear in nearly all of them. Without a cache each
     * copy cost its own blocking request at up to 8 seconds - a hundred posts
     * sharing ten footer links meant a thousand requests to check ten URLs.
     *
     * Internal URLs are answered from the database instead of over HTTP: if
     * url_to_postid() resolves it and the post is published, it is not broken,
     * and no request is worth making to find that out.
     */
    private static function check_url( string $url ): int {
        static $memo = [];

        $key = untrailingslashit( $url );
        if ( isset( $memo[ $key ] ) ) {
            return $memo[ $key ];
        }

        $transient = 'ailg_url_' . md5( $key );
        $cached    = get_transient( $transient );
        if ( false !== $cached ) {
            $memo[ $key ] = (int) $cached;
            return (int) $cached;
        }

        $home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

        if ( $host === $home ) {
            $post_id = url_to_postid( $url );
            if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
                $memo[ $key ] = 200;
                set_transient( $transient, 200, DAY_IN_SECONDS );
                return 200;
            }
        }

        $resp = wp_remote_head( $url, [ 'timeout' => 8, 'redirection' => 5 ] );
        $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );

        // Some servers refuse HEAD outright; a 405 says nothing about whether
        // the page exists, so confirm with a ranged GET before condemning it.
        if ( 405 === $code || 501 === $code ) {
            $resp = wp_remote_get( $url, [ 'timeout' => 8, 'redirection' => 5, 'headers' => [ 'Range' => 'bytes=0-2048' ] ] );
            $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
        }

        $memo[ $key ] = $code;
        // Cache failures briefly and successes for a day, so a transient
        // outage does not stick around as a "broken link" for 24 hours.
        set_transient( $transient, $code, ( $code >= 200 && $code < 300 ) ? DAY_IN_SECONDS : HOUR_IN_SECONDS );

        return $code;
    }

    public static function ajax_get_broken_queue(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $post_types = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        $ids = get_posts( [
            'post_type'      => $post_types,
            'posts_per_page' => -1, // Fetch ALL for enterprise
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ] );

        wp_send_json_success( [ 'ids' => $ids ] );
    }

    public static function ajax_scan_broken_single(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( 'Invalid post ID' );

        $post = get_post( $post_id );
        if ( ! $post ) wp_send_json_error( 'Post not found' );

        $broken = self::scan_broken_in_post( $post );

        wp_send_json_success( [ 'links' => $broken ] );
    }

    /**
     * Remove a specific link from a post's content while keeping the anchor text.
     */
    public static function ajax_unlink_broken(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( -1 );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $url     = esc_url_raw( (string) ( $_POST['url'] ?? '' ) );

        if ( ! $post_id || empty( $url ) ) wp_send_json_error( 'Invalid request' );

        $post = get_post( $post_id );
        if ( ! $post ) wp_send_json_error( 'Post not found' );

        // 'edit_posts' alone let anyone who can write a draft strip links out
        // of anybody else's published article.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'You do not have permission to edit that post.' );
        }

        // Regex to find the <a> tag with this href and strip it, keeping the
        // anchor text.
        $pattern     = '/<a[^>]+href=["\']' . preg_quote( $url, '/' ) . '["\'][^>]*>(.*?)<\/a>/i';
        $new_content = preg_replace( $pattern, '$1', $post->post_content );

        if ( null === $new_content || $new_content === $post->post_content ) {
            wp_send_json_error( 'Link not found in content.' );
        }

        $revision_id = AILG_Revisions::record( $post_id, $post->post_content, 'Removed broken link ' . $url );

        $saved = AILG_Inserter::save_content( $post_id, $new_content );
        if ( is_wp_error( $saved ) ) {
            wp_send_json_error( $saved->get_error_message() );
        }

        AILG_LinkIndex::scan_post( $post_id );

        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}ailg_broken_links", [ 'post_id' => $post_id, 'url' => $url ] );

        wp_send_json_success( [
            'message'     => 'Link removed.',
            'revision_id' => $revision_id,
            'undoable'    => (bool) $revision_id,
        ] );
    }

    /**
     * Audit semantic coverage and topical connectivity gaps.
     */
    public static function ajax_semantic_audit(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $post_types = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        $index = AILG_LinkIndex::table();

        // 1. Identify Pillars (using RM or by inbound link count > 5)
        $pillars = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
             WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders})
             AND (
                EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = 'rank_math_pillar_content' AND meta_value = 'on')
                OR (SELECT COUNT(*) FROM {$index} WHERE target_id = p.ID AND is_internal = 1) > 5
             ) LIMIT 10",
            ...$post_types
        ) );

        $gaps = [];
        foreach ( $pillars as $pillar ) {
            $cat_ids = wp_get_post_categories( $pillar->ID );
            if ( empty( $cat_ids ) ) continue;

            $cat_ids_str = implode( ',', array_map( 'intval', $cat_ids ) );

            // Posts in same categories
            $total_related = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                 WHERE tr.term_taxonomy_id IN (SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id IN ($cat_ids_str))
                   AND p.post_status = 'publish' AND p.post_type IN ({$placeholders}) AND p.ID != %d",
                ...array_merge( $post_types, [ $pillar->ID ] )
            ) );

            if ( $total_related === 0 ) continue;

            // Related posts that DO link to this pillar
            $linked = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT source_id) FROM {$index}
                 WHERE target_id = %d AND is_internal = 1 AND source_id IN (
                    SELECT p.ID FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                    WHERE tr.term_taxonomy_id IN (SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id IN ($cat_ids_str))
                 )",
                $pillar->ID
            ) );

            $coverage = round( ( $linked / $total_related ) * 100 );

            if ( $coverage < 60 ) {
                $gaps[] = [
                    'pillar_id'    => $pillar->ID,
                    'pillar_title' => $pillar->post_title,
                    'coverage'     => $coverage,
                    'missing'      => $total_related - $linked,
                    'total'        => $total_related
                ];
            }
        }

        wp_send_json_success( [ 'gaps' => $gaps ] );
    }

    /**
     * Get internal links with low/zero clicks.
     */
    public static function ajax_get_link_decay(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $decayed = $wpdb->get_results(
            "SELECT l.*, a.clicks, a.last_update, p.post_title as source_title
             FROM {$wpdb->prefix}ailg_links l
             LEFT JOIN {$wpdb->prefix}ailg_link_analytics a ON l.post_id = a.post_id AND l.target_id = a.target_id
             LEFT JOIN {$wpdb->posts} p ON l.post_id = p.ID
             WHERE l.link_type = 'internal'
               AND (a.clicks < 2 OR a.clicks IS NULL)
               AND l.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY l.created_at ASC
             LIMIT 50",
            ARRAY_A
        );

        wp_send_json_success( [ 'decayed' => $decayed ] );
    }

    /**
     * Export all internal links as a Graph (Nodes & Edges).
     */
    public static function ajax_get_link_graph(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $table = AILG_LinkIndex::table();
        $links = $wpdb->get_results(
            "SELECT source_id AS post_id, target_id FROM {$table}
             WHERE is_internal = 1 AND target_id > 0 AND source_id != target_id
             LIMIT 1000"
        );

        $nodes = [];
        $edges = [];

        // Track unique post IDs
        $post_ids = [];
        foreach ( $links as $l ) {
            $post_ids[] = (int) $l->post_id;
            $post_ids[] = (int) $l->target_id;
            $edges[] = [ 'source' => (int) $l->post_id, 'target' => (int) $l->target_id ];
        }
        $post_ids = array_unique( $post_ids );

        foreach ( $post_ids as $id ) {
            $nodes[] = [
                'id'    => $id,
                'label' => get_the_title( $id ),
                'url'   => get_edit_post_link( $id, 'raw' )
            ];
        }

        wp_send_json_success( [ 'nodes' => $nodes, 'edges' => $edges ] );
    }

    /**
     * Automatically update all internal links that point to a redirect URL.
     */
    public static function ajax_groom_redirects(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;

        $dry_run = ! empty( $_POST['dry_run'] );
        $batch   = AILG_Revisions::new_batch( 'groom-redirects' );

        // Bounded per run. This makes an HTTP request and rewrites content for
        // every row; unbounded it was one request that could run for hours.
        $redirects = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ailg_broken_links WHERE status = 'redirect' LIMIT 25" );

        $groomed_count = 0;
        $skipped       = [];
        $changes       = [];

        foreach ( $redirects as $r ) {
            $resp = wp_remote_head( $r->url, [ 'timeout' => 5, 'redirection' => 0 ] );
            if ( is_wp_error( $resp ) ) {
                $skipped[] = [ 'url' => $r->url, 'why' => $resp->get_error_message() ];
                continue;
            }

            $code      = wp_remote_retrieve_response_code( $resp );
            $final_url = ( $code >= 300 && $code < 400 ) ? trim( (string) wp_remote_retrieve_header( $resp, 'location' ) ) : '';

            // The original code fell through this branch with $final_url still
            // an empty string whenever the Location header was absent and the
            // status was not exactly 200 - and then str_replace()'d the real
            // URL with nothing, across every post on the site. A missing
            // destination is a reason to skip, never a reason to write.
            if ( '' === $final_url ) {
                $skipped[] = [ 'url' => $r->url, 'why' => 'The server did not say where the redirect goes.' ];
                continue;
            }
            if ( ! wp_http_validate_url( $final_url ) ) {
                $skipped[] = [ 'url' => $r->url, 'why' => 'The redirect target is not a valid URL.' ];
                continue;
            }
            if ( untrailingslashit( $final_url ) === untrailingslashit( $r->url ) ) {
                $skipped[] = [ 'url' => $r->url, 'why' => 'The redirect points at itself.' ];
                continue;
            }

            $result = self::swap_url_in_posts( $r->url, $final_url, $batch, $dry_run, 'Redirect grooming' );
            $groomed_count += $result['updated'];

            if ( $result['updated'] ) {
                $changes[] = [ 'from' => $r->url, 'to' => $final_url, 'posts' => $result['updated'] ];
            }

            if ( ! $dry_run ) {
                $wpdb->update( "{$wpdb->prefix}ailg_broken_links", [ 'status' => 'ok' ], [ 'id' => $r->id ] );
            }
        }

        wp_send_json_success( [
            'message'  => $dry_run
                ? "Preview: {$groomed_count} post(s) would be updated."
                : "Groomed {$groomed_count} link(s).",
            'dry_run'  => $dry_run,
            'changes'  => $changes,
            'skipped'  => $skipped,
            'batch_id' => $dry_run ? '' : $batch,
        ] );
    }

    /**
     * Global internal link URL swapper.
     */
    public static function ajax_global_swap(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $old_url = esc_url_raw( (string) ( $_POST['old_url'] ?? '' ) );
        $new_url = esc_url_raw( (string) ( $_POST['new_url'] ?? '' ) );
        $dry_run = ! empty( $_POST['dry_run'] );

        if ( empty( $old_url ) || empty( $new_url ) ) {
            wp_send_json_error( 'Both URLs are required.' );
        }
        if ( untrailingslashit( $old_url ) === untrailingslashit( $new_url ) ) {
            wp_send_json_error( 'The two URLs are the same.' );
        }

        $batch  = AILG_Revisions::new_batch( 'global-swap' );
        $result = self::swap_url_in_posts( $old_url, $new_url, $batch, $dry_run, 'Global URL swap' );

        wp_send_json_success( [
            'message'  => $dry_run
                ? "Preview: {$result['updated']} post(s) would be updated."
                : "Updated {$result['updated']} post(s).",
            'dry_run'  => $dry_run,
            'updated'  => $result['updated'],
            'posts'    => $result['posts'],
            'batch_id' => $dry_run ? '' : $batch,
        ] );
    }

    /**
     * Swap one URL for another across post content.
     *
     * Shared by the global swapper and redirect grooming, both of which used
     * to do this inline with three problems each:
     *
     *   - the query had no post_type or post_status filter, so it rewrote
     *     revisions, autosaves, attachments and trashed posts alongside live
     *     content - and rewriting a revision quietly corrupts the history the
     *     user would restore from;
     *   - there was no record of the previous content, so a bad swap across a
     *     thousand posts had no undo;
     *   - there was no way to see what a swap would do before doing it.
     *
     * @return array{updated:int,posts:array}
     */
    private static function swap_url_in_posts( string $old_url, string $new_url, string $batch, bool $dry_run, string $reason ): array {
        global $wpdb;

        $types = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        $types = array_filter( array_map( 'sanitize_key', $types ) ) ?: [ 'post', 'page' ];
        $in    = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";

        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title, post_content FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ({$in})
               AND post_content LIKE %s
             LIMIT 500",
            '%' . $wpdb->esc_like( $old_url ) . '%'
        ) );

        $updated = 0;
        $touched = [];

        foreach ( $posts as $p ) {
            // Replace only inside href attributes: href="old_url" or href='old_url'
            $pattern = '/(<a\s[^>]*href\s*=\s*([\'"]))' . preg_quote( $old_url, '/' ) . '(\2[^>]*>)/is';
            $new_content = preg_replace( $pattern, '${1}' . addcslashes( $new_url, '\\$' ) . '${3}', $p->post_content );
            if ( null === $new_content || $new_content === $p->post_content ) {
                continue;
            }

            $touched[] = [
                'ID'    => (int) $p->ID,
                'title' => $p->post_title,
                'edit'  => get_edit_post_link( (int) $p->ID, 'raw' ),
            ];
            $updated++;

            if ( $dry_run ) {
                continue;
            }

            AILG_Revisions::record( (int) $p->ID, $p->post_content, $reason, $batch );
            AILG_Inserter::save_content( (int) $p->ID, $new_content );
            AILG_LinkIndex::scan_post( (int) $p->ID );
        }

        return [ 'updated' => $updated, 'posts' => $touched ];
    }

    public static function rest_get_stats( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        return new WP_REST_Response( [
            'total_links'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ailg_links" ),
            'total_suggestions' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ailg_suggestions" ),
            'accepted'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ailg_suggestions WHERE status='accepted'" ),
            'pending'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ailg_suggestions WHERE status='pending'" ),
        ], 200 );
    }

    /**
     * Track a click on an internal link via REST API.
     */
    /**
     * Record a click on an internal link.
     *
     * This route is necessarily public - the visitors doing the clicking are
     * logged out - but it was previously an unauthenticated, unlimited,
     * unvalidated INSERT. Anyone could drive the click count on any pair of
     * posts to any number with a loop, poisoning every report built on it and
     * growing the analytics table without bound.
     *
     * It is now: off unless tracking is enabled, same-origin only, rate
     * limited per IP, and both ends must be real published posts.
     */
    public static function rest_track_click( WP_REST_Request $request ): WP_REST_Response {
        if ( ! get_option( 'ailg_track_clicks', true ) ) {
            return new WP_REST_Response( [ 'disabled' => true ], 200 );
        }

        // Same-origin only. A cross-site request has no business writing here,
        // and a real click always carries this site's own referer.
        $referer = (string) $request->get_header( 'referer' );
        $home    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        if ( '' === $referer || strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) ) !== $home ) {
            return new WP_REST_Response( [ 'error' => 'Bad origin' ], 403 );
        }

        $post_id    = (int) $request->get_param( 'post_id' );
        $target_url = esc_url_raw( (string) $request->get_param( 'target_url' ) );

        if ( ! $post_id || ! $target_url ) {
            return new WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
        }

        // Both ends must exist and be published, so the table can only ever
        // hold rows about real content.
        if ( 'publish' !== get_post_status( $post_id ) ) {
            return new WP_REST_Response( [ 'error' => 'Unknown source' ], 200 );
        }

        $target_id = url_to_postid( $target_url );
        if ( ! $target_id || 'publish' !== get_post_status( $target_id ) ) {
            return new WP_REST_Response( [ 'error' => 'Not a post' ], 200 );
        }

        // Rate limit: one recorded click per visitor per link pair per hour.
        // Enough for honest analytics, useless for inflating a number.
        $fingerprint = 'ailg_ck_' . md5( self::client_fingerprint() . "|{$post_id}|{$target_id}" );
        if ( get_transient( $fingerprint ) ) {
            return new WP_REST_Response( [ 'throttled' => true ], 200 );
        }
        set_transient( $fingerprint, 1, HOUR_IN_SECONDS );

        global $wpdb;
        $table = "{$wpdb->prefix}ailg_link_analytics";

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table (post_id, target_id, clicks, last_update)
             VALUES (%d, %d, 1, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE clicks = clicks + 1, last_update = CURRENT_TIMESTAMP",
            $post_id, $target_id
        ) );

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * A coarse, non-identifying handle for the current visitor, used only to
     * throttle. Hashed with the site's auth salt so the raw IP is never
     * stored anywhere, including in the transient key.
     */
    private static function client_fingerprint(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 120 ) : '';
        $salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'ailg';
        return hash( 'sha256', $salt . '|' . $ip . '|' . $ua );
    }

    /**
     * AJAX handler to detect anchor text cannibalization conflicts.
     */
    public static function ajax_get_cannibalization(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( -1 );

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT LOWER(TRIM(anchor_text)) AS anchor, 
                    COUNT(DISTINCT target_id) AS target_count, 
                    GROUP_CONCAT(DISTINCT target_id) AS target_ids,
                    COUNT(*) AS total_links
             FROM {$wpdb->prefix}ailg_links 
             WHERE link_type = 'internal' AND anchor_text != '' AND target_id > 0
             GROUP BY LOWER(TRIM(anchor_text))
             HAVING target_count > 1
             ORDER BY target_count DESC, total_links DESC
             LIMIT 50",
            ARRAY_A
        );

        $results = [];
        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $tids = explode( ',', (string) $row['target_ids'] );
                $targets = [];
                foreach ( $tids as $tid ) {
                    $tpost = get_post( (int) $tid );
                    if ( $tpost ) {
                        $targets[] = [
                            'id'    => (int) $tid,
                            'title' => $tpost->post_title,
                            'url'   => get_permalink( (int) $tid ),
                        ];
                    }
                }

                if ( count( $targets ) > 1 ) {
                    $results[] = [
                        'anchor'       => $row['anchor'],
                        'target_count' => (int) $row['target_count'],
                        'total_links'  => (int) $row['total_links'],
                        'targets'      => $targets,
                    ];
                }
            }
        }

        wp_send_json_success( [ 'cannibalization' => $results ] );
    }
}