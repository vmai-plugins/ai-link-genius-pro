<?php
/**
 * The real internal link graph.
 *
 * `ailg_links` only ever contained links this plugin inserted itself. Every
 * report was built on it anyway, so on any site with existing content they
 * were all wrong in the same direction:
 *
 *   - "Orphaned Content" listed every post nothing *we* had linked to, which
 *     on a fresh install is the entire site.
 *   - Inbound and outbound counts in Reports counted our own insertions only.
 *   - The Link Map drew a graph of our edges, not the site's.
 *   - The semantic audit measured pillar coverage against our edges, so a
 *     perfectly linked silo reported 0% coverage.
 *
 * This reads the content that is actually there. `ailg_links` keeps its old
 * job of recording what the plugin did; `ailg_link_index` is the truth about
 * the site, rebuilt from post content and refreshed on save.
 */

defined( 'ABSPATH' ) || exit;

class AILG_LinkIndex {

    const SCAN_META    = '_ailg_link_scan';
    const READY_OPTION = 'ailg_link_index_built';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ailg_link_index';
    }

    /**
     * Is the index table actually there?
     *
     * The schema is created on activation and on a version bump, but neither
     * is guaranteed to have run by the time a hook fires: a plugin folder
     * updated in place keeps its stored db_version, a restricted database
     * user can make dbDelta fail silently, and deleted_post fires on the
     * front end where nobody is watching for a fatal. Without this, one
     * missing table turned every post deletion into "Table doesn't exist".
     *
     * Checked once per request, then cached for an hour.
     */
    public static function table_ready(): bool {
        static $ready = null;

        if ( null !== $ready ) {
            return $ready;
        }
        if ( '1' === get_transient( 'ailg_link_index_ready' ) ) {
            $ready = true;
            return true;
        }

        global $wpdb;
        $table = self::table();
        $ready = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

        if ( $ready ) {
            set_transient( 'ailg_link_index_ready', '1', HOUR_IN_SECONDS );
        } elseif ( class_exists( 'AILG_Core' ) ) {
            // Self-heal once rather than failing for the rest of the request.
            AILG_Core::create_tables();
            $ready = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
        }

        return $ready;
    }

    public static function boot(): void {
        add_action( 'save_post', [ __CLASS__, 'on_save' ], 20, 2 );
        add_action( 'deleted_post', [ __CLASS__, 'on_delete' ] );
    }

    public static function on_save( int $post_id, $post = null ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( $post && 'publish' !== $post->post_status ) {
            self::purge_source( $post_id );
            return;
        }
        self::scan_post( $post_id );
    }

    public static function on_delete( int $post_id ): void {
        if ( ! self::table_ready() ) {
            return;
        }
        global $wpdb;
        $table = self::table();
        $wpdb->delete( $table, [ 'source_id' => $post_id ] );
        // Links pointing at the deleted post are now internal links to
        // nowhere, which is exactly what the broken-link report should see.
        $wpdb->update( $table, [ 'target_id' => 0 ], [ 'target_id' => $post_id ] );
    }

    /* ------------------------------------------------------------ scanning */

    /** Parse one post's links and replace its rows. */
    public static function scan_post( int $post_id ): int {
        if ( ! self::table_ready() ) {
            return 0;
        }
        global $wpdb;

        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            self::purge_source( $post_id );
            return 0;
        }

        $rows = self::parse( $post->post_content );
        self::purge_source( $post_id );

        $now = current_time( 'mysql', true );
        foreach ( $rows as $row ) {
            $wpdb->insert( self::table(), [
                'source_id'   => $post_id,
                'source_type' => $post->post_type,
                'target_id'   => $row['target_id'],
                'target_url'  => $row['url'],
                'target_host' => $row['host'],
                'anchor_text' => mb_substr( $row['anchor'], 0, 250 ),
                'is_internal' => $row['internal'] ? 1 : 0,
                'is_managed'  => $row['managed'] ? 1 : 0,
                'created_at'  => $now,
            ] );
        }

        update_post_meta( $post_id, self::SCAN_META, time() );

        return count( $rows );
    }

    /**
     * Walk the site a batch at a time.
     *
     * @return array{scanned:int,links:int,remaining:int}
     */
    public static function scan_batch( int $limit = 40 ): array {
        if ( ! self::table_ready() ) {
            return [ 'scanned' => 0, 'links' => 0, 'remaining' => 0 ];
        }
        global $wpdb;

        $types = self::post_types();
        $in    = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";

        // Never scanned, or scanned before the post was last edited.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_status = 'publish' AND p.post_type IN ({$in})
               AND ( m.meta_value IS NULL OR m.meta_value < UNIX_TIMESTAMP( p.post_modified_gmt ) )
             ORDER BY p.post_modified_gmt DESC
             LIMIT %d",
            self::SCAN_META,
            $limit
        ) );

        $links = 0;
        foreach ( $ids as $id ) {
            $links += self::scan_post( (int) $id );
        }

        $remaining = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_status = 'publish' AND p.post_type IN ({$in})
               AND ( m.meta_value IS NULL OR m.meta_value < UNIX_TIMESTAMP( p.post_modified_gmt ) )",
            self::SCAN_META
        ) );

        if ( 0 === $remaining ) {
            update_option( self::READY_OPTION, time(), false );
        }

        delete_transient( 'ailg_link_authority' );

        return [ 'scanned' => count( $ids ), 'links' => $links, 'remaining' => $remaining ];
    }

    public static function ajax_run_batch(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $result = self::scan_batch( 50 );
        wp_send_json_success( $result );
    }

    /** Throw the index away and start again - e.g. after a permalink change. */
    public static function rebuild(): bool {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . self::table() );
        $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => self::SCAN_META ] );
        delete_option( self::READY_OPTION );
        delete_transient( 'ailg_link_authority' );
        return true;
    }

    public static function is_ready(): bool {
        return (bool) get_option( self::READY_OPTION );
    }

    /** How much of the site has been read, for the progress UI. */
    public static function progress(): array {
        global $wpdb;
        $types = self::post_types();
        $in    = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";

        $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ({$in})" );
        $done   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_status='publish' AND p.post_type IN ({$in})
               AND m.meta_value >= UNIX_TIMESTAMP( p.post_modified_gmt )",
            self::SCAN_META
        ) );

        return [
            'total'   => $total,
            'done'    => $done,
            'percent' => $total ? (int) round( ( $done / $total ) * 100 ) : 100,
            'ready'   => self::is_ready(),
        ];
    }

    private static function purge_source( int $post_id ): void {
        if ( ! self::table_ready() ) {
            return;
        }
        global $wpdb;
        $wpdb->delete( self::table(), [ 'source_id' => $post_id ] );
    }

    private static function post_types(): array {
        $types = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        $types = array_filter( array_map( 'sanitize_key', $types ) );
        return $types ?: [ 'post', 'page' ];
    }

    /**
     * Pull every anchor out of a chunk of content.
     *
     * @return array<int,array{url:string,host:string,anchor:string,internal:bool,target_id:int,managed:bool}>
     */
    public static function parse( string $content ): array {
        if ( ! $content || false === strpos( $content, '<a' ) ) {
            return [];
        }

        if ( ! preg_match_all( '/<a\s[^>]*href\s*=\s*([\'"])(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
            return [];
        }

        $home = self::home_host();
        $out  = [];
        $seen = [];

        foreach ( $matches as $match ) {
            $url = trim( html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' ) );

            if ( '' === $url || str_starts_with( $url, '#' ) || preg_match( '/^(mailto|tel|javascript|data):/i', $url ) ) {
                continue;
            }

            $host     = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
            $internal = ( '' === $host || $host === $home );
            $target   = $internal ? self::resolve( $url ) : 0;

            // One edge per destination per post - and the destination is the
            // page, not the URL string. A post that links to the same article
            // as "/post/", "/post/#respond" and "/post/#comments" is linking
            // to it once; counting three inflated its inbound total and, with
            // it, its PageRank. Fall back to the fragment-stripped URL when
            // the target does not resolve to a post.
            $key = $target ?: strtok( $url, '#' );

            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;

            $out[] = [
                'url'       => esc_url_raw( $url ),
                'host'      => mb_substr( $host ?: $home, 0, 190 ),
                'anchor'    => trim( wp_strip_all_tags( $match[3] ) ),
                'internal'  => $internal,
                'target_id' => $target,
                'managed'   => str_contains( $match[0], 'data-ailg' ),
            ];
        }

        return $out;
    }

    /**
     * URL to post ID, memoised. url_to_postid() runs its own query every call
     * and one content-heavy post can link to the same handful of pages a
     * dozen times.
     */
    private static function resolve( string $url ): int {
        static $cache = [];

        // For plain permalinks (?p=123), there is no path, so we use the
        // whole URL as the key. For pretty permalinks, untrailingslashit path.
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $key  = $path ? untrailingslashit( $path ) : $url;

        if ( isset( $cache[ $key ] ) ) {
            return $cache[ $key ];
        }

        $id = (int) url_to_postid( $url );

        // Handle root-relative URLs
        if ( ! $id && str_starts_with( $url, '/' ) ) {
            $id = (int) url_to_postid( home_url( $url ) );
        }

        $cache[ $key ] = $id;
        return $id;
    }

    private static function home_host(): string {
        static $host = null;
        if ( null === $host ) {
            $host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        }
        return $host;
    }

    /* --------------------------------------------------------------- reads */

    public static function has_link( int $source_id, int $target_id ): bool {
        if ( ! self::table_ready() ) {
            return false;
        }
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . self::table() . ' WHERE source_id = %d AND target_id = %d LIMIT 1',
            $source_id,
            $target_id
        ) );
    }

    public static function inbound_count( int $post_id ): int {
        if ( ! self::table_ready() ) {
            return 0;
        }
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE target_id = %d AND is_internal = 1',
            $post_id
        ) );
    }

    public static function outbound_count( int $post_id ): int {
        if ( ! self::table_ready() ) {
            return 0;
        }
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE source_id = %d AND is_internal = 1',
            $post_id
        ) );
    }

    /** Every post that links to $post_id - the report the editor never had. */
    public static function inbound( int $post_id, int $limit = 50 ): array {
        if ( ! self::table_ready() ) {
            return [];
        }
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT l.source_id, l.anchor_text, p.post_title
             FROM ' . self::table() . " l
             INNER JOIN {$wpdb->posts} p ON p.ID = l.source_id
             WHERE l.target_id = %d AND l.is_internal = 1
             ORDER BY p.post_date DESC LIMIT %d",
            $post_id,
            $limit
        ) );
    }

    /** Published posts nothing links to. */
    public static function orphans( int $limit = 200 ): array {
        if ( ! self::table_ready() ) {
            return [];
        }
        global $wpdb;

        $in   = "'" . implode( "','", array_map( 'esc_sql', self::post_types() ) ) . "'";
        $skip = array_filter( [ (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ] );
        $skip = $skip ? ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $skip ) ) . ')' : '';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type, p.post_date
             FROM {$wpdb->posts} p
             LEFT JOIN " . self::table() . " l ON l.target_id = p.ID AND l.is_internal = 1
             WHERE p.post_status = 'publish' AND p.post_type IN ({$in}){$skip}
             GROUP BY p.ID
             HAVING COUNT(l.id) = 0
             ORDER BY p.post_date DESC
             LIMIT %d",
            $limit
        ), ARRAY_A );
    }

    /**
     * Posts with some inbound links but not enough. Unlike orphans this is
     * where most of the real ranking upside sits - a page with two inbound
     * links is invisible to the orphan report and still starved.
     */
    public static function underlinked( int $limit = 25, int $threshold = 3 ): array {
        if ( ! self::table_ready() ) {
            return [];
        }
        global $wpdb;
        $in = "'" . implode( "','", array_map( 'esc_sql', self::post_types() ) ) . "'";

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, COUNT(l.id) AS inbound
             FROM {$wpdb->posts} p
             LEFT JOIN " . self::table() . " l ON l.target_id = p.ID AND l.is_internal = 1
             WHERE p.post_status = 'publish' AND p.post_type IN ({$in})
             GROUP BY p.ID
             HAVING inbound > 0 AND inbound < %d
             ORDER BY inbound ASC, p.post_date DESC
             LIMIT %d",
            $threshold,
            $limit
        ), ARRAY_A );
    }

    /**
     * Internal links whose target no longer resolves to anything. This is
     * free broken-internal-link detection: no HTTP request, no timeout, and
     * it is already sitting in the index.
     */
    public static function unresolved( int $limit = 200 ): array {
        if ( ! self::table_ready() ) {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT l.id, l.source_id, l.target_url, l.anchor_text, p.post_title
             FROM ' . self::table() . " l
             LEFT JOIN {$wpdb->posts} p ON p.ID = l.source_id
             WHERE l.is_internal = 1 AND l.target_id = 0
             ORDER BY l.source_id DESC LIMIT %d",
            $limit * 3
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return [];
        }

        $home = untrailingslashit( home_url() );
        $filtered = [];
        foreach ( $rows as $row ) {
            $url = $row['target_url'];
            $clean = untrailingslashit( $url );
            if ( $clean === $home || '' === $url || '/' === $url ) {
                continue;
            }
            $path = (string) wp_parse_url( $url, PHP_URL_PATH );
            if ( '' === $path || '/' === $path ) {
                continue;
            }
            if ( preg_match( '#/(category|tag|author|feed|wp-content|wp-admin|wp-json|cart|checkout|shop|my-account)/#i', $path ) ) {
                continue;
            }
            $filtered[] = $row;
            if ( count( $filtered ) >= $limit ) {
                break;
            }
        }

        return $filtered;
    }

    /**
     * Anchors used so often against one target that they read as manipulation
     * rather than editorial.
     */
    public static function anchor_report( int $limit = 25, int $min_uses = 3 ): array {
        if ( ! self::table_ready() ) {
            return [];
        }
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT anchor_text, COUNT(*) AS uses, COUNT(DISTINCT target_id) AS targets
             FROM ' . self::table() . "
             WHERE is_internal = 1 AND anchor_text != '' AND anchor_text NOT REGEXP '^[0-9]+$'
             GROUP BY anchor_text
             HAVING uses >= %d
             ORDER BY uses DESC LIMIT %d",
            $min_uses,
            $limit
        ), ARRAY_A );
    }

    public static function stats(): array {
        if ( ! self::table_ready() ) {
            return [
                'total' => 0, 'internal' => 0, 'external' => 0, 'managed' => 0,
                'sources' => 0, 'unresolved' => 0, 'progress' => self::progress(),
            ];
        }

        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS total,
                    SUM(is_internal) AS internal,
                    SUM(CASE WHEN is_internal = 0 THEN 1 ELSE 0 END) AS external,
                    SUM(is_managed) AS managed,
                    COUNT(DISTINCT source_id) AS sources
             FROM {$table}",
            ARRAY_A
        );

        return [
            'total'      => (int) ( $row['total'] ?? 0 ),
            'internal'   => (int) ( $row['internal'] ?? 0 ),
            'external'   => (int) ( $row['external'] ?? 0 ),
            'managed'    => (int) ( $row['managed'] ?? 0 ),
            'sources'    => (int) ( $row['sources'] ?? 0 ),
            'unresolved' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_internal = 1 AND target_id = 0" ),
            'progress'   => self::progress(),
        ];
    }

    /* ----------------------------------------------------------- authority */

    /**
     * Internal PageRank over the site's own link graph.
     *
     * This is the number an internal linking tool should be driven by, and the
     * one thing no keyword tool can tell you: which of your pages actually has
     * authority to pass, and which are starved of it. The Reports screen
     * scored pages with
     *
     *     ( inbound * 60 + outbound * 40 ) / ( inbound + outbound )
     *
     * which is a weighted average of two numbers that always lands between 40
     * and 60 regardless of the values - a page with 1 inbound and a page with
     * 500 score within a few points of each other.
     *
     * Standard formulation: rank = (1-d)/N + d * sum( rank(in) / outdegree(in) ),
     * with dangling nodes' mass redistributed so the total stays 1.
     *
     * @return array<int,float> post ID => score, normalised to 0-100, descending.
     */
    public static function authority( bool $force = false ): array {
        if ( ! self::table_ready() ) {
            return [];
        }
        if ( ! $force ) {
            $cached = get_transient( 'ailg_link_authority' );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        global $wpdb;
        $edges = $wpdb->get_results(
            'SELECT source_id, target_id FROM ' . self::table() . '
             WHERE is_internal = 1 AND target_id > 0 AND source_id != target_id
             LIMIT 200000',
            ARRAY_A
        );

        if ( ! $edges ) {
            return [];
        }

        $out   = [];
        $in    = [];
        $nodes = [];

        foreach ( $edges as $edge ) {
            $s = (int) $edge['source_id'];
            $t = (int) $edge['target_id'];

            $nodes[ $s ] = true;
            $nodes[ $t ] = true;

            $out[ $s ]  = ( $out[ $s ] ?? 0 ) + 1;
            $in[ $t ][] = $s;
        }

        $n = count( $nodes );
        if ( $n < 2 ) {
            return [];
        }

        $damping = 0.85;
        $base    = 1 / $n;
        $rank    = array_fill_keys( array_keys( $nodes ), $base );

        for ( $i = 0; $i < 25; $i++ ) {
            // Mass held by pages with no outbound links would otherwise leak
            // out of the system on every pass.
            $dangling = 0.0;
            foreach ( $rank as $id => $score ) {
                if ( empty( $out[ $id ] ) ) {
                    $dangling += $score;
                }
            }

            $next  = [];
            $floor = ( 1 - $damping ) * $base + $damping * $dangling * $base;

            foreach ( $nodes as $id => $unused ) {
                $sum = 0.0;
                foreach ( ( $in[ $id ] ?? [] ) as $source ) {
                    $sum += $rank[ $source ] / $out[ $source ];
                }
                $next[ $id ] = $floor + $damping * $sum;
            }

            $rank = $next;
        }

        $max = max( $rank ) ?: 1;
        foreach ( $rank as $id => $score ) {
            $rank[ $id ] = round( ( $score / $max ) * 100, 2 );
        }
        arsort( $rank );

        set_transient( 'ailg_link_authority', $rank, 6 * HOUR_IN_SECONDS );

        return $rank;
    }

    /**
     * The pages best placed to hand authority to something else: high internal
     * rank, and not already spending it across dozens of outbound links.
     */
    public static function donors( int $limit = 10, int $max_outbound = 15 ): array {
        $authority = self::authority();
        if ( ! $authority ) {
            return [];
        }

        $out = [];
        foreach ( $authority as $id => $score ) {
            if ( count( $out ) >= $limit ) {
                break;
            }
            $post = get_post( $id );
            if ( ! $post || 'publish' !== $post->post_status ) {
                continue;
            }
            $outbound = self::outbound_count( (int) $id );
            if ( $outbound > $max_outbound ) {
                continue;
            }
            $out[] = [
                'ID'        => (int) $id,
                'title'     => $post->post_title,
                'authority' => $score,
                'outbound'  => $outbound,
                'edit_url'  => get_edit_post_link( $id, 'raw' ),
            ];
        }

        return $out;
    }
}
