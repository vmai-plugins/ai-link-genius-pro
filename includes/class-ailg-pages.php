<?php
/**
 * Admin Pages Class
 */

defined( 'ABSPATH' ) || exit;

class AILG_Pages {

    public static function dashboard(): void {
        $provider = get_option( 'ailg_default_provider', 'openai' );
        $license  = get_option( 'ailg_license_key', '' );
        ?>
        <div class="ailg-wrap">
            <div class="ailg-header">
                <div class="ailg-header-icon">🔗</div>
                <div class="ailg-header-title">
                    <h1>AI Link Genius Pro</h1>
                    <p>The most advanced AI-powered internal linking suite for WordPress</p>
                </div>
                <span class="ailg-badge">PRO</span>
                <span class="ailg-version">v<?php echo esc_html( AILG_VERSION ); ?></span>
            </div>

            <div id="ailg-dash-stats">
                <div class="ailg-grid ailg-grid-4" style="margin-bottom:20px">
                    <?php
                    $stats = [
                        [ 'purple', '🔗', 'Total Internal Links', 'ailg-stat-links',        'across all posts'     ],
                        [ 'blue',   '💡', 'Pending Suggestions',  'ailg-stat-suggestions',  'awaiting review'      ],
                        [ 'yellow', '👻', 'Orphaned Posts',       'ailg-stat-orphaned',     'need inbound links'   ],
                        [ 'red',    '💔', 'Broken Links',         'ailg-stat-broken',       'need attention'       ],
                    ];
                    foreach ( $stats as [ $color, $icon, $title, $id, $label ] ): ?>
                    <div class="ailg-card">
                        <div class="ailg-card-header">
                            <div class="ailg-card-icon <?php echo esc_attr( $color ); ?>"><?php echo $icon; ?></div>
                            <span class="ailg-card-title"><?php echo esc_html( $title ); ?></span>
                        </div>
                        <div class="ailg-stat-value" id="<?php echo esc_attr( $id ); ?>">—</div>
                        <div class="ailg-stat-label"><?php echo esc_html( $label ); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="ailg-grid ailg-grid-2" style="margin-bottom:20px">
                    <div class="ailg-card">
                        <div class="ailg-section-title">📈 Links Added (Last 7 Days)</div>
                        <div id="ailg-weekly-chart"></div>
                    </div>
                    <div class="ailg-card">
                        <div class="ailg-section-title">⚡ Quick Actions</div>
                        <div style="display:flex;flex-direction:column;gap:10px">
                            <button class="ailg-btn ailg-btn-primary" id="ailg-bulk-scan-btn">🔍 Bulk Scan All Posts</button>
                            <div id="ailg-bulk-progress" style="display:none">
                                <div class="ailg-progress" style="margin-bottom:6px"><div class="ailg-progress-bar" id="ailg-bulk-bar" style="width:0%"></div></div>
                                <p style="color:var(--ailg-text-dim);font-size:12px;margin:0;font-family:Verdana,sans-serif">Scanning posts and generating AI suggestions…</p>
                            </div>
                            <a href="<?php echo esc_url( admin_url('admin.php?page=ailg-suggestions') ); ?>" class="ailg-btn ailg-btn-secondary">💡 View All Suggestions</a>
                            <a href="<?php echo esc_url( admin_url('admin.php?page=ailg-reports') ); ?>"    class="ailg-btn ailg-btn-secondary">📊 View Link Report</a>
                            <a href="<?php echo esc_url( admin_url('admin.php?page=ailg-settings') ); ?>"  class="ailg-btn ailg-btn-secondary">⚙️ Configure AI Provider</a>
                        </div>
                    </div>
                </div>

                <?php if ( empty( $license ) ): ?>
                <div style="padding: 0 32px; margin-bottom:20px">
                    <div class="ailg-alert ailg-alert-warning">
                        ⚠️ No license key entered. Add your key in Settings to enable all Pro features.
                        <a href="<?php echo esc_url( admin_url('admin.php?page=ailg-settings') ); ?>" style="color:var(--ailg-yellow);margin-left:8px;font-weight:700">Configure Now →</a>
                    </div>
                </div>
                <?php endif; ?>

                <div class="ailg-grid ailg-grid-3" style="margin-bottom:32px">
                    <div class="ailg-card">
                        <div class="ailg-section-title">🤖 AI Provider Status</div>
                        <?php
                        $providers = [ 'openai' => 'OpenAI', 'google' => 'Google Gemini', 'openrouter' => 'OpenRouter', 'ollama' => 'Ollama (Local)' ];
                        foreach ( $providers as $k => $label ):
                            $configured = ( $k === 'ollama' )
                                ? ! empty( get_option( 'ailg_ollama_host' ) )
                                : ! empty( get_option( "ailg_{$k}_key" ) );
                            $active = ( $provider === $k );
                        ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--ailg-border)">
                            <span style="font-size:13px;font-weight:<?php echo $active ? '700' : '400'; ?>;color:var(--ailg-text)">
                                <?php echo esc_html( $label ); ?>
                                <?php if ( $active ) echo '<span class="ailg-tag ailg-tag-purple" style="font-size:10px;margin-left:6px">Default</span>'; ?>
                            </span>
                            <span class="ailg-provider-status">
                                <span class="ailg-dot <?php echo $configured ? 'ailg-dot-green' : 'ailg-dot-dim'; ?>"></span>
                                <span style="font-size:11px;color:<?php echo $configured ? 'var(--ailg-green)' : 'var(--ailg-text-dim)'; ?>;font-weight:600"><?php echo $configured ? 'Configured' : 'Not set'; ?></span>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="ailg-card">
                        <div class="ailg-section-title">🔧 Configuration Summary</div>
                        <?php
                        $items = [
                            'Default Provider'   => ucfirst( (string) get_option( 'ailg_default_provider', 'openai' ) ),
                            'Max Suggestions'    => get_option( 'ailg_max_suggestions', 5 ) . ' per post',
                            'Min Score'          => ( round( (float) get_option( 'ailg_min_score', 0.65 ) * 100 ) ) . '%',
                            'Auto-linking'       => get_option( 'ailg_auto_link_enabled' ) ? '✅ Enabled' : '❌ Disabled',
                            'Scan on Publish'    => get_option( 'ailg_scan_on_publish', true ) ? '✅ Yes' : '❌ No',
                            'Link Limit/Post'    => (string) get_option( 'ailg_link_limit_per_post', 10 ),
                        ];
                        foreach ( $items as $k => $v ): ?>
                        <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--ailg-border);font-size:13px;font-family:Verdana,sans-serif">
                            <span style="color:var(--ailg-text-dim)"><?php echo esc_html( $k ); ?></span>
                            <span style="font-weight:700;color:var(--ailg-text)"><?php echo esc_html( $v ); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="ailg-card">
                        <div class="ailg-section-title">📋 Recent Activity</div>
                        <?php
                        global $wpdb;
                        $recent = $wpdb->get_results(
                            "SELECT s.*, p.post_title FROM {$wpdb->prefix}ailg_suggestions s
                             LEFT JOIN {$wpdb->posts} p ON p.ID = s.post_id
                             ORDER BY s.created_at DESC LIMIT 6"
                        );
                        if ( $recent ):
                            foreach ( $recent as $r ):
                                $sc = $r->status === 'accepted' ? 'ailg-tag-green' : ( $r->status === 'dismissed' ? 'ailg-tag-red' : 'ailg-tag-yellow' );
                            ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--ailg-border)">
                                <span style="font-size:12px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:Verdana,sans-serif"><?php echo esc_html( $r->post_title ?: 'Post #' . $r->post_id ); ?></span>
                                <span class="ailg-tag <?php echo esc_attr( $sc ); ?>" style="margin-left:8px;flex-shrink:0"><?php echo esc_html( ucfirst( $r->status ) ); ?></span>
                            </div>
                            <?php endforeach;
                        else: ?>
                        <div class="ailg-empty"><div class="ailg-empty-icon" style="font-size:32px">📭</div><p style="font-size:12px">No suggestions yet. Run a scan!</p></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function suggestions(): void {
        global $wpdb;
        $suggestions = $wpdb->get_results(
            "SELECT s.*, p.post_title AS source_title, t.post_title AS target_title
             FROM {$wpdb->prefix}ailg_suggestions s
             LEFT JOIN {$wpdb->posts} p ON p.ID = s.post_id
             LEFT JOIN {$wpdb->posts} t ON t.ID = s.target_id
             WHERE s.status = 'pending'
             ORDER BY s.score DESC
             LIMIT 100"
        );
        ?>
        <div class="ailg-wrap">
            <div class="ailg-header">
                <div class="ailg-header-icon">💡</div>
                <div class="ailg-header-title">
                    <h1>Link Suggestions</h1>
                    <p>AI-powered internal linking opportunities for your content</p>
                </div>
                <button class="ailg-btn ailg-btn-secondary ailg-bulk-scan-btn" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff">🔍 Scan All Posts</button>
            </div>
            <div id="ailg-bulk-progress" style="display:none;padding:0 32px 16px">
                <div class="ailg-progress"><div class="ailg-progress-bar" id="ailg-bulk-bar" style="width:0%"></div></div>
            </div>
            <div class="ailg-tabs">
                <button class="ailg-tab active" data-group="suggs" data-target="ailg-tab-internal">🔗 Internal Suggestions</button>
                <button class="ailg-tab" data-group="suggs" data-target="ailg-tab-eeat">✨ EEAT & Authority</button>
            </div>

            <div id="ailg-tab-internal" class="ailg-tab-content active" data-group="suggs">
                <?php if ( empty( $suggestions ) ): ?>
                <div class="ailg-empty">
                    <div class="ailg-empty-icon">💡</div>
                    <p>No pending suggestions. Run a bulk scan to generate AI-powered link suggestions.</p>
                    <button class="ailg-btn ailg-btn-primary ailg-bulk-scan-btn" style="margin-top:16px">🔍 Start Bulk Scan</button>
                </div>
                <?php else: ?>
                <div class="ailg-alert ailg-alert-info">💡 Showing <strong><?php echo count( $suggestions ); ?></strong> pending link suggestions — review and insert them into your content.</div>
                <?php foreach ( $suggestions as $s ):
                    $pct = round( $s->score * 100 );
                    $bar = $pct >= 80 ? 'var(--ailg-green)' : ( $pct >= 60 ? 'var(--ailg-secondary)' : 'var(--ailg-primary)' );
                    $is_bridge = ! empty( $s->is_bridge );
                ?>
                <div class="ailg-suggestion" data-id="<?php echo (int) $s->id; ?>">
                    <div class="ailg-suggestion-header">
                        <div class="ailg-suggestion-score"><?php echo $pct; ?>%</div>
                        <div class="ailg-suggestion-meta">
                            <div class="ailg-suggestion-title">
                                <span style="color:var(--ailg-text-dim);font-weight:400">In:</span>
                                <a href="<?php echo esc_url( (string) get_edit_post_link( (int) $s->post_id ) ); ?>" style="color:var(--ailg-primary);text-decoration:none;font-weight:700"><?php echo esc_html( (string) $s->source_title ); ?></a>
                                <span style="color:var(--ailg-border);margin:0 8px">→</span>
                                <a href="<?php echo esc_url( (string) get_permalink( (int) $s->target_id ) ); ?>" target="_blank" rel="noopener" style="color:var(--ailg-secondary);text-decoration:none"><?php echo esc_html( (string) $s->target_title ); ?></a>
                                <?php if ( $is_bridge ) echo '<span class="ailg-tag ailg-tag-purple" style="margin-left:8px">Context Bridge</span>'; ?>
                            </div>
                            <div class="ailg-suggestion-anchor" style="margin-top:4px">
                                Anchor: <strong>&ldquo;<?php echo esc_html( $s->anchor_text ); ?>&rdquo;</strong>
                                &nbsp;|&nbsp; Provider: <span class="ailg-tag ailg-tag-purple"><?php echo esc_html( $s->provider ?: 'ai' ); ?></span>
                                <?php if ( $s->model_used ) echo ' <span class="ailg-tag ailg-tag-blue" style="margin-left:4px">' . esc_html( $s->model_used ) . '</span>'; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ( $s->context ): ?>
                    <div class="ailg-suggestion-context"><?php echo esc_html( $s->context ); ?></div>
                    <?php endif; ?>
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                        <div class="ailg-score-bar" style="flex:1"><div class="ailg-score-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $bar; ?>"></div></div>
                        <span style="font-size:11px;color:var(--ailg-text-dim);white-space:nowrap;font-family:Verdana,sans-serif">Relevance</span>
                    </div>
                    <div class="ailg-suggestion-actions">
                        <button class="ailg-btn ailg-btn-success ailg-btn-sm ailg-accept-btn" data-id="<?php echo (int) $s->id; ?>" data-post="<?php echo (int) $s->post_id; ?>">✓ Insert Link</button>
                        <a href="<?php echo esc_url( (string) get_edit_post_link( (int) $s->post_id ) ); ?>" class="ailg-btn ailg-btn-secondary ailg-btn-sm">✏️ Edit Post</a>
                        <button class="ailg-btn ailg-btn-danger ailg-btn-sm ailg-dismiss-btn" data-id="<?php echo (int) $s->id; ?>">✗ Dismiss</button>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <div id="ailg-tab-eeat" class="ailg-tab-content" data-group="suggs">
                <div class="ailg-empty">
                    <div class="ailg-empty-icon">✨</div>
                    <p>EEAT Authority suggestions are coming soon. This feature scans your content for missing citations to high-authority external sources like Wikipedia or official research.</p>
                </div>
            </div>
        </div>
        <?php
    }

    public static function automation(): void {
        global $wpdb;
        $rules      = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ailg_automation_rules ORDER BY created_at DESC" );
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        $providers  = [ 'openai' => 'OpenAI', 'google' => 'Google Gemini', 'openrouter' => 'OpenRouter', 'ollama' => 'Ollama' ];
        ?>
        <div class="ailg-wrap">
            <div class="ailg-header">
                <div class="ailg-header-icon">⚡</div>
                <div class="ailg-header-title">
                    <h1>Automation Rules</h1>
                    <p>Create intelligent rules to auto-generate and insert internal links</p>
                </div>
                <button class="ailg-btn ailg-btn-secondary" id="ailg-add-rule-btn" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff">+ Add Custom Rule</button>
            </div>

            <div class="ailg-tabs">
                <button class="ailg-tab active" data-group="auto" data-target="ailg-tab-active-rules">⚙️ Active Rules</button>
                <button class="ailg-tab" data-group="auto" data-target="ailg-tab-library">📚 Template Library</button>
            </div>

            <div style="padding:0 32px 32px">
                <div id="ailg-tab-active-rules" class="ailg-tab-content active" data-group="auto">
                    <?php if ( empty( $rules ) ): ?>
                    <div class="ailg-empty">
                        <div class="ailg-empty-icon">⚡</div>
                        <p>No automation rules yet. Create a rule or install a template from the Library.</p>
                        <button class="ailg-btn ailg-btn-primary" onclick="document.querySelector('[data-target=ailg-tab-library]').click()">📂 Browse Library</button>
                    </div>
                    <?php else:
                        foreach ( $rules as $rule ):
                    ?>
                    <div class="ailg-rule-card">
                        <div class="ailg-rule-indicator <?php echo $rule->is_active ? 'active' : 'inactive'; ?>"></div>
                        <div style="flex:1">
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap">
                                <strong style="font-size:15px;color:var(--ailg-text)"><?php echo esc_html( $rule->rule_name ); ?></strong>
                                <?php if ( $rule->is_active ) echo '<span class="ailg-tag ailg-tag-green">Active</span>'; else echo '<span class="ailg-tag ailg-tag-red">Inactive</span>'; ?>
                                <span class="ailg-tag ailg-tag-purple"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $rule->trigger_type ) ) ); ?></span>
                                <span class="ailg-tag ailg-tag-blue"><?php echo esc_html( $providers[ $rule->ai_provider ] ?? $rule->ai_provider ); ?></span>
                            </div>
                            <div style="display:flex;gap:24px;font-size:12px;color:var(--ailg-text-dim);flex-wrap:wrap;font-family:Verdana,sans-serif">
                                <span>Max Links: <strong style="color:var(--ailg-text)"><?php echo (int) $rule->max_links; ?></strong></span>
                                <span>Min Score: <strong style="color:var(--ailg-text)"><?php echo round( (float) $rule->min_score * 100 ); ?>%</strong></span>
                                <span>Auto-Insert: <strong style="color:var(--ailg-text)"><?php echo $rule->auto_insert ? '✅ Yes' : '❌ No'; ?></strong></span>
                                <span>Applied: <strong style="color:var(--ailg-primary)"><?php echo number_format( (int) $rule->total_applied ); ?> links</strong></span>
                                <?php if ( $rule->last_run ) echo '<span>Last Run: <strong style="color:var(--ailg-text)">' . esc_html( human_time_diff( strtotime( $rule->last_run ), time() ) ) . ' ago</strong></span>'; ?>
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;flex-shrink:0">
                            <button class="ailg-btn ailg-btn-success ailg-btn-sm ailg-run-rule-btn" data-id="<?php echo (int) $rule->id; ?>">▶ Run Now</button>
                            <button class="ailg-btn ailg-btn-danger ailg-btn-sm ailg-delete-rule-btn" data-id="<?php echo (int) $rule->id; ?>" title="Delete rule">🗑</button>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>

                <div id="ailg-tab-library" class="ailg-tab-content" data-group="auto">
                    <div class="ailg-grid ailg-grid-2">
                        <?php
                        $templates = [
                            [
                                'id'    => 'authority_builder',
                                'title' => 'The Authority Builder',
                                'desc'  => 'Boost your SEO silos. Automatically finds links from new blog posts to your high-authority Pillar/Cornerstone pages.',
                                'icon'  => '🏛️',
                                'color' => 'purple'
                            ],
                            [
                                'id'    => 'revenue_funnel',
                                'title' => 'The Revenue Funnel',
                                'desc'  => 'Convert readers into customers. Scans informational content for opportunities to link to Product/Service pages.',
                                'icon'  => '💰',
                                'color' => 'green'
                            ],
                            [
                                'id'    => 'page1_booster',
                                'title' => 'Page 1 Booster (GSC)',
                                'desc'  => 'Uses real search data to identify posts in positions 4-15 and reinforces them with new inbound links to hit the Top 3.',
                                'icon'  => '🚀',
                                'color' => 'blue'
                            ],
                            [
                                'id'    => 'hands_free',
                                'title' => 'Hands-Free Maintenance',
                                'desc'  => 'Strict auto-insertion. Only applies links with >90% relevance score automatically on publish. Set and forget.',
                                'icon'  => '🤖',
                                'color' => 'yellow'
                            ]
                        ];
                        foreach ( $templates as $t ): ?>
                        <div class="ailg-card">
                            <div class="ailg-card-header">
                                <div class="ailg-card-icon <?php echo $t['color']; ?>"><?php echo $t['icon']; ?></div>
                                <span class="ailg-card-title"><?php echo $t['title']; ?></span>
                            </div>
                            <p style="font-size:13px; color:var(--ailg-text-dim); line-height:1.5; margin-bottom:15px; min-height:60px;">
                                <?php echo $t['desc']; ?>
                            </p>
                            <button class="ailg-btn ailg-btn-primary ailg-install-template-btn" data-template="<?php echo $t['id']; ?>" style="width:100%; justify-content:center;">⚡ Install Template</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Modal Overlay -->
            <div id="ailg-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(30,34,53,.5);z-index:99998;backdrop-filter:blur(3px)"></div>
            <!-- Add Rule Modal -->
            <div id="ailg-rule-modal" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--ailg-surface);border:1.5px solid var(--ailg-border);border-radius:var(--ailg-radius);padding:32px;width:640px;max-width:95vw;max-height:90vh;overflow-y:auto;z-index:99999;box-shadow:0 20px 60px rgba(30,34,53,.2)">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px">
                    <h2 style="margin:0;font-size:18px;color:var(--ailg-text);font-family:Georgia,serif">⚡ Create Automation Rule</h2>
                    <button class="ailg-modal-close ailg-btn ailg-btn-secondary ailg-btn-sm">✕ Close</button>
                </div>
                <form id="ailg-rule-form">
                    <?php wp_nonce_field( 'ailg_nonce', 'nonce' ); ?>
                    <div class="ailg-form-row">
                        <div><div class="ailg-form-label">Rule Name</div><div class="ailg-form-desc">A descriptive name for this rule</div></div>
                        <input name="rule_name" class="ailg-input" placeholder="e.g. Auto-link blog posts on publish" required>
                    </div>
                    <div class="ailg-form-row">
                        <div><div class="ailg-form-label">Trigger</div><div class="ailg-form-desc">When should this rule run?</div></div>
                        <select name="trigger_type" class="ailg-select">
                            <option value="on_publish">On Post Publish</option>
                            <option value="on_update">On Post Update</option>
                            <option value="daily">Daily (Scheduled)</option>
                            <option value="weekly">Weekly (Scheduled)</option>
                            <option value="manual">Manual Only</option>
                        </select>
                    </div>
                    <div class="ailg-form-row">
                        <div><div class="ailg-form-label">Post Types</div><div class="ailg-form-desc">Apply to these post types</div></div>
                        <div style="display:flex;flex-wrap:wrap;gap:10px">
                            <?php foreach ( $post_types as $pt ): ?>
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;font-family:Verdana,sans-serif">
                                <input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, [ 'post', 'page' ], true ) ); ?>>
                                <?php echo esc_html( $pt->label ); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="ailg-form-row">
                        <div><div class="ailg-form-label">AI Provider</div></div>
                        <select name="ai_provider" class="ailg-select">
                            <?php foreach ( $providers as $k => $v ): ?>
                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( get_option( 'ailg_default_provider' ), $k ); ?>><?php echo esc_html( $v ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ailg-form-row">
                        <div><div class="ailg-form-label">Max Links Per Post</div></div>
                        <div class="ailg-range-wrap">
                            <input type="range" name="max_links" class="ailg-range" min="1" max="15" value="3">
                            <span class="ailg-range-val">3</span>
                        </div>
                    </div>
                    <div class="ailg-form-row">
                        <div><div class="ailg-form-label">Minimum Score</div><div class="ailg-form-desc">Only suggest links above this relevance score</div></div>
                        <div class="ailg-range-wrap">
                            <input type="range" name="min_score" class="ailg-range" min="0.3" max="1" step="0.05" value="0.7">
                            <span class="ailg-range-val">0.7</span>
                        </div>
                    </div>
                    <div class="ailg-form-row">
                        <div><div class="ailg-form-label">Auto-Insert Links</div><div class="ailg-form-desc">Automatically insert accepted links without manual review</div></div>
                        <label class="ailg-toggle"><input type="checkbox" name="auto_insert" value="1"><span class="ailg-toggle-slider"></span></label>
                    </div>
                    <div class="ailg-form-row">
                        <div><div class="ailg-form-label">Anchor Text Mode</div></div>
                        <select name="anchor_mode" class="ailg-select">
                            <option value="ai_optimal">AI Optimal (Recommended)</option>
                            <option value="exact_keyword">Exact Keyword Match</option>
                            <option value="semantic">Semantic Variation</option>
                            <option value="custom">Custom Pattern</option>
                        </select>
                    </div>
                    <div class="ailg-form-row" style="border:none">
                        <div><div class="ailg-form-label">Active</div><div class="ailg-form-desc">Enable or disable this rule</div></div>
                        <label class="ailg-toggle"><input type="checkbox" name="is_active" value="1" checked><span class="ailg-toggle-slider"></span></label>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;padding-top:16px;border-top:1px solid var(--ailg-border)">
                        <button type="button" class="ailg-btn ailg-btn-secondary ailg-modal-close">Cancel</button>
                        <button type="submit" class="ailg-btn ailg-btn-primary">💾 Save Rule</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    public static function reports(): void {
        ?>
        <div class="ailg-wrap">
            <div class="ailg-header">
                <div class="ailg-header-icon">📊</div>
                <div class="ailg-header-title">
                    <h1>Reports &amp; Analytics</h1>
                    <p>Comprehensive overview of your internal linking strategy</p>
                </div>
            </div>
            <div class="ailg-tabs">
                <button class="ailg-tab active" data-group="reports" data-target="ailg-tab-allposts">📊 All Posts</button>
                <button class="ailg-tab" data-group="reports" data-target="ailg-tab-audit">🧬 Semantic Coverage</button>
                <button class="ailg-tab" data-group="reports" data-target="ailg-tab-decay">📉 Link Decay</button>
            </div>

            <div style="padding:0 32px 32px">
                <div id="ailg-tab-allposts" class="ailg-tab-content active" data-group="reports">
                    <div id="ailg-reports-wrap">
                        <div style="text-align:center;padding:60px"><span class="ailg-spinner" style="width:28px;height:28px;border-width:3px"></span><p style="color:var(--ailg-text-dim);margin-top:14px;font-family:Verdana,sans-serif">Loading report data…</p></div>
                    </div>
                </div>

                <div id="ailg-tab-audit" class="ailg-tab-content" data-group="reports">
                    <div class="ailg-card">
                        <div class="ailg-section-title">🧬 Semantic Coverage Audit</div>
                        <p class="ailg-form-desc">Identifying topical hubs with low internal connectivity. High-priority hubs should have inbound links from at least 60% of related content.</p>
                        <div id="ailg-audit-wrap" style="margin-top:20px">
                             <div style="text-align:center;padding:40px"><span class="ailg-spinner"></span></div>
                        </div>
                    </div>
                </div>

                <div id="ailg-tab-decay" class="ailg-tab-content" data-group="reports">
                    <div class="ailg-card">
                        <div class="ailg-section-title">📉 Link Decay Engine</div>
                        <p class="ailg-form-desc">Monitoring internal links with low engagement. Links with 0 clicks after 6 months are flagged for anchor optimization or target refreshing.</p>
                        <div id="ailg-decay-wrap" style="margin-top:20px">
                             <div style="text-align:center;padding:40px"><span class="ailg-spinner"></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function orphaned(): void {
        ?>
        <div class="ailg-wrap">
            <div class="ailg-header">
                <div class="ailg-header-icon">👻</div>
                <div class="ailg-header-title">
                    <h1>Orphaned Content</h1>
                    <p>Posts and pages with no inbound internal links — invisible to link equity</p>
                </div>
            </div>
            <div style="padding:0 32px 32px">
                <div id="ailg-orphaned-wrap">
                    <div style="text-align:center;padding:60px"><span class="ailg-spinner" style="width:28px;height:28px;border-width:3px"></span></div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function broken(): void {
        ?>
        <div class="ailg-wrap">
            <div class="ailg-header">
                <div class="ailg-header-icon">💔</div>
                <div class="ailg-header-title">
                    <h1>Broken Link Checker</h1>
                    <p>Scan your site for 404s, redirects, and dead internal links</p>
                </div>
                <button class="ailg-btn ailg-btn-secondary ailg-check-broken-btn" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff">🔍 Start Scan</button>
            </div>
            <div style="padding:0 32px 32px">
                <div class="ailg-alert ailg-alert-info">ℹ️ This scan checks all links across your published posts. Large sites may take several minutes. Checks the first 50 posts.</div>
                <div style="margin-bottom:20px; display:flex; gap:10px;">
                    <button class="ailg-btn ailg-btn-primary ailg-check-broken-btn">🔍 Start Broken Link Scan</button>
                    <button class="ailg-btn ailg-btn-success" id="ailg-groom-redirects-btn" style="display:none">🧹 Groom All Redirects</button>
                </div>
                <div id="ailg-broken-wrap">
                    <div class="ailg-empty"><div class="ailg-empty-icon">🔍</div><p>Click "Start Scan" to check all your links for broken URLs.</p></div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function linkmap(): void {
        ?>
        <div class="ailg-wrap">
            <div class="ailg-header">
                <div class="ailg-header-icon">🗺️</div>
                <div class="ailg-header-title">
                    <h1>Internal Link Map</h1>
                    <p>Visual network graph of your internal linking structure</p>
                </div>
            </div>
            <div style="padding:0 32px 32px">
                <div class="ailg-card">
                    <div class="ailg-section-title">🕸️ Link Network Visualization</div>
                    <p style="color:var(--ailg-text-dim);font-size:13px;margin-bottom:16px;font-family:Verdana,sans-serif">Each node represents a post/page. Lines show internal link connections. Larger nodes have more incoming links.</p>
                    <div id="ailg-link-graph"></div>
                    <div style="display:flex;gap:24px;margin-top:16px;flex-wrap:wrap;font-family:Verdana,sans-serif">
                        <div style="display:flex;align-items:center;gap:8px;font-size:12px"><div style="width:12px;height:12px;border-radius:50%;background:#4f46e5"></div>High Authority</div>
                        <div style="display:flex;align-items:center;gap:8px;font-size:12px"><div style="width:12px;height:12px;border-radius:50%;background:#0891b2"></div>Well Linked</div>
                        <div style="display:flex;align-items:center;gap:8px;font-size:12px"><div style="width:12px;height:12px;border-radius:50%;background:#7c3aed"></div>Regular Posts</div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function settings(): void {
        $providers = [
            'aipuffer'   => [ 'label' => 'AI Puffer',        'icon' => '🐡', 'desc' => 'Connect to your own AI Puffer instance or local bot. Best for privacy and custom silos.', 'color' => 'blue' ],
            'openai'     => [ 'label' => 'OpenAI',           'icon' => '🧠', 'desc' => 'GPT-4o, GPT-4, GPT-3.5 and more. Best accuracy for link suggestions.', 'color' => 'purple' ],
            'google'     => [ 'label' => 'Google Gemini',    'icon' => '✨', 'desc' => 'Gemini 2.0, Gemini Pro. Excellent for multilingual sites.',            'color' => 'blue'   ],
            'openrouter' => [ 'label' => 'OpenRouter',       'icon' => '🔄', 'desc' => 'Access Claude, Llama, Mistral & 100+ models via one API.',             'color' => 'green'  ],
            'ollama'     => [ 'label' => 'Ollama (Local)',   'icon' => '🏠', 'desc' => 'Run AI locally. 100% private, no API costs. Requires local Ollama.',    'color' => 'yellow' ],
        ];
        $current_provider   = (string) get_option( 'ailg_default_provider', 'aipuffer' );
        $fallback_providers = (array) get_option( 'ailg_fallback_providers', [ 'openai', 'google' ] );
        $post_types         = get_post_types( [ 'public' => true ], 'objects' );
        $selected_types     = (array) get_option( 'ailg_auto_link_post_types', [ 'post', 'page' ] );
        $ignore_words       = implode( "\n", (array) get_option( 'ailg_ignore_words', [] ) );
        ?>
        <div class="ailg-wrap">
            <div class="ailg-header">
                <div class="ailg-header-icon">⚙️</div>
                <div class="ailg-header-title">
                    <h1>Plugin Settings</h1>
                    <p>Configure AI providers, linking behavior, and advanced options</p>
                </div>
            </div>

            <div class="ailg-tabs">
                <button class="ailg-tab active" data-group="settings" data-target="ailg-tab-providers">🤖 AI Providers</button>
                <button class="ailg-tab" data-group="settings" data-target="ailg-tab-linking">🔗 Linking Rules</button>
                <button class="ailg-tab" data-group="settings" data-target="ailg-tab-google">📈 Performance (GSC)</button>
                <button class="ailg-tab" data-group="settings" data-target="ailg-tab-advanced">⚡ Advanced</button>
                <button class="ailg-tab" data-group="settings" data-target="ailg-tab-license">🔑 License</button>
            </div>

            <form id="ailg-settings-form">
                <?php wp_nonce_field( 'ailg_nonce', 'ailg_settings_nonce' ); ?>

                <!-- ── AI Providers Tab ── -->
                <div id="ailg-tab-providers" class="ailg-tab-content active" data-group="settings">
                    <p style="color:var(--ailg-text-dim);font-size:13px;margin-bottom:20px;font-family:Verdana,sans-serif">Configure the AI providers used to generate link suggestions. You can set up multiple providers and switch between them per automation rule.</p>

                    <div class="ailg-section-title">Primary AI Provider</div>
                    <div class="ailg-grid ailg-grid-4" style="margin-bottom:28px">
                        <?php foreach ( $providers as $k => $p ):
                            $is_active = ( $current_provider === $k );
                        ?>
                        <div class="ailg-provider <?php echo $is_active ? 'active' : ''; ?>"
                             onclick="document.querySelector('[name=ailg_default_provider]').value='<?php echo esc_js( $k ); ?>';document.querySelectorAll('.ailg-provider').forEach(x=>x.classList.remove('active'));this.classList.add('active')">
                            <div style="font-size:30px"><?php echo $p['icon']; ?></div>
                            <div class="ailg-provider-name"><?php echo esc_html( $p['label'] ); ?></div>
                            <div class="ailg-provider-desc"><?php echo esc_html( $p['desc'] ); ?></div>
                            <div class="ailg-provider-status" id="ailg-status-<?php echo esc_attr( $k ); ?>">
                                <span class="ailg-dot ailg-dot-dim"></span> Not tested
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="ailg_default_provider" value="<?php echo esc_attr( $current_provider ); ?>">

                    <div class="ailg-card" style="margin-bottom:24px">
                        <div class="ailg-section-title">Fallback Chain</div>
                        <p class="ailg-form-desc" style="margin-bottom:15px">If the primary provider fails (timeout or error), the plugin will automatically try these fallbacks in order.</p>
                        <div style="display:flex; flex-direction:column; gap:10px">
                            <?php foreach ( $providers as $k => $p ): if($k === 'aipuffer') continue; ?>
                            <label style="display:flex; align-items:center; gap:10px; cursor:pointer">
                                <input type="checkbox" name="ailg_fallback_providers[]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, $fallback_providers)); ?>>
                                <span style="font-size:13px; font-weight:600"><?php echo $p['icon'] . ' ' . $p['label']; ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="ailg-card" style="margin-bottom:16px">
                        <div class="ailg-card-header"><div class="ailg-card-icon blue">🐡</div><div class="ailg-card-title">AI Puffer Configuration</div></div>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label">Instance URL</div><div class="ailg-form-desc">Remote URL (e.g. https://site.com) or <strong>leave blank</strong> if AI Power/Engine is installed on this site.</div></div>
                            <input name="ailg_aipuffer_url" type="url" class="ailg-input"
                                   value="<?php echo esc_attr( (string) get_option( 'ailg_aipuffer_url', '' ) ); ?>"
                                   placeholder="https://your-aipuffer-site.com">
                        </div>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label">API Key</div><div class="ailg-form-desc">Required for remote instances. If local, usually not needed.</div></div>
                            <input name="ailg_aipuffer_key" type="password" class="ailg-input"
                                   value="<?php echo esc_attr( (string) get_option( 'ailg_aipuffer_key', '' ) ); ?>">
                        </div>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label">Active Chatbot</div></div>
                            <div class="ailg-model-select-wrap">
                                <select name="ailg_aipuffer_bot_id" class="ailg-select" id="ailg-model-aipuffer">
                                    <option value="">Select a Bot...</option>
                                    <?php
                                    $saved = (int) get_option( 'ailg_aipuffer_bot_id' );
                                    $bots = AILG_AIPuffer::discover_bots();
                                    foreach ( $bots as $bot ) echo '<option value="' . esc_attr( $bot['id'] ) . '"' . selected( $saved, $bot['id'], false ) . '>' . esc_html( $bot['name'] ) . '</option>';
                                    ?>
                                </select>
                                <button type="button" class="ailg-btn ailg-btn-secondary ailg-btn-sm ailg-sync-puffer-btn">⟳ Sync Bots</button>
                            </div>
                        </div>
                    </div>

                    <?php
                    // Helper to render provider config blocks
                    $provider_configs = [
                        'openai'     => [
                            'color' => 'purple', 'icon' => '🧠', 'title' => 'OpenAI Configuration',
                            'key_opt' => 'ailg_openai_key',   'key_name' => 'ailg_openai_key',   'key_placeholder' => 'sk-…',        'key_desc' => 'Get from platform.openai.com',
                            'model_opt' => 'ailg_openai_model', 'list_opt' => 'ailg_openai_models_list',
                            'defaults'  => [ 'gpt-4o' => 'GPT-4o (Latest)', 'gpt-4-turbo' => 'GPT-4 Turbo', 'gpt-4o-mini' => 'GPT-4o Mini', 'gpt-3.5-turbo' => 'GPT-3.5 Turbo' ],
                        ],
                        'google'     => [
                            'color' => 'blue',   'icon' => '✨', 'title' => 'Google Gemini Configuration',
                            'key_name' => 'ailg_google_key',   'key_placeholder' => 'AIza…',       'key_desc' => 'Get from aistudio.google.com',
                            'model_opt' => 'ailg_google_model', 'list_opt' => 'ailg_google_models_list',
                            'defaults'  => [ 'gemini-2.0-flash' => 'Gemini 2.0 Flash', 'gemini-1.5-pro' => 'Gemini 1.5 Pro', 'gemini-1.5-flash' => 'Gemini 1.5 Flash' ],
                        ],
                        'openrouter' => [
                            'color' => 'green',  'icon' => '🔄', 'title' => 'OpenRouter Configuration',
                            'key_name' => 'ailg_openrouter_key', 'key_placeholder' => 'sk-or-…',     'key_desc' => 'Get from openrouter.ai',
                            'model_opt' => 'ailg_openrouter_model', 'list_opt' => 'ailg_openrouter_models_list',
                            'defaults'  => [ 'anthropic/claude-3-5-sonnet' => 'Claude 3.5 Sonnet', 'openai/gpt-4o' => 'GPT-4o via OR', 'meta-llama/llama-3.1-70b-instruct' => 'Llama 3.1 70B', 'google/gemini-2.0-flash-001' => 'Gemini 2.0 Flash', 'mistralai/mistral-large' => 'Mistral Large' ],
                        ],
                    ];
                    foreach ( $provider_configs as $pkey => $pc ): ?>
                    <div class="ailg-card" style="margin-bottom:16px">
                        <div class="ailg-card-header">
                            <div class="ailg-card-icon <?php echo esc_attr( $pc['color'] ); ?>"><?php echo $pc['icon']; ?></div>
                            <div class="ailg-card-title"><?php echo esc_html( $pc['title'] ); ?></div>
                        </div>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label">API Key</div><div class="ailg-form-desc"><?php echo esc_html( $pc['key_desc'] ); ?></div></div>
                            <input name="<?php echo esc_attr( $pc['key_name'] ); ?>" type="password" class="ailg-input"
                                   value="<?php echo esc_attr( (string) get_option( $pc['key_name'], '' ) ); ?>"
                                   placeholder="<?php echo esc_attr( $pc['key_placeholder'] ); ?>">
                        </div>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label">Model</div></div>
                            <div class="ailg-model-select-wrap">
                                <select name="<?php echo esc_attr( $pc['model_opt'] ); ?>" class="ailg-select" id="ailg-model-<?php echo esc_attr( $pkey ); ?>">
                                    <?php
                                    $saved_models = (array) get_option( $pc['list_opt'], [] );
                                    $current_m    = (string) get_option( $pc['model_opt'], array_key_first( $pc['defaults'] ) );
                                    $model_list   = ! empty( $saved_models ) ? array_combine( $saved_models, $saved_models ) : $pc['defaults'];
                                    foreach ( $model_list as $id => $name ) {
                                        echo '<option value="' . esc_attr( $id ) . '"' . selected( $current_m, $id, false ) . '>' . esc_html( $name ) . '</option>';
                                    }
                                    ?>
                                </select>
                                <button type="button" class="ailg-btn ailg-btn-secondary ailg-btn-sm ailg-sync-btn" data-provider="<?php echo esc_attr( $pkey ); ?>">⟳ Sync</button>
                                <button type="button" class="ailg-btn ailg-btn-success ailg-btn-sm ailg-test-btn"  data-provider="<?php echo esc_attr( $pkey ); ?>">✓ Test</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Ollama -->
                    <div class="ailg-card" style="margin-bottom:16px">
                        <div class="ailg-card-header"><div class="ailg-card-icon yellow">🏠</div><div class="ailg-card-title">Ollama (Local AI) Configuration</div></div>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label">Ollama Host</div><div class="ailg-form-desc">URL of your local Ollama instance</div></div>
                            <input name="ailg_ollama_host" type="url" class="ailg-input"
                                   value="<?php echo esc_attr( (string) get_option( 'ailg_ollama_host', 'http://localhost:11434' ) ); ?>"
                                   placeholder="http://localhost:11434">
                        </div>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label">Model</div></div>
                            <div class="ailg-model-select-wrap">
                                <select name="ailg_ollama_model" class="ailg-select" id="ailg-model-ollama">
                                    <?php
                                    $saved = (array) get_option( 'ailg_ollama_models_list', [] );
                                    $cur   = (string) get_option( 'ailg_ollama_model', 'llama3.2' );
                                    $defs  = [ 'llama3.2' => 'Llama 3.2', 'llama3.1' => 'Llama 3.1', 'mistral' => 'Mistral', 'qwen2.5' => 'Qwen 2.5', 'phi4' => 'Phi-4' ];
                                    $ml    = ! empty( $saved ) ? array_combine( $saved, $saved ) : $defs;
                                    foreach ( $ml as $id => $name ) echo '<option value="' . esc_attr( $id ) . '"' . selected( $cur, $id, false ) . '>' . esc_html( $name ) . '</option>';
                                    ?>
                                </select>
                                <button type="button" class="ailg-btn ailg-btn-secondary ailg-btn-sm ailg-sync-btn" data-provider="ollama">⟳ Sync</button>
                                <button type="button" class="ailg-btn ailg-btn-success ailg-btn-sm ailg-test-btn"  data-provider="ollama">✓ Test</button>
                            </div>
                        </div>
                        <div class="ailg-alert ailg-alert-info" style="margin-top:12px">
                            ℹ️ Ollama must be running locally with <code style="background:rgba(0,0,0,.07);padding:2px 6px;border-radius:4px;font-family:monospace">ollama serve</code>.
                            Pull models with <code style="background:rgba(0,0,0,.07);padding:2px 6px;border-radius:4px;font-family:monospace">ollama pull &lt;model&gt;</code>.
                        </div>
                    </div>

                    <!-- Custom Prompt -->
                    <div class="ailg-card" style="margin-bottom:24px">
                        <div class="ailg-card-header"><div class="ailg-card-icon purple">💬</div><div class="ailg-card-title">Custom AI Prompt</div></div>
                        <div class="ailg-form-row" style="border:none">
                            <div><div class="ailg-form-label">Custom Prompt</div><div class="ailg-form-desc">Override the default prompt. Use <code>{post_title}</code>, <code>{post_content}</code>, <code>{candidates}</code> as placeholders. Leave blank for the optimised default.</div></div>
                            <textarea name="ailg_custom_prompt" class="ailg-textarea" placeholder="Leave blank to use the default optimised prompt…"><?php echo esc_textarea( (string) get_option( 'ailg_custom_prompt', '' ) ); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- ── Linking Rules Tab ── -->
                <div id="ailg-tab-linking" class="ailg-tab-content" data-group="settings">
                    <div class="ailg-card" style="margin-bottom:16px">
                        <div class="ailg-section-title">🔗 Linking Behaviour</div>
                        <?php
                        $toggles = [
                            [ 'ailg_auto_link_enabled',     'Auto-Link Content',             'Automatically insert accepted links into post content on the front end', false ],
                            [ 'ailg_scan_on_publish',       'Scan on Publish',               'Auto-scan new posts for link opportunities when published', true ],
                            [ 'ailg_restrict_to_silo',      'Silo Guardrails',               'Restrict link suggestions to posts within the same category/silo', false ],
                            [ 'ailg_nofollow_external',     'nofollow External Links',       '', false ],
                            [ 'ailg_open_external_new_tab', 'Open External Links in New Tab','', true ],
                        ];
                        foreach ( $toggles as [ $name, $label, $desc, $default ] ): ?>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label"><?php echo esc_html( $label ); ?></div><?php if ( $desc ) echo '<div class="ailg-form-desc">' . esc_html( $desc ) . '</div>'; ?></div>
                            <label class="ailg-toggle"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) get_option( $name, $default ) ); ?>><span class="ailg-toggle-slider"></span></label>
                        </div>
                        <?php endforeach; ?>

                        <?php
                        $ranges = [
                            [ 'ailg_max_suggestions',    'Max Suggestions Per Post', '',                                         1, 20, 1,    5 ],
                            [ 'ailg_link_limit_per_post','Max Links Per Post',       'Total internal links cap per post',         1, 30, 1,   10 ],
                            [ 'ailg_same_link_limit',    'Same-Link Limit',          'Max times same target can be linked from one post', 1, 5, 1, 2 ],
                            [ 'ailg_min_score',          'Minimum Relevance Score',  'Filter suggestions below this threshold',   0.1, 1, 0.05, 0.65 ],
                        ];
                        foreach ( $ranges as [ $name, $label, $desc, $min, $max, $step, $default ] ): ?>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label"><?php echo esc_html( $label ); ?></div><?php if ( $desc ) echo '<div class="ailg-form-desc">' . esc_html( $desc ) . '</div>'; ?></div>
                            <div class="ailg-range-wrap">
                                <input type="range" name="<?php echo esc_attr( $name ); ?>" class="ailg-range"
                                       min="<?php echo $min; ?>" max="<?php echo $max; ?>" step="<?php echo $step; ?>"
                                       value="<?php echo esc_attr( (string) get_option( $name, $default ) ); ?>">
                                <span class="ailg-range-val"><?php echo esc_html( (string) get_option( $name, $default ) ); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="ailg-card" style="margin-bottom:24px">
                        <div class="ailg-section-title">📝 Post Types &amp; Exclusions</div>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label">Enable for Post Types</div></div>
                            <div style="display:flex;flex-wrap:wrap;gap:12px">
                                <?php foreach ( $post_types as $pt ): ?>
                                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;font-family:Verdana,sans-serif">
                                    <input type="checkbox" name="ailg_auto_link_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $selected_types, true ) ); ?>>
                                    <?php echo esc_html( $pt->label ); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="ailg-form-row" style="border:none">
                            <div><div class="ailg-form-label">Ignore Anchor Words</div><div class="ailg-form-desc">Words that should never be used as link anchors (one per line)</div></div>
                            <textarea name="ailg_ignore_words" class="ailg-textarea"><?php echo esc_textarea( $ignore_words ); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- ── Google Search Console Tab ── -->
                <div id="ailg-tab-google" class="ailg-tab-content" data-group="settings">
                    <div class="ailg-card">
                        <div class="ailg-card-header"><div class="ailg-card-icon blue">📈</div><div class="ailg-card-title">Google Search Console Integration</div></div>
                        <p class="ailg-form-desc">Connect to GSC to enable <strong>Authority Sculpting</strong>. The plugin will automatically prioritize internal links to pages in "Striking Distance" (Position 4-15) to help push them to Page 1.</p>

                        <?php if ( AILG_Google::is_connected() ): ?>
                            <div class="ailg-alert ailg-alert-success">✅ Search Console is connected.</div>
                            <div class="ailg-form-row">
                                <div><div class="ailg-form-label">GSC Property</div><div class="ailg-form-desc">Select the property for this site.</div></div>
                                <input name="ailg_gsc_property" class="ailg-input" value="<?php echo esc_attr( get_option('ailg_gsc_property') ); ?>" placeholder="sc-domain:yoursite.com or https://yoursite.com/">
                            </div>
                        <?php else: ?>
                            <div class="ailg-form-row">
                                <div><div class="ailg-form-label">Client ID</div></div>
                                <input name="ailg_gsc_client_id" class="ailg-input" value="<?php echo esc_attr( get_option('ailg_gsc_client_id') ); ?>">
                            </div>
                            <div class="ailg-form-row">
                                <div><div class="ailg-form-label">Client Secret</div></div>
                                <input name="ailg_gsc_client_secret" type="password" class="ailg-input" value="<?php echo esc_attr( get_option('ailg_gsc_client_secret') ); ?>">
                            </div>
                            <div style="margin-top:20px; display:flex; gap:10px;">
                                <a href="<?php echo esc_url( AILG_Google::get_auth_url() ); ?>" class="ailg-btn ailg-btn-primary">Connect Search Console</a>
                                <?php if ( class_exists('AILG_VMSB_Integration') && AILG_VMSB_Integration::has_gsc_connection() ): ?>
                                    <button type="button" id="ailg-sync-vmsb-gsc" class="ailg-btn ailg-btn-secondary">🔗 Sync from VM SEO Brain</button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Advanced Tab ── -->
                <div id="ailg-tab-advanced" class="ailg-tab-content" data-group="settings">
                    <div class="ailg-card" style="margin-bottom:16px">
                        <div class="ailg-card-header"><div class="ailg-card-icon purple">🧠</div><div class="ailg-card-title">Strategic Context (Sentience)</div></div>
                        <p class="ailg-form-desc">Inject high-level site intelligence into every AI call to ensure the bot "knows" your business and niche before it starts writing.</p>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label">Business DNA / Brand Voice</div><div class="ailg-form-desc">Describe your business, values, and tone of voice.</div></div>
                            <textarea name="ailg_business_dna" class="ailg-textarea" placeholder="e.g. A luxury travel blog focusing on sustainable tourism and hidden gems in Europe..."><?php echo esc_textarea( (string) get_option( 'ailg_business_dna', '' ) ); ?></textarea>
                        </div>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label">Topical Niche</div><div class="ailg-form-desc">Define your primary topical authority.</div></div>
                            <input name="ailg_topical_niche" class="ailg-input" value="<?php echo esc_attr( (string) get_option( 'ailg_topical_niche', '' ) ); ?>" placeholder="e.g. European Travel & Luxury Hospitality">
                        </div>
                        <div class="ailg-form-row" style="border:none">
                            <div><div class="ailg-form-label">Topical Entities (Knowledge Graph)</div><div class="ailg-form-desc">Key entities or topics you want to prioritize (comma separated).</div></div>
                            <textarea name="ailg_topical_entities" class="ailg-textarea" placeholder="e.g. Sustainable Travel, Boutique Hotels, Mediterranean Cuisine, UNESCO World Heritage Sites..."><?php echo esc_textarea( (string) get_option( 'ailg_topical_entities', '' ) ); ?></textarea>
                        </div>
                    </div>

                    <div class="ailg-card" style="margin-bottom:16px">
                        <div class="ailg-section-title">♻️ Global Link Target Swap</div>
                        <p class="ailg-form-desc">Replace all internal links pointing to an old URL with a new URL (e.g., when you consolidate thin content into a new pillar guide).</p>
                        <div style="display:flex; flex-direction:column; gap:12px; margin-top:15px">
                            <input id="ailg-swap-old" class="ailg-input" placeholder="Old URL (e.g. https://site.com/old-post/)">
                            <input id="ailg-swap-new" class="ailg-input" placeholder="New URL (e.g. https://site.com/ultimate-guide/)">
                            <button type="button" id="ailg-swap-btn" class="ailg-btn ailg-btn-danger" style="width:fit-content">🚀 Execute Global Swap</button>
                        </div>
                    </div>
                    <div class="ailg-card" style="margin-bottom:16px">
                        <div class="ailg-section-title">🧬 Semantic Analysis</div>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label">Use LSI Keywords</div><div class="ailg-form-desc">Extract latent semantic indexing keywords for better matching</div></div>
                            <label class="ailg-toggle"><input type="checkbox" name="ailg_use_lsi_keywords" value="1" <?php checked( (bool) get_option( 'ailg_use_lsi_keywords', true ) ); ?>><span class="ailg-toggle-slider"></span></label>
                        </div>
                        <div class="ailg-form-row" style="border:none">
                            <div><div class="ailg-form-label">Semantic Threshold</div><div class="ailg-form-desc">Minimum cosine similarity score for semantic matching</div></div>
                            <div class="ailg-range-wrap">
                                <input type="range" name="ailg_semantic_threshold" class="ailg-range" min="0.3" max="1" step="0.05"
                                       value="<?php echo esc_attr( (string) get_option( 'ailg_semantic_threshold', 0.72 ) ); ?>">
                                <span class="ailg-range-val"><?php echo esc_html( (string) get_option( 'ailg_semantic_threshold', 0.72 ) ); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="ailg-card" style="margin-bottom:24px">
                        <div class="ailg-section-title">🛡️ Safety &amp; Performance Features</div>
                        <div class="ailg-alert ailg-alert-info">ℹ️ The following features are automatically managed to ensure optimal performance and API cost efficiency.</div>
                        <div class="ailg-grid ailg-grid-3" style="margin-top:16px">
                            <?php
                            $features = [
                                [ '🚀', 'Request Batching',       'Groups multiple post analyses into single API calls',            'green'  ],
                                [ '💾', 'Smart Caching',          'Caches AI responses to minimise repeat API usage',               'blue'   ],
                                [ '⚖️', 'Rate Limiting',          'Respects provider rate limits automatically',                    'purple' ],
                                [ '🔄', 'Retry Logic',            'Auto-retries failed requests with exponential back-off',         'yellow' ],
                                [ '🧹', 'Content Sanitization',   'Strips HTML before sending to AI for clean analysis',            'green'  ],
                                [ '📏', 'Token Management',       'Optimises prompt length to fit model context windows',           'blue'   ],
                            ];
                            foreach ( $features as [ $icon, $title, $desc, $color ] ): ?>
                            <div style="background:var(--ailg-surface2);border:1px solid var(--ailg-border);border-radius:var(--ailg-radius);padding:18px">
                                <div style="font-size:26px;margin-bottom:8px"><?php echo $icon; ?></div>
                                <div style="font-size:13px;font-weight:700;margin-bottom:4px;color:var(--ailg-text)"><?php echo esc_html( $title ); ?></div>
                                <div style="font-size:12px;color:var(--ailg-text-dim);line-height:1.5;font-family:Verdana,sans-serif"><?php echo esc_html( $desc ); ?></div>
                                <span class="ailg-tag ailg-tag-<?php echo esc_attr( $color ); ?>" style="margin-top:10px">Active</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- ── License Tab ── -->
                <div id="ailg-tab-license" class="ailg-tab-content" data-group="settings">
                    <div class="ailg-card" style="max-width:620px">
                        <div class="ailg-section-title">🔑 License Activation</div>
                        <div class="ailg-form-row">
                            <div><div class="ailg-form-label">License Key</div><div class="ailg-form-desc">Enter your Pro license key to unlock all features</div></div>
                            <input name="ailg_license_key" type="text" class="ailg-input" id="ailg-license-key-input"
                                   value="<?php echo esc_attr( (string) get_option( 'ailg_license_key', '' ) ); ?>"
                                   placeholder="AILG-XXXX-XXXX-XXXX-XXXX">
                        </div>
                        <div style="padding:8px 0 16px">
                            <?php
                            $lk = (string) get_option( 'ailg_license_key', '' );
                            if ( ! empty( $lk ) ) echo '<div class="ailg-alert ailg-alert-success">✅ License key entered. Pro features are enabled.</div>';
                            else echo '<div class="ailg-alert ailg-alert-warning">⚠️ No license key entered. Some advanced features may be limited.</div>';
                            ?>
                        </div>
                        <div class="ailg-section-title" style="margin-top:8px">✨ Pro Features Included</div>
                        <?php
                        $pro_features = [
                            'Unlimited AI-powered link suggestions',
                            'All 4 AI providers (OpenAI, Google, OpenRouter, Ollama)',
                            'Full automation rules engine with scheduling',
                            'Broken link checker across all posts',
                            'Orphaned content finder & bulk fix',
                            'Link network visualisation map',
                            'Bulk scan & auto-insert tools',
                            'Priority email support',
                            'Lifetime updates',
                        ];
                        foreach ( $pro_features as $f ): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--ailg-border);font-size:13px;font-family:Verdana,sans-serif">
                            <span style="color:var(--ailg-green);font-weight:700">✓</span> <?php echo esc_html( $f ); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="padding:0 32px 32px;margin-top:8px">
                    <button type="submit" class="ailg-btn ailg-btn-primary" style="min-width:180px;font-size:14px">💾 Save All Settings</button>
                </div>
            </form>
        </div>
        <?php
    }
}