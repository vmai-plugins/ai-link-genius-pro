<?php
/**
 * Suggester Class
 */

defined( 'ABSPATH' ) || exit;

class AILG_Suggester {

    public static function ajax_suggestions(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( -1 );

        $post_id  = (int) ( $_POST['post_id'] ?? 0 );
        $content  = isset( $_POST['content'] ) ? wp_unslash( (string) $_POST['content'] ) : '';
        $provider = sanitize_key( (string) ( $_POST['provider'] ?? get_option( 'ailg_default_provider', 'openai' ) ) );
        if ( ! $post_id && empty($content) ) wp_send_json_error( 'Invalid request' );

        // Return cached pending suggestions if any
        if ( empty($content) ) {
            $cached = self::get_pending_suggestions( $post_id );
            if ( ! empty( $cached ) ) {
                wp_send_json_success( [
                    'suggestions' => $cached,
                    'provider'    => 'cache',
                    'cached'      => true,
                ] );
                return;
            }
        }

        $suggestions = AILG_AI::generate_suggestions( $post_id, $provider, $content );

        if ( empty( $suggestions ) ) {
            wp_send_json_error( [
                'message' => 'No link opportunities found. Make sure your post has sufficient content and other published posts exist on your site.',
            ] );
            return;
        }

        global $wpdb;
        $stored = [];
        foreach ( $suggestions as $s ) {
            $wpdb->insert( "{$wpdb->prefix}ailg_suggestions", [
                'post_id'     => $s['post_id'],
                'target_id'   => $s['target_id'],
                'anchor_text' => $s['anchor_text'],
                'context'     => $s['context'],
                'score'       => $s['score'],
                'is_bridge'   => $s['is_bridge'],
                'is_image'    => $s['is_image'],
                'is_ghost'    => $s['is_ghost'],
                'provider'    => $s['provider'],
                'model_used'  => $s['model_used'],
                'status'      => 'pending',
            ] );
            $s['id']           = $wpdb->insert_id;
            $s['target_title'] = get_the_title( $s['target_id'] );
            $s['target_url']   = get_permalink( $s['target_id'] );
            $stored[]          = $s;
        }

        wp_send_json_success( [
            'suggestions' => $stored,
            'provider'    => $provider,
        ] );
    }

    private static function get_pending_suggestions( int $post_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, t.post_title AS target_title
             FROM {$wpdb->prefix}ailg_suggestions s
             LEFT JOIN {$wpdb->posts} t ON t.ID = s.target_id
             WHERE s.post_id = %d AND s.status = 'pending'
             ORDER BY s.score DESC
             LIMIT 10",
            $post_id
        ), ARRAY_A );
        return $rows ?: [];
    }

    public static function ajax_insert_link(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( -1 );

        global $wpdb;
        $id = (int) ( $_POST['suggestion_id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Invalid suggestion ID' );

        $suggestion = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ailg_suggestions WHERE id = %d",
            $id
        ) );
        if ( ! $suggestion ) wp_send_json_error( 'Suggestion not found' );

        $post_id = (int) $suggestion->post_id;
        $post    = get_post( $post_id );
        if ( ! $post ) wp_send_json_error( 'Source post not found' );

        // Capability was checked against 'edit_posts' in general, never
        // against this post. A contributor could apply a suggestion to
        // somebody else's published article.
        $gate = AILG_Inserter::can_edit( $post_id );
        if ( is_wp_error( $gate ) ) {
            wp_send_json_error( $gate->get_error_message() );
            return;
        }

        $content = $post->post_content;
        $anchor  = (string) $suggestion->anchor_text;
        $url     = (string) get_permalink( (int) $suggestion->target_id );

        if ( AILG_Inserter::links_to( $content, $url ) ) {
            $wpdb->update( "{$wpdb->prefix}ailg_suggestions", [ 'status' => 'accepted' ], [ 'id' => $id ] );
            wp_send_json_error( 'This link already exists in the post content.' );
            return;
        }

        // Image and bridge links do not go through the anchor path, so they
        // keep their own handling - but both now save the previous content
        // first, and both write through the kses-safe saver.
        if ( ! empty( $suggestion->is_bridge ) || ! empty( $suggestion->is_image ) ) {
            $link = '<a href="' . esc_url( $url ) . '" class="ailg-link" data-ailg="1">' . esc_html( $anchor ) . '</a>';

            if ( ! empty( $suggestion->is_image ) ) {
                $new_content = AILG_LinkHelper::wrap_image( $content, $anchor, $url );
            } else {
                // Place the bridge after the second paragraph where there is
                // one, otherwise append it.
                $paragraphs = explode( '</p>', $content );
                if ( count( $paragraphs ) > 2 ) {
                    $paragraphs[1] .= '</p><p>' . $link . '</p>';
                    $new_content    = implode( '', $paragraphs );
                } else {
                    $new_content = $content . "\n<p>" . $link . '</p>';
                }
            }

            if ( $new_content === $content ) {
                wp_send_json_error( 'Could not place that link: the image or paragraph it needed was not found.' );
                return;
            }

            $revision_id = AILG_Revisions::record( $post_id, $content, 'Applied suggestion #' . $id );
            $saved       = AILG_Inserter::save_content( $post_id, $new_content );
            if ( is_wp_error( $saved ) ) {
                wp_send_json_error( $saved->get_error_message() );
                return;
            }
            AILG_LinkIndex::scan_post( $post_id );
        } else {
            $result = AILG_Inserter::insert( $post_id, (int) $suggestion->target_id, [
                'anchor'        => $anchor,
                'reason'        => 'Applied suggestion #' . $id,
                'suggestion_id' => $id,
            ] );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
                return;
            }
            $revision_id = $result['revision_id'] ?? 0;
        }

        // Integration: VM SEO Brain Graph update
        if ( class_exists( 'VMSB_Graph' ) ) {
            VMSB_Graph::add_edge( 'post', (int) $suggestion->post_id, 'links_to', 'post', (int) $suggestion->target_id, (float) $suggestion->score );
        }

        // Integration: Rank Math
        if ( class_exists( 'AILG_RankMath_Integration' ) ) {
            AILG_RankMath_Integration::update_link_counts( (int) $suggestion->post_id );
        }

        $wpdb->update( "{$wpdb->prefix}ailg_suggestions", [ 'status' => 'accepted' ], [ 'id' => $id ] );

        $wpdb->replace( "{$wpdb->prefix}ailg_links", [
            'post_id'     => $post_id,
            'target_id'   => (int) $suggestion->target_id,
            'anchor_text' => $anchor,
            'target_url'  => $url,
            'link_type'   => 'internal',
        ] );

        wp_send_json_success( [
            'message'     => 'Link inserted successfully.',
            'revision_id' => $revision_id,
            'undoable'    => (bool) $revision_id,
        ] );
    }

    public static function ajax_eeat_suggestions(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( -1 );

        $post_id  = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( 'Invalid post ID' );

        // Generating suggestions spends a real API call. Gate it on the
        // specific post, not on whether the user can edit posts in general.
        if ( ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error( 'You cannot edit that post.' );

        $suggestions = AILG_AI::generate_external_suggestions( $post_id );

        if ( empty( $suggestions ) ) {
            wp_send_json_error( 'Could not generate authority suggestions. Check your AI settings.' );
            return;
        }

        wp_send_json_success( [ 'suggestions' => $suggestions ] );
    }

    public static function ajax_insert_external_link(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $url     = esc_url_raw( (string) ( $_POST['url'] ?? '' ) );
        $anchor  = sanitize_text_field( (string) ( $_POST['anchor'] ?? '' ) );

        if ( ! $post_id || ! $url || ! $anchor ) wp_send_json_error( 'Missing data' );

        // 'edit_posts' only asks whether this user can write posts at all. It
        // never asked whether they can edit THIS one, so anyone who could save
        // a draft could inject an arbitrary external URL into any published
        // article on the site. can_edit() checks the specific post, and also
        // applies the exclusion lists and the page-builder refusal.
        $gate = AILG_Inserter::can_edit( $post_id );
        if ( is_wp_error( $gate ) ) {
            wp_send_json_error( $gate->get_error_message() );
        }

        if ( ! wp_http_validate_url( $url ) ) {
            wp_send_json_error( 'That is not a valid external URL.' );
        }

        $post = get_post( $post_id );

        // rel has to be one attribute. Emitting rel="nofollow" and then
        // rel="noopener" produced two rel attributes on the same tag; browsers
        // keep the first and discard the rest, so turning on "nofollow" also
        // silently turned off the noopener protection on new-tab links.
        $rel = [];
        if ( get_option( 'ailg_nofollow_external', false ) ) {
            $rel[] = 'nofollow';
        }

        $attrs = ' href="' . esc_url( $url ) . '" class="ailg-external-link"';
        if ( get_option( 'ailg_open_external_new_tab', true ) ) {
            $attrs .= ' target="_blank"';
            $rel[]  = 'noopener';
            $rel[]  = 'noreferrer';
        }
        if ( $rel ) {
            $attrs .= ' rel="' . esc_attr( implode( ' ', array_unique( $rel ) ) ) . '"';
        }

        $link        = '<a' . $attrs . '>' . esc_html( $anchor ) . '</a>';
        $new_content = AILG_LinkHelper::insert( $post->post_content, $anchor, $link );

        if ( $new_content === $post->post_content ) {
            wp_send_json_error( 'Could not find the anchor text safely in the content.' );
        }

        $revision_id = AILG_Revisions::record( $post_id, $post->post_content, 'Inserted EEAT external link: ' . $url );

        // The result was discarded, so a refused write still reported success.
        $saved = AILG_Inserter::save_content( $post_id, $new_content );
        if ( is_wp_error( $saved ) ) {
            wp_send_json_error( $saved->get_error_message() );
        }

        // The index is built from content; without this it goes stale on
        // every external insert.
        AILG_LinkIndex::scan_post( $post_id );

        wp_send_json_success( [
            'message'     => 'Authority link inserted.',
            'revision_id' => $revision_id,
            'undoable'    => (bool) $revision_id,
        ] );
    }

    public static function ajax_accept_suggestion(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( -1 );

        global $wpdb;
        $id = (int) ( $_POST['suggestion_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid suggestion ID' );
        }

        $suggestion = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ailg_suggestions WHERE id = %d",
            $id
        ) );
        if ( ! $suggestion ) {
            wp_send_json_error( 'Suggestion not found' );
        }

        $post_id   = (int) $suggestion->post_id;
        $target_id = (int) $suggestion->target_id;
        $anchor    = (string) $suggestion->anchor_text;
        $url       = (string) get_permalink( $target_id );

        $wpdb->update( "{$wpdb->prefix}ailg_suggestions", [ 'status' => 'accepted' ], [ 'id' => $id ] );
        $wpdb->replace( "{$wpdb->prefix}ailg_links", [
            'post_id'     => $post_id,
            'target_id'   => $target_id,
            'anchor_text' => $anchor,
            'target_url'  => $url,
            'link_type'   => 'internal',
        ] );

        // Link Index update
        AILG_LinkIndex::scan_post( $post_id );

        if ( class_exists( 'AILG_VMSB_Integration' ) ) {
            AILG_VMSB_Integration::sync_edge( $post_id, $target_id );
        }

        wp_send_json_success( [ 'message' => 'Suggestion marked as accepted.' ] );
    }

    public static function ajax_dismiss(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( -1 );
        global $wpdb;
        $id = (int) ( $_POST['suggestion_id'] ?? 0 );
        if ( $id ) {
            $wpdb->update( "{$wpdb->prefix}ailg_suggestions", [ 'status' => 'dismissed' ], [ 'id' => $id ] );
        }
        wp_send_json_success();
    }

    public static function ajax_bulk_accept(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( -1 );

        global $wpdb;
        $min_score = (float) ( $_POST['min_score'] ?? 0.8 );

        $suggestions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ailg_suggestions WHERE status = 'pending' AND score >= %f LIMIT 100",
            $min_score
        ) );

        if ( empty( $suggestions ) ) {
            wp_send_json_error( 'No suggestions found above that score.' );
        }

        // Up to a hundred posts get rewritten here. Without a shared batch id
        // every one of those edits could only ever be undone one at a time.
        $batch    = AILG_Revisions::new_batch( 'bulk-accept' );
        $accepted = 0;
        foreach ( $suggestions as $s ) {
            // We simulate a manual insert for each
            // Note: This might be slow if many suggestions are processed.
            // But we want to ensure content is actually updated if possible.
            $post_id = (int) $s->post_id;
            $target_id = (int) $s->target_id;
            $anchor = (string) $s->anchor_text;

            $result = AILG_Inserter::insert( $post_id, $target_id, [
                'anchor' => $anchor,
                'reason' => 'Bulk accepted from suggestions',
                'batch'  => $batch,
            ] );

            if ( ! is_wp_error( $result ) ) {
                $accepted++;
                $wpdb->update( "{$wpdb->prefix}ailg_suggestions", [ 'status' => 'accepted' ], [ 'id' => $s->id ] );
                $wpdb->replace( "{$wpdb->prefix}ailg_links", [
                    'post_id'     => $post_id,
                    'target_id'   => $target_id,
                    'anchor_text' => $anchor,
                    'target_url'  => get_permalink( $target_id ),
                    'link_type'   => 'internal',
                ] );
            }
        }

        wp_send_json_success( [
            'message'  => "Inserted {$accepted} link(s).",
            'batch_id' => $batch,
            'undoable' => $accepted > 0,
        ] );
    }

    public static function rest_get_suggestions( WP_REST_Request $request ): WP_REST_Response {
        $post_id     = (int) $request->get_param( 'post_id' );
        $suggestions = AILG_AI::generate_suggestions( $post_id );
        return new WP_REST_Response( [ 'suggestions' => $suggestions, 'count' => count( $suggestions ) ], 200 );
    }
}