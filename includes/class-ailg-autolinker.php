<?php
/**
 * Auto-Linker Class
 */

defined( 'ABSPATH' ) || exit;

class AILG_AutoLinker {

    public static function process_content( string $content ): string {
        if ( ! get_option( 'ailg_auto_link_enabled' ) ) return $content;
        if ( ! is_singular() ) return $content;

        $post_id = get_the_ID();
        if ( ! $post_id ) return $content;

        global $wpdb;
        $links = $wpdb->get_results( $wpdb->prepare(
            "SELECT anchor_text, target_url FROM {$wpdb->prefix}ailg_links
             WHERE post_id = %d AND link_type = 'internal'",
            $post_id
        ) );
        if ( empty( $links ) ) return $content;

        $limit     = (int) get_option( 'ailg_link_limit_per_post', 10 );
        $same_max  = (int) get_option( 'ailg_same_link_limit', 2 );
        $inserted  = 0;
        $used_urls = [];

        foreach ( $links as $link ) {
            if ( $inserted >= $limit ) break;
            $url    = (string) $link->target_url;
            $anchor = (string) $link->anchor_text;
            if ( ( $used_urls[ $url ] ?? 0 ) >= $same_max ) continue;
            if ( false !== strpos( $content, esc_url( $url ) ) ) {
                $used_urls[ $url ] = ( $used_urls[ $url ] ?? 0 ) + 1;
                continue;
            }
            $html    = '<a href="' . esc_url( $url ) . '">' . esc_html( $anchor ) . '</a>';
            $new     = AILG_LinkHelper::insert( $content, $anchor, $html );
            if ( $new !== $content ) {
                $content = $new;
                $used_urls[ $url ] = ( $used_urls[ $url ] ?? 0 ) + 1;
                $inserted++;
            }
        }

        return $content;
    }
}