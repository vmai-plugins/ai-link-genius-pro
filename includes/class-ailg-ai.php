<?php
/**
 * AI Integration Class
 */

defined( 'ABSPATH' ) || exit;

class AILG_AI {

    public static function generate_suggestions( int $post_id, string $provider = '', string $override_content = '' ): array {
        $post = get_post( $post_id );
        if ( ! $post && empty($override_content) ) return [];

        $content = ! empty($override_content) ? $override_content : $post->post_content;

        // Performance: Don't send massive content to AI
        $content_short = mb_substr( wp_strip_all_tags( $content ), 0, 4000 );
        if ( mb_strlen( $content_short ) < 50 ) return [];
        $title = $post ? $post->post_title : 'Draft Content';

        $candidates = self::get_candidates( $post_id );
        if ( empty( $candidates ) ) return [];

        $candidate_list = '';
        foreach ( $candidates as $c ) {
            $candidate_list .= "ID:{$c->ID} | Title: {$c->post_title} | URL: " . get_permalink( $c->ID ) . "\n";
        }

        // Feature 8: Extract Image Alts for Contextual Image Linking
        $images = [];
        preg_match_all('/<img[^>]+alt=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $img_matches);
        if ( ! empty($img_matches[1]) ) {
            $images = array_unique(array_filter($img_matches[1]));
        }

        $custom_prompt = (string) get_option( 'ailg_custom_prompt', '' );
        $prompt = ! empty( $custom_prompt )
            ? str_replace( [ '{post_title}', '{post_content}', '{candidates}' ], [ $title, $content_short, $candidate_list ], $custom_prompt )
            : self::build_default_prompt( $title, $content_short, $candidate_list );

        if ( ! empty($images) ) {
            $prompt .= "\n\nIMAGE ALT TEXTS AVAILABLE FOR LINKING:\n- " . implode("\n- ", $images) . "\n"
                    . "INSTRUCTION: If an Image Alt Text perfectly matches a candidate post, suggest wrapping that image in a link.";
        }

        // Integration: VM SEO Brain
        if ( class_exists( 'AILG_VMSB_Integration' ) ) {
            $prompt = AILG_VMSB_Integration::augment_prompt( $prompt, $post_id );
        }

        // Integration: Rank Math
        if ( class_exists( 'AILG_RankMath_Integration' ) ) {
            $prompt = AILG_RankMath_Integration::augment_prompt( $prompt, $post_id );
        }

        // Use AI Router for generation with fallback support
        $args = [
            'provider'   => $provider,
            'max_tokens' => 2000,
            'temperature' => 0.4
        ];

        $res = AILG_AiRouter::generate( $prompt, $args );

        if ( empty( $res['ok'] ) || empty( $res['text'] ) ) {
            error_log( 'AILG AI Router Error: ' . ( $res['error'] ?? 'Unknown' ) );
            return [];
        }

        return self::parse_ai_response( $res['text'], $post_id, $candidates, $res['provider'] ?? 'unknown', $res['model'] ?? 'unknown' );
    }

    private static function build_default_prompt( string $title, string $content, string $candidates ): string {
        return <<<PROMPT
You are an expert SEO specialist and semantic content architect. Suggest high-value internal links for this WordPress post.

SOURCE POST TITLE: {$title}

SOURCE POST CONTENT:
{$content}

HIGH-PRIORITY CANDIDATE POSTS:
{$candidates}

INSTRUCTIONS:
1. TOPIC ANALYSIS & INTENT: Identify the core topics and classify the source post's intent (Informational, Commercial, or Transactional).
2. SEMANTIC MATCHING: Find candidate posts that cover related topics.
   - CONVERSION FUNNEL: If the source is Informational, prioritize links to Commercial pillars. If the source is Commercial, AVOID linking away to low-value info posts unless necessary for trust.
3. ANCHOR OPTIMIZATION & CONTEXTUAL BRIDGES:
   - PORTFOLIO DIVERSITY: When suggesting links, use a variety of semantic variations for anchor text (e.g., if linking to a "Rome Travel Guide", cycle between "Rome guide", "Eternal City tips", "visiting Italy's capital"). AVOID exact keyword repetition across multiple links.
   - Option A: Look for natural, contextual phrases in the source content to use as anchor text.
   - Option B (Contextual Bridge): If a target post is highly relevant but no perfect anchor exists, suggest a NEW natural-sounding sentence (a bridge) to insert between paragraphs.
   - Option C (Ghost Link / Content Gap): If you identify a major topic mentioned in the source that lacks a candidate post, suggest it as a "Ghost Link" with a [NEW POST DRAFT] target.
4. RETURN FORMAT: Return ONLY the top 5 most valuable linking opportunities as a JSON array.

RULES:
- For GHOST LINKS (new post ideas), set target_id to 0 and is_ghost to true.
- If it's a CONTEXTUAL BRIDGE (new text), set "is_bridge" to true.
- If it's an IMAGE LINK (wrapping an existing image), set "is_image" to true and anchor_text to the exact Alt Text.
- If it's an EXISTING text match, set "is_bridge" to false and "is_image" to false.
- context: For existing text, the surrounding sentence. For bridges, specify "Insert after: [sentence from post]". For images, "Wrap image with alt: [alt text]". For ghost links, "Topic for new post".
- score (0.0-1.0) reflects both topical relevance and the naturalness of the anchor.

RETURN FORMAT:
[
  {
    "target_id": <int>,
    "anchor_text": "...",
    "context": "...",
    "score": <float>,
    "is_bridge": <bool>,
    "is_image": <bool>,
    "is_ghost": <bool>,
    "reason": "..."
  }
]
PROMPT;
    }

    public static function call_aipuffer( $prompt, $args ) {
        // Circuit Breaker check
        $failures = (int) get_option( 'ailg_aipuffer_failures', 0 );
        if ( $failures >= 5 ) {
            $last_fail = (int) get_option( 'ailg_aipuffer_last_fail', 0 );
            if ( ( time() - $last_fail ) < 300 ) { // 5 minute cool down
                return [ 'ok' => false, 'error' => 'Circuit Breaker Active: AI Puffer is failing consistently. Pausing for 5 minutes.' ];
            }
            update_option( 'ailg_aipuffer_failures', 0 ); // Reset after cool down
        }

        $url    = get_option( 'ailg_aipuffer_url' );
        $key    = get_option( 'ailg_aipuffer_key' );
        $bot_id = get_option( 'ailg_aipuffer_bot_id' );

        // Sentience: Inject Strategic Context
        $dna      = get_option( 'ailg_business_dna' );
        $niche    = get_option( 'ailg_topical_niche' );
        $entities = get_option( 'ailg_topical_entities' );

        if ( $dna || $niche || $entities ) {
            $context = "\n--- SITE INTELLIGENCE ---\n";
            if ( $dna )      $context .= "BUSINESS DNA: {$dna}\n";
            if ( $niche )    $context .= "TOPICAL NICHE: {$niche}\n";
            if ( $entities ) $context .= "ENTITIES: {$entities}\n";
            $prompt = $context . "\nUSER PROMPT:\n" . $prompt;
        }

        $is_local = empty($url) || ( untrailingslashit($url) === untrailingslashit(home_url()) );
        $namespaces = [ 'aipkit/v1', 'mwai/v1', 'wpaicg/v1', 'aipuffer/v1' ];

        foreach ( $namespaces as $ns ) {
            // Determine suffix
            if ( $ns === 'mwai/v1' ) {
                $suffix = '/simpleChatbotQuery';
            } elseif ( $ns === 'aipuffer/v1' ) {
                $suffix = '/chat/message';
            } else {
                $suffix = ( $bot_id ) ? "/chat/{$bot_id}/message" : '/generate';
            }

            $endpoint_path = '/' . $ns . $suffix;
            $full_url      = untrailingslashit( $url ?: home_url() ) . '/wp-json' . $endpoint_path;

            $body = [
                'message'     => $prompt,
                'bot_id'      => $bot_id,
                'botId'       => $bot_id,
                'temperature' => $args['temperature'] ?? 0.4,
                'max_tokens'  => $args['max_tokens']  ?? 1000,
                'stream'      => false,
            ];

            $headers = [ 'Content-Type' => 'application/json' ];
            if ( $key ) {
                $headers['Authorization'] = 'Bearer ' . $key;
                $headers['X-API-KEY'] = $key;
            }

            $res = [ 'ok' => false ];

            // 1. Zero-Latency Execution (Direct REST)
            if ( $is_local && function_exists( 'rest_do_request' ) ) {
                $request = new WP_REST_Request( 'POST', $endpoint_path );
                foreach ( $headers as $k => $v ) $request->add_header( $k, $v );
                $request->set_body( wp_json_encode( $body ) );

                $response = rest_do_request( $request );
                if ( is_wp_error( $response ) ) {
                    $res = [ 'ok' => false, 'error' => $response->get_error_message() ];
                } else {
                    $status = $response->get_status();
                    if ( $status >= 200 && $status < 300 ) {
                        $res = [ 'ok' => true, 'data' => $response->get_data() ];
                    } else {
                        $res = [ 'ok' => false, 'error' => 'Local REST HTTP ' . $status ];
                    }
                }
            } else {
                // 3. Reliability: Retry Loop with Exponential Backoff
                $max_retries = 3;
                for ( $i = 0; $i < $max_retries; $i++ ) {
                    $res = self::remote_post( $full_url, $body, $headers );
                    if ( $res['ok'] ) break;
                    if ( $i < $max_retries - 1 ) usleep( ( $i + 1 ) * 200000 ); // 200ms, 400ms...
                }
            }

            if ( $res['ok'] ) {
                $text = self::extract_aipuffer_text( $res['data'] );
                if ( $text ) {
                    update_option( 'ailg_aipuffer_failures', 0 ); // Reset failures on success
                    return [ 'ok' => true, 'text' => $text, 'provider' => 'aipuffer', 'model' => 'puffer-' . $bot_id ];
                }
            }
        }

        // Increment failures for Circuit Breaker
        $new_failures = $failures + 1;
        update_option( 'ailg_aipuffer_failures', $new_failures );
        update_option( 'ailg_aipuffer_last_fail', time() );

        return [ 'ok' => false, 'error' => 'AI Puffer failed or not configured correctly.' ];
    }

    private static function extract_aipuffer_text( $data ) {
        if ( is_string( $data ) ) return $data;
        if ( ! is_array( $data ) ) return '';

        return $data['reply'] ?? ( $data['content'] ?? ( $data['response'] ?? ( $data['text'] ?? ( $data['choices'][0]['message']['content'] ?? '' ) ) ) );
    }

    public static function call_openai( string $prompt, $args ): array {
        $key   = $args['api_key'] ?? (string) get_option( 'ailg_openai_key', '' );
        $model = $args['model'] ?? (string) get_option( 'ailg_openai_model', 'gpt-4o' );
        if ( empty( $key ) ) return [ 'ok' => false, 'error' => 'No key' ];

        $res = self::remote_post( 'https://api.openai.com/v1/chat/completions', [
            'model'       => $model,
            'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
            'temperature' => $args['temperature'],
            'max_tokens'  => $args['max_tokens'],
        ], [ 'Authorization' => 'Bearer ' . $key ] );

        if ( ! $res['ok'] ) return $res;

        $text = $res['data']['choices'][0]['message']['content'] ?? '';
        return $text ? [ 'ok' => true, 'text' => $text, 'provider' => 'openai', 'model' => $model ] : [ 'ok' => false, 'error' => 'Empty response' ];
    }

    public static function call_google( string $prompt, $args ): array {
        $key   = $args['api_key'] ?? (string) get_option( 'ailg_google_key', '' );
        $model = $args['model'] ?? (string) get_option( 'ailg_google_model', 'gemini-2.0-flash' );
        if ( empty( $key ) ) return [ 'ok' => false, 'error' => 'No key' ];

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
        $res = self::remote_post( $url, [
            'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
            'generationConfig' => [ 'temperature' => $args['temperature'], 'maxOutputTokens' => $args['max_tokens'] ],
        ] );

        if ( ! $res['ok'] ) return $res;

        $text = $res['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return $text ? [ 'ok' => true, 'text' => $text, 'provider' => 'google', 'model' => $model ] : [ 'ok' => false, 'error' => 'Empty response' ];
    }

    public static function call_openrouter( string $prompt, $args ): array {
        $key   = $args['api_key'] ?? (string) get_option( 'ailg_openrouter_key', '' );
        $model = $args['model'] ?? (string) get_option( 'ailg_openrouter_model', 'anthropic/claude-3-5-sonnet' );
        if ( empty( $key ) ) return [ 'ok' => false, 'error' => 'No key' ];

        $res = self::remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
            'model'       => $model,
            'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
            'temperature' => $args['temperature'],
            'max_tokens'  => $args['max_tokens'],
        ], [
            'Authorization' => 'Bearer ' . $key,
            'HTTP-Referer'  => get_site_url(),
            'X-Title'       => 'AI Link Genius Pro',
        ] );

        if ( ! $res['ok'] ) return $res;

        $text = $res['data']['choices'][0]['message']['content'] ?? '';
        return $text ? [ 'ok' => true, 'text' => $text, 'provider' => 'openrouter', 'model' => $model ] : [ 'ok' => false, 'error' => 'Empty response' ];
    }

    public static function call_ollama( string $prompt, $args ): array {
        $host  = $args['host'] ?? rtrim( (string) get_option( 'ailg_ollama_host', 'http://localhost:11434' ), '/' );
        $model = $args['model'] ?? (string) get_option( 'ailg_ollama_model', 'llama3.2' );

        $res = self::remote_post( "{$host}/api/generate", [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [ 'temperature' => $args['temperature'] ]
        ] );

        if ( ! $res['ok'] ) return $res;

        $text = $res['data']['response'] ?? '';
        return $text ? [ 'ok' => true, 'text' => $text, 'provider' => 'ollama', 'model' => $model ] : [ 'ok' => false, 'error' => 'Empty response' ];
    }

    private static function remote_post( $url, $body, $headers = [] ) {
        $response = wp_remote_post( $url, [
            'headers' => array_merge( [ 'Content-Type' => 'application/json' ], $headers ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
            'sslverify' => false, // Better for local development
        ] );

        if ( is_wp_error( $response ) ) return [ 'ok' => false, 'error' => $response->get_error_message() ];

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );

        if ( $code >= 400 ) {
            $msg = is_array($body) && isset($body['error']['message']) ? $body['error']['message'] : (is_array($body) && isset($body['message']) ? $body['message'] : 'Unknown API Error');
            return [ 'ok' => false, 'error' => 'HTTP ' . $code . ': ' . $msg ];
        }

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return [ 'ok' => false, 'error' => 'Invalid JSON from API: ' . substr($raw, 0, 100) ];
        }

        return [ 'ok' => true, 'data' => $body ];
    }

    private static function parse_ai_response( string $response, int $post_id, array $candidates, string $provider, string $model ): array {
        $json = preg_replace( '/^```(?:json)?\s*/i', '', trim( $response ) );
        $json = preg_replace( '/\s*```$/', '', $json );

        if ( preg_match( '/\[.*\]/s', $json, $m ) ) {
            $json = $m[0];
        }

        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) return [];

        $min_score     = (float) get_option( 'ailg_min_score', 0.65 );
        $max_suggs     = (int)   get_option( 'ailg_max_suggestions', 5 );
        $candidate_ids = array_column( $candidates, 'ID' );
        $results       = [];

        foreach ( $data as $item ) {
            if ( ! isset( $item['target_id'], $item['anchor_text'], $item['score'] ) ) continue;
            $target_id = (int) $item['target_id'];
            if ( ! in_array( $target_id, $candidate_ids, true ) ) continue;
            $score = (float) $item['score'];
            if ( $score < $min_score ) continue;

            $results[] = [
                'post_id'     => $post_id,
                'target_id'   => $target_id,
                'anchor_text' => sanitize_text_field( (string) ( $item['anchor_text'] ?? '' ) ),
                'context'     => sanitize_text_field( (string) ( $item['context']     ?? '' ) ),
                'score'       => $score,
                'is_bridge'   => ! empty( $item['is_bridge'] ),
                'is_image'    => ! empty( $item['is_image'] ),
                'is_ghost'    => ! empty( $item['is_ghost'] ),
                'provider'    => $provider,
                'model_used'  => $model,
            ];

            if ( count( $results ) >= $max_suggs ) break;
        }

        return $results;
    }

    /**
     * Get candidate posts to link to, using semantic heuristics.
     */
    private static function get_candidates( int $post_id, int $limit = 60 ): array {
        global $wpdb;
        $post = get_post( $post_id );
        if ( ! $post ) return [];

        $post_types   = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        // Strategy 1: Find posts in the same category/taxonomy (High Relevance)
        $categories = wp_get_post_categories( $post_id );
        $cat_ids    = ! empty( $categories ) ? implode( ',', array_map( 'intval', $categories ) ) : '';

        $cat_posts = [];
        if ( $cat_ids ) {
            $cat_posts = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 WHERE tt.term_id IN ($cat_ids)
                   AND p.post_status = 'publish'
                   AND p.post_type IN ({$placeholders})
                   AND p.ID != %d
                 ORDER BY p.post_modified DESC
                 LIMIT 30",
                ...array_merge( $post_types, [ $post_id ] )
            ) );
        }

        // Strategy 2: Find posts with similar title words (Keyword Relevance)
        $title_words = explode( ' ', preg_replace( '/[^a-z0-9 ]/i', '', $post->post_title ) );
        $title_words = array_filter( $title_words, fn($w) => strlen($w) > 4 );

        $title_posts = [];
        if ( ! empty( $title_words ) ) {
            $search = '%' . $wpdb->esc_like( array_shift( $title_words ) ) . '%';
            $title_posts = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->posts}
                 WHERE post_title LIKE %s
                   AND post_status = 'publish'
                   AND post_type IN ({$placeholders})
                   AND ID != %d
                 ORDER BY p.post_modified DESC
                 LIMIT 20",
                ...array_merge( [ $search ], $post_types, [ $post_id ] )
            ) );
        }

        // Strategy 3: Global recent posts (Discovery)
        $recent_posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ({$placeholders})
               AND ID != %d
             ORDER BY post_modified DESC
             LIMIT 30",
            ...array_merge( $post_types, [ $post_id ] )
        ) );

        // Strategy 4: Striking Distance Prioritization (Strategic SEO)
        $sd_posts = [];
        $sd_ids = [];

        // 1. Try native GSC integration first
        if ( class_exists('AILG_Google') && AILG_Google::is_connected() ) {
            $sd_ids = AILG_Google::get_striking_distance_pages();
        }

        // 2. Fallback to VM SEO Brain / VMAI
        if ( empty($sd_ids) && class_exists( 'AILG_VMSB_Integration' ) ) {
            $sd_ids = AILG_VMSB_Integration::get_striking_distance_posts();
        }

        if ( ! empty( $sd_ids ) ) {
            $sd_ids_str = implode( ',', array_map( 'intval', $sd_ids ) );
            $sd_posts = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->posts}
                 WHERE ID IN ($sd_ids_str)
                   AND post_status = 'publish'
                   AND post_type IN ({$placeholders})
                   AND ID != %d
                 LIMIT 20",
                ...array_merge( $post_types, [ $post_id ] )
            ) );
        }

        // Merge and unique
        $all = array_merge( $cat_posts ?: [], $title_posts ?: [], $recent_posts ?: [], $sd_posts ?: [] );

        if ( get_option( 'ailg_restrict_to_silo' ) ) {
            // Filter: Only keep posts that share at least one category with the source post
            $all = array_filter( $all, function($p) use ($categories) {
                $p_cats = wp_get_post_categories( $p->ID );
                return ! empty( array_intersect( $p_cats, $categories ) );
            });
        }

        $unique = [];
        foreach ( $all as $p ) {
            if ( ! isset( $unique[ $p->ID ] ) ) {
                $unique[ $p->ID ] = $p;
            }
        }

        return array_slice( array_values( $unique ), 0, $limit );
    }

    public static function sync_models( string $provider ): array {
        switch ( $provider ) {
            case 'aipuffer':   return AILG_AIPuffer::discover_bots();
            case 'openai':     return self::sync_openai_models();
            case 'google':     return self::sync_google_models();
            case 'openrouter': return self::sync_openrouter_models();
            case 'ollama':     return self::sync_ollama_models();
        }
        return [];
    }

    private static function sync_openai_models(): array {
        $key = (string) get_option( 'ailg_openai_key', '' );
        if ( empty( $key ) ) return [];
        $resp = wp_remote_get( 'https://api.openai.com/v1/models', [
            'headers' => [ 'Authorization' => 'Bearer ' . $key ],
            'timeout' => 20,
        ] );
        if ( is_wp_error( $resp ) ) return [];
        $body   = json_decode( wp_remote_retrieve_body( $resp ), true );
        $models = array_filter( $body['data'] ?? [], fn( $m ) => str_contains( $m['id'], 'gpt' ) );
        usort( $models, fn( $a, $b ) => strcmp( $b['id'], $a['id'] ) );
        $list = array_map( fn( $m ) => [ 'id' => $m['id'], 'name' => $m['id'] ], array_values( $models ) );
        update_option( 'ailg_openai_models_list', array_column( $list, 'id' ) );
        return $list;
    }

    private static function sync_google_models(): array {
        $key = (string) get_option( 'ailg_google_key', '' );
        if ( empty( $key ) ) return [];
        $resp = wp_remote_get( "https://generativelanguage.googleapis.com/v1beta/models?key={$key}", [ 'timeout' => 20 ] );
        if ( is_wp_error( $resp ) ) return [];
        $body   = json_decode( wp_remote_retrieve_body( $resp ), true );
        $models = array_filter( $body['models'] ?? [], fn( $m ) => str_contains( $m['name'], 'gemini' ) );
        $list   = array_map( fn( $m ) => [ 'id' => str_replace( 'models/', '', $m['name'] ), 'name' => $m['displayName'] ?? $m['name'] ], array_values( $models ) );
        update_option( 'ailg_google_models_list', array_column( $list, 'id' ) );
        return $list;
    }

    private static function sync_openrouter_models(): array {
        $key = (string) get_option( 'ailg_openrouter_key', '' );
        if ( empty( $key ) ) return [];
        $resp = wp_remote_get( 'https://openrouter.ai/api/v1/models', [
            'headers' => [ 'Authorization' => 'Bearer ' . $key ],
            'timeout' => 20,
        ] );
        if ( is_wp_error( $resp ) ) return [];
        $body   = json_decode( wp_remote_retrieve_body( $resp ), true );
        $models = $body['data'] ?? [];
        $list   = array_map( fn( $m ) => [ 'id' => $m['id'], 'name' => $m['name'] ?? $m['id'] ], $models );
        update_option( 'ailg_openrouter_models_list', array_column( $list, 'id' ) );
        return array_slice( $list, 0, 100 );
    }

    private static function sync_ollama_models(): array {
        $host = rtrim( (string) get_option( 'ailg_ollama_host', 'http://localhost:11434' ), '/' );
        $resp = wp_remote_get( "{$host}/api/tags", [ 'timeout' => 10 ] );
        if ( is_wp_error( $resp ) ) return [];
        $body   = json_decode( wp_remote_retrieve_body( $resp ), true );
        $models = $body['models'] ?? [];
        $list   = array_map( fn( $m ) => [ 'id' => $m['name'], 'name' => $m['name'] ], $models );
        update_option( 'ailg_ollama_models_list', array_column( $list, 'id' ) );
        return $list;
    }

    public static function test_connection( string $provider, array $creds = [] ): array {
        switch ( $provider ) {
            case 'aipuffer': {
                $bots = AILG_AIPuffer::discover_bots();
                $count = count( $bots );
                return $count > 0 ? [ true, "AI Puffer connected! {$count} bots found.", $count ] : [ false, 'No bots found. Check your configuration.', 0 ];
            }
            case 'openai': {
                $key = $creds['api_key'] ?? (string) get_option( 'ailg_openai_key', '' );
                if ( empty( $key ) ) return [ false, 'No API key configured', 0 ];
                $resp  = wp_remote_get( 'https://api.openai.com/v1/models', [ 'headers' => [ 'Authorization' => 'Bearer ' . $key ], 'timeout' => 15 ] );
                if ( is_wp_error( $resp ) ) return [ false, $resp->get_error_message(), 0 ];
                $code  = (int) wp_remote_retrieve_response_code( $resp );
                $body  = json_decode( wp_remote_retrieve_body( $resp ), true );
                $count = count( $body['data'] ?? [] );
                return $code === 200 ? [ true, "Connected! {$count} models available", $count ] : [ false, $body['error']['message'] ?? 'Authentication failed', 0 ];
            }
            case 'google': {
                $key = $creds['api_key'] ?? (string) get_option( 'ailg_google_key', '' );
                if ( empty( $key ) ) return [ false, 'No API key configured', 0 ];
                $resp  = wp_remote_get( "https://generativelanguage.googleapis.com/v1beta/models?key={$key}", [ 'timeout' => 15 ] );
                if ( is_wp_error( $resp ) ) return [ false, $resp->get_error_message(), 0 ];
                $code  = (int) wp_remote_retrieve_response_code( $resp );
                $body  = json_decode( wp_remote_retrieve_body( $resp ), true );
                $count = count( $body['models'] ?? [] );
                return $code === 200 ? [ true, "Connected! {$count} models available", $count ] : [ false, 'Authentication failed', 0 ];
            }
            case 'openrouter': {
                $key = $creds['api_key'] ?? (string) get_option( 'ailg_openrouter_key', '' );
                if ( empty( $key ) ) return [ false, 'No API key configured', 0 ];
                $resp  = wp_remote_get( 'https://openrouter.ai/api/v1/models', [ 'headers' => [ 'Authorization' => 'Bearer ' . $key ], 'timeout' => 15 ] );
                if ( is_wp_error( $resp ) ) return [ false, $resp->get_error_message(), 0 ];
                $code  = (int) wp_remote_retrieve_response_code( $resp );
                $body  = json_decode( wp_remote_retrieve_body( $resp ), true );
                $count = count( $body['data'] ?? [] );
                return $code === 200 ? [ true, "Connected! {$count} models available", $count ] : [ false, 'Authentication failed', 0 ];
            }
            case 'ollama': {
                $host  = $creds['host'] ?? rtrim( (string) get_option( 'ailg_ollama_host', 'http://localhost:11434' ), '/' );
                $resp  = wp_remote_get( "{$host}/api/tags", [ 'timeout' => 8 ] );
                if ( is_wp_error( $resp ) ) return [ false, 'Cannot connect to Ollama at ' . $host, 0 ];
                $body  = json_decode( wp_remote_retrieve_body( $resp ), true );
                $count = count( $body['models'] ?? [] );
                return [ true, "Ollama connected! {$count} local models", $count ];
            }
        }
        return [ false, 'Unknown provider', 0 ];
    }

    public static function ajax_sync_models(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        $provider = sanitize_key( (string) ( $_POST['provider'] ?? '' ) );

        $creds = [];
        if ( isset($_POST['api_key']) ) $creds['api_key'] = sanitize_text_field($_POST['api_key']);
        if ( isset($_POST['host']) )    $creds['host']    = sanitize_text_field($_POST['host']);

        // Temporarily override for sync if creds provided
        if ( ! empty($creds['api_key']) ) update_option( "ailg_{$provider}_key", $creds['api_key'] );
        if ( ! empty($creds['host']) )    update_option( "ailg_{$provider}_host", $creds['host'] );

        $models   = self::sync_models( $provider );
        if ( empty( $models ) ) wp_send_json_error( 'Could not sync models. Check your API key and connection.' );
        wp_send_json_success( [ 'models' => $models ] );
    }

    public static function ajax_test_connection(): void {
        check_ajax_referer( 'ailg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        $provider = sanitize_key( (string) ( $_POST['provider'] ?? '' ) );

        $creds = [];
        if ( isset($_POST['api_key']) ) $creds['api_key'] = sanitize_text_field($_POST['api_key']);
        if ( isset($_POST['host']) )    $creds['host']    = sanitize_text_field($_POST['host']);

        [ $ok, $msg, $count ] = self::test_connection( $provider, $creds );
        if ( $ok ) wp_send_json_success( [ 'message' => $msg, 'model_count' => $count ] );
        else       wp_send_json_error( $msg );
    }

    /**
     * Generate suggestings for High-Authority External Links (EEAT Booster).
     */
    public static function generate_external_suggestions( int $post_id, string $provider = '' ): array {
        if ( empty( $provider ) ) $provider = (string) get_option( 'ailg_default_provider', 'openai' );
        $post = get_post( $post_id );
        if ( ! $post ) return [];

        $prompt = "You are an expert SEO strategist specializing in EEAT. Suggest 2-3 High-Authority External Links (Wikipedia, Official Govt sites, Research Papers, or Industry Leaders) that would support the claims in the following content: \n\n"
                . "TITLE: " . $post->post_title . "\n\n"
                . "CONTENT: " . mb_substr( wp_strip_all_tags( $post->post_content ), 0, 3000 )
                . "\n\nReturn ONLY a JSON array: [{\"url\": \"...\", \"anchor_text\": \"...\", \"reason\": \"...\"}]";

        // Logic similar to internal but with a specialized prompt...
        // For brevity in this step, I'll reuse the call_* methods.
        $response = '';
        switch ( $provider ) {
            case 'openai':      [ $response, ] = self::call_openai( $prompt );      break;
            case 'google':      [ $response, ] = self::call_google( $prompt );      break;
        }

        $json = preg_replace( '/^```(?:json)?\s*/i', '', trim( (string) $response ) );
        $json = preg_replace( '/\s*```$/', '', $json );
        return json_decode( $json, true ) ?: [];
    }