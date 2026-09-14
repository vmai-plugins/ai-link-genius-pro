<?php
/**
 * Safe link insertion.
 *
 * Everything that rewrites a post's content goes through here.
 *
 * The class this replaces was one regex:
 *
 *     '/(?<!["\'>])(' . preg_quote( $anchor ) . ')(?![^<]*<\/a>)/u'
 *
 * The lookbehind only rejects a match that begins immediately after a quote or
 * a `>`, so `alt="a solar panel install in progress"` matches happily on the
 * space. The lookahead only looks as far as the next `<`, so it misses nearly
 * every anchor that is genuinely inside a link. Nothing at all guarded
 * headings, shortcodes, script blocks, or the JSON inside Gutenberg block
 * comments - where a stray `<a>` breaks the block and the editor offers to
 * "attempt recovery" the next time anybody opens the post.
 *
 * This works the other way round: compute every byte range that must not be
 * touched, look for the anchor only in what is left, splice by offset. If
 * there is nowhere safe, refuse and say why.
 */

defined( 'ABSPATH' ) || exit;

class AILG_Inserter {

    /**
     * Page builders that keep the real body text somewhere other than
     * post_content. Writing to post_content on these sites changes nothing a
     * visitor will ever see.
     *
     * @var array<string,string> meta key => builder label
     */
    const BUILDER_META = [
        '_elementor_data'           => 'Elementor',
        '_et_pb_use_builder'        => 'Divi',
        '_fl_builder_enabled'       => 'Beaver Builder',
        'panels_data'               => 'SiteOrigin Page Builder',
        'ct_builder_shortcodes'     => 'Oxygen',
        'tve_updated_post'          => 'Thrive Architect',
        '_themify_builder_settings' => 'Themify Builder',
        'mfn-page-items'            => 'Muffin Builder',
        '_cornerstone_data'         => 'Cornerstone',
        '_wpb_vc_js_status'         => 'WPBakery',
    ];

    /**
     * Insert a link to $target_id inside $post_id.
     *
     * @param array $args anchor, reason, suggestion_id, dry_run, nofollow, new_tab
     * @return array|WP_Error
     */
    public static function insert( int $post_id, int $target_id, array $args = [] ): array|WP_Error {
        $args = wp_parse_args( $args, [
            'anchor'        => '',
            'reason'        => 'Link Genius suggestion',
            'suggestion_id' => 0,
            'batch'         => '',
            'dry_run'       => false,
            'nofollow'      => false,
            'new_tab'       => false,
        ] );

        $gate = self::can_edit( $post_id );
        if ( is_wp_error( $gate ) ) {
            return $gate;
        }

        $target = get_post( $target_id );
        if ( ! $target || 'publish' !== $target->post_status ) {
            return new WP_Error( 'ailg_link', 'The link target is missing or not published.' );
        }
        if ( $post_id === $target_id ) {
            return new WP_Error( 'ailg_link', 'A post cannot link to itself.' );
        }

        $excluded_terms = array_filter( array_map( 'intval', (array) get_option( 'ailg_exclude_categories', [] ) ) );
        if ( $excluded_terms && has_category( $excluded_terms, $target_id ) ) {
            return new WP_Error( 'ailg_link', 'The target is in a category excluded from automated linking.' );
        }

        $url = get_permalink( $target_id );
        if ( ! $url ) {
            return new WP_Error( 'ailg_link', 'The link target has no resolvable URL.' );
        }

        $post    = get_post( $post_id );
        $content = (string) $post->post_content;

        if ( self::links_to( $content, $url ) ) {
            return new WP_Error( 'ailg_link_exists', 'That link is already in this post.' );
        }

        $anchor = trim( (string) $args['anchor'] );
        if ( '' === $anchor ) {
            return new WP_Error( 'ailg_link', 'No anchor text was supplied.' );
        }
        if ( self::is_ignored_anchor( $anchor ) ) {
            return new WP_Error( 'ailg_link_anchor', sprintf( 'The anchor "%s" is on the ignore list.', $anchor ) );
        }

        $blocked = self::protected_ranges( $content );
        $hit     = self::locate( $content, $anchor, $blocked );

        if ( ! $hit ) {
            return new WP_Error(
                'ailg_link_anchor',
                sprintf( 'No safe place to put the anchor "%s" - it is either absent, or every occurrence sits inside a tag, a heading, an existing link, or a shortcode.', $anchor )
            );
        }

        // Use the text exactly as it appears in the content, not the model's
        // copy of it. They differ whenever the source contains an entity
        // (&amp;, &nbsp;) or a curly apostrophe, and re-escaping the model's
        // version is what turns "R&D" into "R&amp;amp;D".
        $matched = substr( $content, $hit['offset'], $hit['length'] );

        $attrs = ' href="' . esc_url( $url ) . '" class="ailg-link" data-ailg="1"';
        if ( $args['nofollow'] ) {
            $attrs .= ' rel="nofollow"';
        }
        if ( $args['new_tab'] ) {
            $attrs .= ' target="_blank" rel="noopener"';
        }

        $updated = substr( $content, 0, $hit['offset'] )
            . '<a' . $attrs . '>' . $matched . '</a>'
            . substr( $content, $hit['offset'] + $hit['length'] );

        if ( $args['dry_run'] ) {
            return [
                'post_id'   => $post_id,
                'target_id' => $target_id,
                'anchor'    => $matched,
                'url'       => $url,
                'offset'    => $hit['offset'],
                'preview'   => self::preview( $content, $hit ),
                'dry_run'   => true,
            ];
        }

        $revision_id = AILG_Revisions::record( $post_id, $content, $args['reason'], (string) $args['batch'] );

        $saved = self::save_content( $post_id, $updated );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        AILG_LinkIndex::scan_post( $post_id );

        if ( class_exists( 'AILG_VMSB_Integration' ) ) {
            AILG_VMSB_Integration::sync_edge( $post_id, $target_id );
        }

        return [
            'post_id'     => $post_id,
            'target_id'   => $target_id,
            'anchor'      => $matched,
            'url'         => $url,
            'offset'      => $hit['offset'],
            'revision_id' => $revision_id,
        ];
    }

    /* ------------------------------------------------------------- gates */

    /**
     * Whether this post may be rewritten at all.
     *
     * @return true|WP_Error
     */
    public static function can_edit( int $post_id ): bool|WP_Error {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'ailg_link', 'Post not found.' );
        }

        // Every caller previously acted on whatever post ID arrived in the
        // request as long as the user could edit *some* post. Check this post.
        if ( ! current_user_can( 'edit_post', $post_id ) && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return new WP_Error( 'ailg_link', 'You do not have permission to edit that post.' );
        }

        if ( in_array( (int) $post_id, [ (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ], true ) ) {
            return new WP_Error( 'ailg_link', 'The home and blog pages are never rewritten automatically.' );
        }

        $excluded = (array) get_option( 'ailg_exclude_post_ids', [] );
        if ( in_array( (int) $post_id, array_map( 'intval', $excluded ), true ) ) {
            return new WP_Error( 'ailg_link', 'This post is on the exclusion list.' );
        }

        // ailg_exclude_categories was seeded on activation and then read by
        // nothing at all, so a site that excluded a category still had links
        // written into it. It is enforced on both ends: never rewrite an
        // excluded post, and never link out to one.
        $excluded_terms = array_filter( array_map( 'intval', (array) get_option( 'ailg_exclude_categories', [] ) ) );
        if ( $excluded_terms && has_category( $excluded_terms, $post_id ) ) {
            return new WP_Error( 'ailg_link', 'This post is in a category excluded from automated linking.' );
        }

        $builder = self::detect_builder( $post_id );
        if ( $builder ) {
            return new WP_Error(
                'ailg_link_builder',
                sprintf( 'This post is built with %s, which stores its text outside post_content. Editing it here would not change the published page, so no link was written.', $builder )
            );
        }

        return true;
    }

    /** Which page builder owns this post's body text, if any. */
    public static function detect_builder( int $post_id ): string {
        foreach ( self::BUILDER_META as $key => $label ) {
            $value = get_post_meta( $post_id, $key, true );
            if ( empty( $value ) ) {
                continue;
            }
            // Divi and WPBakery store an explicit off state rather than
            // deleting the key, so a bare "is it set" check flags every post
            // that was ever opened in the builder.
            if ( '_et_pb_use_builder' === $key && 'on' !== $value ) {
                continue;
            }
            if ( '_wpb_vc_js_status' === $key && 'true' !== $value ) {
                continue;
            }
            return $label;
        }
        return '';
    }

    private static function is_ignored_anchor( string $anchor ): bool {
        $ignored = (array) get_option( 'ailg_ignore_words', [] );
        $anchor  = strtolower( trim( $anchor ) );
        foreach ( $ignored as $word ) {
            if ( $anchor === strtolower( trim( (string) $word ) ) ) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------- parsing */

    /**
     * Byte ranges of $content that must never be rewritten.
     *
     * @return array<int,array{0:int,1:int}> [start, end) pairs, ascending, merged.
     */
    public static function protected_ranges( string $content ): array {
        $patterns = [
            // HTML comments. Also how Gutenberg delimits blocks, and their
            // JSON attributes are the easiest thing in a post to corrupt.
            '/<!--.*?-->/s',
            // Anything whose text is not prose.
            '/<(script|style|pre|code|textarea|svg)\b[^>]*>.*?<\/\1>/is',
            // Existing links, element and contents - a link inside a link is
            // invalid HTML and browsers silently tear it apart.
            '/<a\b[^>]*>.*?<\/a>/is',
            // Headings: an anchor here looks automated and dilutes the
            // heading's own keyword signal.
            '/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is',
            '/<figcaption\b[^>]*>.*?<\/figcaption>/is',
            // Every remaining tag, so no match lands inside an attribute such
            // as alt="", title="" or a data- payload.
            '/<[^>]*>/s',
            // Shortcodes, including their arguments.
            '/\[[^\]\[]{0,300}\]/s',
        ];

        $ranges = [];
        foreach ( $patterns as $pattern ) {
            if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
                continue;
            }
            foreach ( $matches[0] as $match ) {
                $ranges[] = [ $match[1], $match[1] + strlen( $match[0] ) ];
            }
        }

        return self::merge_ranges( $ranges );
    }

    private static function merge_ranges( array $ranges ): array {
        if ( ! $ranges ) {
            return [];
        }

        usort( $ranges, static fn( $a, $b ) => $a[0] <=> $b[0] );

        $merged  = [];
        $current = array_shift( $ranges );
        foreach ( $ranges as $range ) {
            if ( $range[0] <= $current[1] ) {
                $current[1] = max( $current[1], $range[1] );
                continue;
            }
            $merged[] = $current;
            $current  = $range;
        }
        $merged[] = $current;

        return $merged;
    }

    /** Is [start, start+length) entirely outside every protected range? */
    private static function is_free( int $start, int $length, array $ranges ): bool {
        $end = $start + $length;
        foreach ( $ranges as $range ) {
            if ( $range[0] >= $end ) {
                return true; // Sorted, so nothing further can overlap.
            }
            if ( $range[1] > $start ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Find the first safe occurrence of $anchor.
     *
     * The model's anchor and the stored content rarely match byte for byte:
     * entities, curly apostrophes and collapsed newlines all differ. Rather
     * than normalise the content (which loses the offsets needed to splice),
     * the anchor becomes a tolerant pattern matched against the content as it
     * actually is.
     *
     * @return array{offset:int,length:int}|null
     */
    public static function locate( string $content, string $anchor, array $blocked ): ?array {
        $anchor = trim( preg_replace( '/\s+/u', ' ', $anchor ) );
        if ( mb_strlen( $anchor ) < 3 ) {
            return null;
        }

        if ( ! preg_match_all( self::anchor_pattern( $anchor ), $content, $matches, PREG_OFFSET_CAPTURE ) ) {
            return null;
        }

        foreach ( $matches[0] as $match ) {
            $offset = $match[1];
            $length = strlen( $match[0] );
            if ( self::is_free( $offset, $length, $blocked ) ) {
                return [ 'offset' => $offset, 'length' => $length ];
            }
        }

        return null;
    }

    /**
     * A forgiving but still literal pattern for an anchor phrase. Whitespace
     * becomes \s+, an ampersand also matches its entity form, and the whole
     * thing is bounded so "cost" never matches inside "costume".
     */
    private static function anchor_pattern( string $anchor ): string {
        $quoted = preg_quote( $anchor, '/' );

        // preg_quote escapes a space as "\ " under some PHP configurations and
        // leaves it bare in others; handle both.
        $quoted = preg_replace( '/(\\\\ |\s)+/', '\\s+', $quoted );
        $quoted = str_replace( '&', '(?:&|&amp;)', $quoted );
        $quoted = str_replace( "'", "(?:'|&#0?39;|&apos;|\u{2019})", $quoted );
        $quoted = str_replace( '"', '(?:"|&quot;|&#0?34;)', $quoted );

        // \b is wrong here: an anchor may begin or end with a non-word
        // character. Assert on the neighbouring character instead, so a match
        // is rejected only when it would split a word.
        return '/(?<![\p{L}\p{N}])' . $quoted . '(?![\p{L}\p{N}])/iu';
    }

    /**
     * Does this content already link to $url? Compares paths, so http/https
     * and trailing-slash variants of the same page all count as linked.
     *
     * The old check was strpos( $content, esc_url( $url ) ), which reports a
     * link whenever one URL is a prefix of another - /guide inside
     * /guide-to-everything - and misses relative hrefs entirely.
     */
    public static function links_to( string $content, string $url ): bool {
        if ( '' === $content || false === strpos( $content, 'href' ) ) {
            return false;
        }

        $target_path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        $target_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $home_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

        if ( ! preg_match_all( '/<a\s[^>]*href\s*=\s*([\'"])(.*?)\1/is', $content, $matches ) ) {
            return false;
        }

        foreach ( $matches[2] as $raw_href ) {
            $href = trim( html_entity_decode( $raw_href, ENT_QUOTES, 'UTF-8' ) );
            if ( '' === $href || str_starts_with( $href, '#' ) || preg_match( '/^(mailto|tel|javascript):/i', $href ) ) {
                continue;
            }

            $href_host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
            $href_path = untrailingslashit( (string) wp_parse_url( $href, PHP_URL_PATH ) );

            // Check if internal (relative or matches home host)
            $is_internal = ( '' === $href_host || $href_host === $home_host );

            if ( $is_internal ) {
                if ( $target_path === $href_path ) {
                    return true;
                }
            } elseif ( $href_host === $target_host && $target_path === $href_path ) {
                return true;
            }
        }

        return false;
    }

    /** A short before/after excerpt for the dry-run preview. */
    private static function preview( string $content, array $hit ): string {
        $start = max( 0, $hit['offset'] - 90 );
        $len   = $hit['length'] + 180;
        return trim( wp_strip_all_tags( substr( $content, $start, $len ) ) );
    }

    /* ------------------------------------------------------------- writing */

    /**
     * Write content back without letting kses eat it.
     *
     * wp_update_post() runs content through wp_filter_post_kses for any
     * request with no user holding unfiltered_html - which is every cron run.
     * On a site using iframes, embeds or inline SVG that silently strips
     * markup the author put there, and the post comes back smaller than it
     * went in. Filters are removed for the write and restored immediately,
     * exactly as core does for imports.
     *
     * @return true|WP_Error
     */
    public static function save_content( int $post_id, string $content ): bool|WP_Error {
        $filtered = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
        if ( $filtered ) {
            kses_remove_filters();
        }

        $result = wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ], true );

        if ( $filtered ) {
            kses_init_filters();
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( ! $result ) {
            return new WP_Error( 'ailg_link', 'WordPress refused the content update.' );
        }

        return true;
    }
}
