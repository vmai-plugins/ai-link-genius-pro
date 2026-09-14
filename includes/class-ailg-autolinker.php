<?php
/**
 * Front-end auto-linking.
 *
 * This filter had a bug that was invisible from the admin screens and very
 * visible to readers.
 *
 * Accepting a suggestion does two things: it writes the link into the post's
 * content, and it records a row in ailg_links. This filter then read those
 * rows on every page view and re-inserted anything whose URL it could not find
 * in the content. In the normal case that is a no-op, because the link is
 * already there. But when somebody edited the post and deliberately removed a
 * link, the row stayed - so this put the link back, on every single page load,
 * for ever. There was no way to remove it from the front end short of finding
 * the row in the database.
 *
 * A link the editor deleted is a decision, not a gap. Rows are now reconciled
 * against the content instead of overriding it: a managed link that is no
 * longer present is treated as removed on purpose and the stale row is
 * dropped. What remains is genuine virtual linking - rows created without ever
 * touching post_content - which is the only case this filter should serve.
 */

defined( 'ABSPATH' ) || exit;

class AILG_AutoLinker {

    public static function process_content( string $content ): string {
        if ( ! get_option( 'ailg_auto_link_enabled' ) ) return $content;
        if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) return $content;

        $post_id = get_the_ID();
        if ( ! $post_id ) return $content;

        // A query on every page view, for a table that is empty on most sites.
        // The answer only changes when the post or its rows change, so cache
        // it against both.
        $cache_key = 'ailg_al_' . $post_id . '_' . get_post_modified_time( 'U', true, $post_id );
        $links     = get_transient( $cache_key );

        if ( false === $links ) {
            global $wpdb;
            $links = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, anchor_text, target_url FROM {$wpdb->prefix}ailg_links
                 WHERE post_id = %d AND link_type = 'internal'",
                $post_id
            ) );
            set_transient( $cache_key, $links ?: [], 12 * HOUR_IN_SECONDS );
        }

        if ( empty( $links ) ) return $content;

        $limit     = (int) get_option( 'ailg_link_limit_per_post', 10 );
        $same_max  = max( 1, (int) get_option( 'ailg_same_link_limit', 2 ) );
        $inserted  = 0;
        $used_urls = [];
        $stale     = [];

        // The stored content, not the filtered content: another filter earlier
        // in the chain may have already added or stripped markup, and the
        // question here is what the author actually saved.
        $stored = (string) get_post_field( 'post_content', $post_id );

        foreach ( $links as $link ) {
            if ( $inserted >= $limit ) break;

            $url    = (string) $link->target_url;
            $anchor = (string) $link->anchor_text;

            if ( ( $used_urls[ $url ] ?? 0 ) >= $same_max ) continue;

            // Already in the rendered content: nothing to do. Compare hrefs
            // rather than raw strings, so an encoding difference between the
            // stored URL and the one in the markup is not read as "missing".
            if ( AILG_Inserter::links_to( $content, $url ) ) {
                $used_urls[ $url ] = ( $used_urls[ $url ] ?? 0 ) + 1;
                continue;
            }

            // Not in the rendered content, but the row says we put it in the
            // stored content once. It has been removed by hand since. Respect
            // that and retire the row instead of re-adding the link.
            if ( ! AILG_Inserter::links_to( $stored, $url ) && self::was_written_to_content( $post_id, $url ) ) {
                $stale[] = (int) $link->id;
                continue;
            }

            $html = '<a href="' . esc_url( $url ) . '" class="ailg-link" data-ailg="1">' . esc_html( $anchor ) . '</a>';
            $new  = AILG_LinkHelper::insert( $content, $anchor, $html );

            if ( $new !== $content ) {
                $content           = $new;
                $used_urls[ $url ] = ( $used_urls[ $url ] ?? 0 ) + 1;
                $inserted++;
            }
        }

        if ( $stale ) {
            self::retire( $stale, $cache_key );
        }

        return $content;
    }

    /**
     * Did this plugin write this link into the post's content at some point?
     *
     * The undo log is the record of that: every content write goes through
     * AILG_Revisions first. If a revision mentions the URL, the link was in
     * the content and is not any more, which means somebody took it out.
     */
    private static function was_written_to_content( int $post_id, string $url ): bool {
        global $wpdb;

        $path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        if ( '' === $path || '/' === $path ) {
            return false;
        }

        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . AILG_Revisions::table() . ' WHERE post_id = %d AND ( before_value LIKE %s OR reason LIKE %s ) LIMIT 1',
            $post_id,
            '%' . $wpdb->esc_like( $path ) . '%',
            '%' . $wpdb->esc_like( $path ) . '%'
        ) );
    }

    /** Drop rows for links the editor has removed by hand. */
    private static function retire( array $ids, string $cache_key ): void {
        global $wpdb;

        $ids = array_filter( array_map( 'intval', $ids ) );
        if ( ! $ids ) {
            return;
        }

        $wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'ailg_links WHERE id IN (' . implode( ',', $ids ) . ')' );
        delete_transient( $cache_key );

        if ( class_exists( 'AILG_Log' ) ) {
            AILG_Log::info(
                'Retired ' . count( $ids ) . ' auto-link row(s) whose links had been removed from the content by hand.',
                'autolinker'
            );
        }
    }

    /**
     * Zero-Touch Auto-Link on Publish Pipeline.
     * Connects newly published articles bidirectionally:
     * 1. Inbound links from existing articles to prevent orphan posts.
     * 2. Outbound links to cluster pillar pages or related foundational content.
     */
    public static function auto_link_newly_published_post( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return;
        }

        $post_types = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            return;
        }

        if ( ! get_option( 'ailg_auto_link_on_publish', true ) ) {
            return;
        }

        if ( class_exists( 'AILG_Log' ) ) {
            AILG_Log::info( "Starting Auto-Link on Publish for post #{$post_id}: {$post->post_title}", 'pipeline' );
        }

        $inbound_count = 0;
        $outbound_count = 0;

        // 1. OUTBOUND LINK: Connect new post to its Silo Pillar Page if available
        if ( class_exists( 'AILG_VMSB_Integration' ) ) {
            $silo = AILG_VMSB_Integration::get_post_silo( $post_id );
            if ( $silo && ! empty( $silo['pillar']['existing_post_id'] ) ) {
                $pillar_id = (int) $silo['pillar']['existing_post_id'];
                if ( $pillar_id !== $post_id ) {
                    $pillar_post = get_post( $pillar_id );
                    if ( $pillar_post && 'publish' === $pillar_post->post_status ) {
                        // Scan content for pillar title or keywords
                        $keywords = AILG_VMSB_Integration::get_post_keywords( $pillar_id );
                        $anchor_candidates = [ $pillar_post->post_title ];
                        foreach ( $keywords as $kw ) {
                            $anchor_candidates[] = $kw['keyword'];
                        }

                        foreach ( $anchor_candidates as $cand_anchor ) {
                            if ( empty( $cand_anchor ) ) continue;
                            if ( stripos( $post->post_content, $cand_anchor ) !== false ) {
                                $res = AILG_Inserter::insert( $post_id, $pillar_id, [
                                    'anchor' => $cand_anchor,
                                    'reason' => 'Auto-link on publish to Silo Pillar #' . $pillar_id,
                                ] );
                                if ( ! is_wp_error( $res ) ) {
                                    $outbound_count++;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        // 2. INBOUND LINKS: Find candidate existing posts to link TO this new post
        global $wpdb;
        $categories = wp_get_post_categories( $post_id );
        $cat_ids    = implode( ',', array_map( 'intval', $categories ) );

        $query = "SELECT DISTINCT p.ID, p.post_title, p.post_content 
                  FROM {$wpdb->posts} p ";
        if ( ! empty( $cat_ids ) ) {
            $query .= "INNER JOIN {$wpdb->term_relationships} tr ON (p.ID = tr.object_id)
                       INNER JOIN {$wpdb->term_taxonomy} tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id)
                       WHERE tt.term_id IN ($cat_ids) AND ";
        } else {
            $query .= "WHERE ";
        }
        $query .= "p.post_status = 'publish' 
                   AND p.post_type = %s 
                   AND p.ID != %d 
                   ORDER BY p.ID DESC 
                   LIMIT 15";

        $candidates = $wpdb->get_results( $wpdb->prepare( $query, $post->post_type, $post_id ) );

        // Search candidate content for phrases matching new post title or keywords
        $new_post_keywords = class_exists( 'AILG_VMSB_Integration' ) ? AILG_VMSB_Integration::get_post_keywords( $post_id ) : [];
        $target_phrases = [ $post->post_title ];
        foreach ( $new_post_keywords as $kw ) {
            $target_phrases[] = $kw['keyword'];
        }
        $clean_title = preg_replace( '/[^\w\s]/u', '', $post->post_title );
        $words = array_filter( explode( ' ', (string) $clean_title ) );
        if ( count( $words ) >= 3 ) {
            $target_phrases[] = implode( ' ', array_slice( $words, 0, 3 ) );
            $target_phrases[] = implode( ' ', array_slice( $words, -3 ) );
        }

        $target_phrases = array_filter( array_unique( $target_phrases ) );

        if ( ! empty( $candidates ) ) {
            foreach ( $candidates as $cand ) {
                if ( $inbound_count >= 3 ) {
                    break; // Max 3 inbound links auto-injected on publish
                }

                foreach ( $target_phrases as $phrase ) {
                    if ( mb_strlen( $phrase ) < 4 ) continue;
                    if ( stripos( $cand->post_content, $phrase ) !== false ) {
                        $res = AILG_Inserter::insert( (int) $cand->ID, $post_id, [
                            'anchor' => $phrase,
                            'reason' => 'Auto-link on publish inbound to new post #' . $post_id,
                        ] );
                        if ( ! is_wp_error( $res ) ) {
                            $inbound_count++;
                            break;
                        }
                    }
                }
            }
        }

        // Link Index scan
        AILG_LinkIndex::scan_post( $post_id );

        if ( class_exists( 'AILG_Log' ) ) {
            AILG_Log::info(
                "Auto-Link on Publish complete for post #{$post_id}: {$inbound_count} inbound, {$outbound_count} outbound link(s) added.",
                'pipeline'
            );
        }
    }
}
