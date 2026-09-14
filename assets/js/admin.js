(function($){
'use strict';

/* ─── Utility ────────────────────────────────────────────────── */
function escHtml(s){
    return String(s)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}

function showNotice(msg, type){
    var cls = {success:'ailg-alert-success',error:'ailg-alert-error',info:'ailg-alert-info',warning:'ailg-alert-warning'};
    var n = $('<div class="ailg-alert '+(cls[type]||'ailg-alert-info')+'">'+escHtml(msg)+'</div>');
    n.css({
        position:'fixed',top:'40px',right:'28px',zIndex:99999,
        minWidth:'300px',maxWidth:'480px',
        boxShadow:'0 8px 32px rgba(79,70,229,0.18)',
        animation:'ailg-slide-in .3s ease',
        cursor:'pointer'
    });
    n.on('click', function(){ n.fadeOut(200,function(){ n.remove(); }); });
    $('body').append(n);
    setTimeout(function(){ n.fadeOut(400,function(){ n.remove(); }); }, 4000);
}

function renderBarChart(sel, data){
    var wrap = $(sel);
    if(!wrap.length || !data || !data.length) return;
    var max = Math.max.apply(null, $.map(data, function(d){ return d.v; }));
    if(!max) max = 1;
    var html = '<div class="ailg-chart-bar">';
    $.each(data, function(i, d){
        var h = Math.round((d.v / max) * 100);
        html += '<div class="ailg-chart-col" title="'+escHtml(d.l)+': '+escHtml(String(d.v))+' links">';
        html += '<div class="ailg-chart-col-bar" style="height:'+h+'%"></div>';
        html += '<div class="ailg-chart-col-label">'+escHtml(d.l)+'</div>';
        html += '</div>';
    });
    html += '</div>';
    wrap.html(html);
}

/* ─── Tabs ───────────────────────────────────────────────────── */
function initTabs(){
    $(document).on('click','.ailg-tab',function(){
        var group  = $(this).data('group') || 'default';
        var target = $(this).data('target');
        $('[data-group="'+group+'"].ailg-tab').removeClass('active');
        $('[data-group="'+group+'"].ailg-tab-content').removeClass('active');
        $(this).addClass('active');
        $('#'+target).addClass('active');
    });
}

/* ─── Range Sliders ──────────────────────────────────────────── */
function initRanges(){
    $(document).on('input','.ailg-range',function(){
        $(this).closest('.ailg-range-wrap').find('.ailg-range-val').text($(this).val());
    });
}

/* ─── Test Connection ─────────────────────────────────────────── */
function initTestConnection(){
    $(document).on('click','.ailg-test-btn',function(){
        var btn = $(this);
        var provider = btn.data('provider');
        var orig = btn.html();

        // Grab current input values
        var key  = $('[name="ailg_'+provider+'_key"]').val();
        var host = $('[name="ailg_'+provider+'_host"]').val();
        var url  = $('[name="ailg_'+provider+'_url"]').val();

        btn.html('<span class="ailg-spinner"></span> Testing…').prop('disabled',true);
        $.post(AILG.ajax_url,{
            action:'ailg_test_connection',
            nonce:AILG.nonce,
            provider:provider,
            api_key: key,
            host: host,
            url: url
        },function(res){
            btn.html(orig).prop('disabled',false);
            var el = $('#ailg-status-'+provider);
            if(res.success){
                el.html('<span class="ailg-dot ailg-dot-green"></span> Connected — '+escHtml(String(res.data.model_count))+' models');
                showNotice('✓ '+escHtml(res.data.message),'success');
            } else {
                el.html('<span class="ailg-dot ailg-dot-red"></span> Failed');
                showNotice('✗ '+(res.data||'Connection failed'),'error');
            }
        }).fail(function(){
            btn.html(orig).prop('disabled',false);
            showNotice('Request failed. Check server connectivity.','error');
        });
    });
}

/* ─── Sync Models ────────────────────────────────────────────── */
function initSyncModels(){
    $(document).on('click','.ailg-sync-btn',function(){
        var btn = $(this);
        var provider = btn.data('provider');
        var key = $('[name="ailg_'+provider+'_key"]').val();
        var url = $('[name="ailg_'+provider+'_url"]').val();

        btn.html('<span class="ailg-spinner"></span>').prop('disabled',true);
        $.post(AILG.ajax_url,{
            action:'ailg_sync_models',
            nonce:AILG.nonce,
            provider:provider,
            api_key: key,
            url: url
        },function(res){
            btn.html('⟳ Sync').prop('disabled',false);
            if(res.success){
                var sel = $('#ailg-model-'+provider);
                sel.empty();
                $.each(res.data.models,function(i,m){
                    sel.append($('<option>').val(m.id).text(m.name||m.id));
                });
                showNotice('✓ Models synced: '+res.data.models.length+' available','success');
            } else {
                showNotice(res.data||'Sync failed — check your API key.','error');
            }
        }).fail(function(){
            btn.html('⟳ Sync').prop('disabled',false);
            showNotice('Sync request failed.','error');
        });
    });

    $(document).on('click','.ailg-sync-puffer-btn',function(){
        var btn = $(this);
        var url = $('[name="ailg_aipuffer_url"]').val();
        var key = $('[name="ailg_aipuffer_key"]').val();

        btn.html('<span class="ailg-spinner"></span>').prop('disabled',true);
        $.post(AILG.ajax_url,{
            action:'ailg_sync_aipuffer',
            nonce:AILG.nonce,
            aipuffer_url: url,
            aipuffer_key: key
        },function(res){
            btn.html('⟳ Sync Bots').prop('disabled',false);
            if(res.success){
                var sel = $('#ailg-model-aipuffer');
                sel.empty();
                sel.append($('<option>').val('').text('Select a Bot...'));
                $.each(res.data.bots,function(i,bot){
                    sel.append($('<option>').val(bot.id).text(bot.name));
                });
                showNotice('✓ Bots synced: '+res.data.bots.length+' available','success');
            } else {
                showNotice(res.data||'Sync failed.','error');
            }
        }).fail(function(){
            btn.html('⟳ Sync Bots').prop('disabled',false);
            showNotice('Sync request failed.','error');
        });
    });

    $(document).on('click','#ailg-sync-vmsb-gsc',function(){
        var btn = $(this);
        btn.html('<span class="ailg-spinner"></span> Syncing…').prop('disabled',true);
        $.post(AILG.ajax_url,{
            action:'ailg_sync_vmsb_gsc', nonce:AILG.nonce
        },function(res){
            btn.html('🔗 Sync from VM SEO Brain').prop('disabled',false);
            if(res.success){
                showNotice('✓ '+res.data.message,'success');
                setTimeout(function(){ location.reload(); }, 1000);
            } else {
                showNotice(res.data||'Sync failed.','error');
            }
        }).fail(function(){
            btn.html('🔗 Sync from VM SEO Brain').prop('disabled',false);
            showNotice('Sync request failed.','error');
        });
    });
}

/* ─── Metabox ─────────────────────────────────────────────────── */
function initMetabox(){
    $(document).on('click','#ailg-analyze-btn',function(){
        var btn = $(this);
        var postId = btn.data('post-id');
        var res = $('#ailg-metabox-results');
        btn.html('<span class="ailg-spinner"></span> Analyzing…').prop('disabled',true);
        res.html('<div style="padding:24px;text-align:center"><span class="ailg-spinner" style="width:28px;height:28px;border-width:3px"></span><p style="color:var(--ailg-text-dim);margin-top:14px;font-size:13px">AI is analyzing your content and finding link opportunities…</p></div>');

        $.post(AILG.ajax_url,{
            action:'ailg_get_suggestions', nonce:AILG.nonce, post_id:postId
        },function(r){
            btn.html('🤖 Analyze &amp; Suggest Links').prop('disabled',false);
            if(r.success && r.data.suggestions && r.data.suggestions.length){
                var cached = r.data.cached ? ' <span style="font-size:11px;opacity:.7">(cached)</span>' : '';
                var html = '<div class="ailg-alert ailg-alert-info">🎯 Found <strong>'+r.data.suggestions.length+'</strong> link opportunities'+cached+'</div>';
                $.each(r.data.suggestions,function(i,s){
                    var pct = Math.round(s.score*100);
                    var badge = s.is_bridge ? '<span class="ailg-tag ailg-tag-purple" style="margin-left:8px">Context Bridge</span>' : '';
                    if(s.is_image) badge = '<span class="ailg-tag ailg-tag-blue" style="margin-left:8px">Image Link</span>';
                    if(s.is_ghost) badge = '<span class="ailg-tag ailg-tag-red" style="margin-left:8px">Content Gap</span>';
                    html += '<div class="ailg-suggestion" data-id="'+escHtml(String(s.id))+'">';
                    html += '<div class="ailg-suggestion-header">';
                    html += '<div class="ailg-suggestion-score">'+pct+'%</div>';
                    html += '<div class="ailg-suggestion-meta">';
                    html += '<div class="ailg-suggestion-title">'+escHtml(s.target_title||'Post #'+s.target_id)+badge+'</div>';
                    html += '<div class="ailg-suggestion-anchor">Anchor: &ldquo;'+escHtml(s.anchor_text)+'&rdquo;</div>';
                    html += '</div></div>';
                    if(s.context) html += '<div class="ailg-suggestion-context">'+escHtml(s.context)+'</div>';
                    html += '<div class="ailg-score-bar" style="margin-bottom:10px"><div class="ailg-score-fill" style="width:'+pct+'%"></div></div>';
                    html += '<div class="ailg-suggestion-actions">';
                    html += '<button class="ailg-btn ailg-btn-success ailg-btn-sm ailg-accept-btn" data-id="'+escHtml(String(s.id))+'" data-post="'+escHtml(String(postId))+'">✓ Insert Link</button>';
                    html += '<button class="ailg-btn ailg-btn-secondary ailg-btn-sm ailg-dismiss-btn" data-id="'+escHtml(String(s.id))+'">✗ Dismiss</button>';
                    html += '</div></div>';
                });
                res.html(html);
            } else {
                var msg = (r.data && r.data.message) ? r.data.message : 'No link opportunities found. Ensure your post has enough content and other posts exist.';
                res.html('<div class="ailg-empty"><div class="ailg-empty-icon">🔍</div><p>'+escHtml(msg)+'</p></div>');
            }
        }).fail(function(){
            btn.html('🤖 Analyze &amp; Suggest Links').prop('disabled',false);
            res.html('<div class="ailg-alert ailg-alert-error">⚠️ Request failed. Please try again.</div>');
        });
    });

    $(document).on('click','.ailg-accept-btn',function(){
        var btn = $(this);
        var id  = btn.data('id');
        btn.html('<span class="ailg-spinner"></span>').prop('disabled',true);
        $.post(AILG.ajax_url,{action:'ailg_insert_link',nonce:AILG.nonce,suggestion_id:id},function(r){
            if(r.success){
                btn.closest('.ailg-suggestion').fadeOut(300,function(){ $(this).remove(); });
                showNotice('✓ Link inserted successfully!','success');
            } else {
                btn.html('✓ Insert Link').prop('disabled',false);
                showNotice(r.data||'Failed to insert link','error');
            }
        }).fail(function(){
            btn.html('✓ Insert Link').prop('disabled',false);
            showNotice('Request failed.','error');
        });
    });

    $(document).on('click','.ailg-dismiss-btn',function(){
        var id = $(this).data('id');
        $(this).closest('.ailg-suggestion').fadeOut(200,function(){ $(this).remove(); });
        $.post(AILG.ajax_url,{action:'ailg_dismiss_suggestion',nonce:AILG.nonce,suggestion_id:id});
    });

    $(document).on('click', '#ailg-bulk-accept-btn', function(){
        if(!confirm('This will automatically insert all suggestions with a score of 80% or higher. Continue?')) return;
        var btn = $(this);
        var orig = btn.html();
        btn.html('<span class="ailg-spinner"></span> Accepting…').prop('disabled', true);

        $.post(AILG.ajax_url, {
            action: 'ailg_bulk_accept_suggs',
            nonce: AILG.nonce,
            min_score: 0.8
        }, function(r){
            btn.html(orig).prop('disabled', false);
            if(r.success) {
                showNotice('✓ ' + r.data.message, 'success');
                setTimeout(function(){ location.reload(); }, 1500);
            } else {
                showNotice(r.data || 'Bulk accept failed', 'error');
            }
        });
    });
}

/* ─── Dashboard Stats ─────────────────────────────────────────── */
function loadDashboardStats(){
    if(!$('#ailg-dash-stats').length) return;
    $.post(AILG.ajax_url,{action:'ailg_get_dashboard_stats',nonce:AILG.nonce},function(r){
        if(!r.success) {
            console.error('Stats load failed:', r.data);
            return;
        }
        var d = r.data;
        $('#ailg-stat-links').text(d.total_links||0);
        $('#ailg-stat-suggestions').text(d.pending_suggestions||0);
        $('#ailg-stat-orphaned').text(d.orphaned||0);
        $('#ailg-stat-broken').text(d.broken||0);
        if(d.chart_data){ renderBarChart('#ailg-weekly-chart',d.chart_data); }
    }).fail(function(){
        console.error('Dashboard stats request failed.');
    });
}

/* ─── Reports ────────────────────────────────────────────────── */
function loadReports(){
    if(!$('#ailg-reports-wrap').length) return;
    $('#ailg-reports-wrap').html('<div style="padding:48px;text-align:center"><span class="ailg-spinner" style="width:28px;height:28px;border-width:3px"></span><p style="color:var(--ailg-text-dim);margin-top:14px">Loading report data…</p></div>');
    $.post(AILG.ajax_url,{action:'ailg_get_report_data',nonce:AILG.nonce},function(r){
        if(!r.success) return;
        renderReportTable(r.data);
    });

    // Semantic Audit
    if($('#ailg-audit-wrap').length){
        $.post(AILG.ajax_url, {action:'ailg_semantic_audit', nonce:AILG.nonce}, function(r){
            if(!r.success || !r.data.gaps || !r.data.gaps.length){
                $('#ailg-audit-wrap').html('<div class="ailg-empty">✅ All topical hubs have healthy internal connectivity (>60%).</div>');
                return;
            }
            var html = '<div class="ailg-table-wrap"><table class="ailg-table"><thead><tr><th>Pillar Hub</th><th>Connectivity</th><th>Gap Analysis</th><th>Action</th></tr></thead><tbody>';
            $.each(r.data.gaps, function(i, g){
                var cls = g.coverage < 30 ? 'ailg-tag-red' : 'ailg-tag-yellow';
                html += '<tr>';
                html += '<td><strong>'+escHtml(g.pillar_title)+'</strong></td>';
                html += '<td><span class="ailg-tag '+cls+'">'+g.coverage+'% Linked</span></td>';
                html += '<td><span style="color:var(--ailg-text-dim)">'+g.missing+' supporting posts are missing links to this hub.</span></td>';
                html += '<td><button class="ailg-btn ailg-btn-primary ailg-btn-xs ailg-reinforce-btn" data-post="'+g.pillar_id+'">Reinforce Hub</button></td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            $('#ailg-audit-wrap').html(html);
        });
    }

    // Link Decay
    if($('#ailg-decay-wrap').length){
        $.post(AILG.ajax_url, {action:'ailg_get_link_decay', nonce:AILG.nonce}, function(r){
            if(!r.success || !r.data.decayed || !r.data.decayed.length){
                $('#ailg-decay-wrap').html('<div class="ailg-empty">✅ No stale links found. All tracked links have healthy engagement.</div>');
                return;
            }
            var html = '<div class="ailg-table-wrap"><table class="ailg-table"><thead><tr><th>Source Post</th><th>Anchor Text</th><th>Target</th><th>Clicks</th><th>Status</th></tr></thead><tbody>';
            $.each(r.data.decayed, function(i, d){
                html += '<tr>';
                html += '<td><strong>'+escHtml(d.source_title)+'</strong></td>';
                html += '<td>"'+escHtml(d.anchor_text)+'"</td>';
                html += '<td><a href="'+escHtml(d.target_url)+'" target="_blank">View Target</a></td>';
                html += '<td><span class="ailg-tag ailg-tag-red">'+(d.clicks||0)+'</span></td>';
                html += '<td><span style="color:var(--ailg-accent)">Stale</span></td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            $('#ailg-decay-wrap').html(html);
        });
    }
}

function renderReportTable(data){
    if(!data||!data.posts||!data.posts.length){
        $('#ailg-reports-wrap').html('<div class="ailg-empty"><div class="ailg-empty-icon">📊</div><p>No data yet. Run a scan first.</p></div>');
        return;
    }
    var html = '<div class="ailg-table-wrap"><table class="ailg-table"><thead><tr><th>Post</th><th>Type</th><th>Inbound</th><th>Outbound</th><th>Clicks</th><th>Score</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
    $.each(data.posts,function(i,p){
        var linked = parseInt(p.inbound) > 0;
        var statusHtml = linked ? '<span class="ailg-tag ailg-tag-green">Linked</span>' : '<span class="ailg-tag ailg-tag-red">Orphaned</span>';
        html += '<tr>';
        html += '<td><a href="'+escHtml(p.edit_url||'')+'" style="color:var(--ailg-primary);font-weight:600;text-decoration:none">'+escHtml(p.post_title)+'</a></td>';
        html += '<td><span class="ailg-tag ailg-tag-blue">'+escHtml(p.post_type)+'</span></td>';
        html += '<td style="font-weight:700;color:var(--ailg-primary)">'+escHtml(String(p.inbound))+'</td>';
        html += '<td style="color:var(--ailg-text-mid)">'+escHtml(String(p.outbound))+'</td>';
        html += '<td style="font-weight:700;color:var(--ailg-green)">'+escHtml(String(p.clicks||0))+'</td>';
        html += '<td><div style="display:flex;align-items:center;gap:8px"><div class="ailg-score-bar" style="width:70px"><div class="ailg-score-fill" style="width:'+escHtml(String(p.link_score||0))+'%"></div></div><span style="font-size:11px;color:var(--ailg-text-dim)">'+escHtml(String(p.link_score||0))+'%</span></div></td>';
        html += '<td>'+statusHtml+'</td>';
        html += '<td><div style="display:flex;gap:5px">';
        html += '<button class="ailg-btn ailg-btn-secondary ailg-btn-xs ailg-suggest-report-btn" data-post="'+escHtml(String(p.ID))+'" title="Scan this post for outgoing links">Suggest Outbound</button>';
        html += '<button class="ailg-btn ailg-btn-primary ailg-btn-xs ailg-reinforce-btn" data-post="'+escHtml(String(p.ID))+'" title="Scan other posts for links pointing TO this one">Reinforce Pillar</button>';
        html += '<a href="'+escHtml(p.edit_url||'')+'" class="ailg-btn ailg-btn-secondary ailg-btn-xs">Edit</a>';
        html += '</div></td>';
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    $('#ailg-reports-wrap').html(html);
}

function processReinforcementQueue(items, btn, origText, successMsg, defaultTargetId) {
    var total = items.length;
    var processed = 0;

    function next() {
        if (items.length === 0) {
            btn.html('✓ Done').prop('disabled', true);
            showNotice(successMsg, 'success');
            return;
        }

        var item = items.shift();
        var postId = (typeof item === 'object' && item !== null) ? item.post_id : item;
        var targetId = (typeof item === 'object' && item !== null) ? (item.target_id || defaultTargetId || 0) : (defaultTargetId || 0);

        processed++;
        btn.html('<span class="ailg-spinner"></span> ' + processed + '/' + total);

        var payload = {
            action: 'ailg_scan_single_item',
            nonce: AILG.nonce,
            post_id: postId
        };
        if (targetId) {
            payload.target_id = targetId;
        }

        $.post(AILG.ajax_url, payload, function() {
            next();
        }).fail(function() {
            next();
        });
    }

    next();
}

$(document).on('click','.ailg-reinforce-btn',function(){
    var pid = $(this).data('post');
    var btn = $(this);
    var orig = btn.html();
    btn.html('<span class="ailg-spinner"></span> Initializing…').prop('disabled',true);

    $.post(AILG.ajax_url,{action:'ailg_fix_orphaned',nonce:AILG.nonce,post_id:pid},function(r){
        if(r.success && r.data.ids && r.data.ids.length > 0) {
            processReinforcementQueue(r.data.ids, btn, orig, '✓ Hub reinforced! Suggestions generated.', pid);
        } else {
            btn.html(orig).prop('disabled',false);
            showNotice('No related posts found for reinforcement.', 'info');
        }
    }).fail(function(){
        btn.html(orig).prop('disabled',false);
        showNotice('Reinforcement request failed.', 'error');
    });
});

$(document).on('click','.ailg-suggest-report-btn',function(){
    var pid = $(this).data('post');
    var btn = $(this);
    btn.html('<span class="ailg-spinner"></span>').prop('disabled',true);
        $.post(AILG.ajax_url,{action:'ailg_get_suggestions',nonce:AILG.nonce,post_id:pid},function(r){
            btn.html('Suggest Links').prop('disabled',false);
            if(r.success) {
                showNotice('✓ Suggestions generated!','success');
                // If we are on the Suggestions page, reload to show them
                if($('.ailg-tab[data-target="ailg-tab-internal"]').length) {
                    location.reload();
                }
            }
            else showNotice(r.data&&r.data.message||'No suggestions found.','info');
        });
});

/* ─── Orphaned Posts ─────────────────────────────────────────── */
function loadOrphaned(){
    if(!$('#ailg-orphaned-wrap').length) return;
    $.post(AILG.ajax_url,{action:'ailg_get_orphaned',nonce:AILG.nonce},function(r){
        if(!r.success){ $('#ailg-orphaned-wrap').html('<div class="ailg-alert ailg-alert-error">Failed to load orphaned posts.</div>'); return; }

        if(!r.data.ready) {
            var p = r.data.progress || {percent:0, done:0, total:0};
            $('#ailg-orphaned-wrap').html(
                '<div class="ailg-empty">' +
                '<div class="ailg-empty-icon">🏗️</div>' +
                '<h3>Link Indexing in Progress (' + p.percent + '%)</h3>' +
                '<p>' + (r.data.message || 'The link index is being built. Orphan detection will be available once the scan is complete.') + '</p>' +
                '<div class="ailg-progress" style="max-width:400px;margin:20px auto"><div class="ailg-progress-bar" style="width:' + p.percent + '%"></div></div>' +
                '<button class="ailg-btn ailg-btn-primary" id="ailg-run-indexer-btn">🛠️ Process Index Batch</button>' +
                '</div>'
            );
            return;
        }

        if(!r.data.posts||!r.data.posts.length){
            $('#ailg-orphaned-wrap').html('<div class="ailg-empty"><div class="ailg-empty-icon">🎉</div><p>No orphaned content found! All posts have inbound links.</p></div>');
            return;
        }
        var html = '<div class="ailg-alert ailg-alert-warning">⚠️ Found <strong>'+r.data.posts.length+'</strong> orphaned posts with no inbound internal links</div>';
        html += '<div class="ailg-table-wrap"><table class="ailg-table"><thead><tr><th><input type="checkbox" id="ailg-check-all"></th><th>Post</th><th>Type</th><th>Published</th><th>Actions</th></tr></thead><tbody>';
        $.each(r.data.posts,function(i,p){
            html += '<tr><td><input type="checkbox" class="ailg-orphan-check" value="'+escHtml(String(p.ID))+'"></td>';
            html += '<td><a href="'+escHtml(p.edit_url||'')+'" style="color:var(--ailg-primary);font-weight:600;text-decoration:none">'+escHtml(p.post_title)+'</a></td>';
            html += '<td><span class="ailg-tag ailg-tag-blue">'+escHtml(p.post_type)+'</span></td>';
            html += '<td style="color:var(--ailg-text-dim);font-size:12px">'+escHtml(String(p.post_date||'').substring(0,10))+'</td>';
            html += '<td><div style="display:flex;gap:5px">';
            html += '<button class="ailg-btn ailg-btn-primary ailg-btn-xs ailg-fix-orphan" data-post="'+escHtml(String(p.ID))+'">🤖 Auto Fix</button>';
            html += '<a href="'+escHtml(p.edit_url||'')+'" class="ailg-btn ailg-btn-secondary ailg-btn-xs">Edit</a>';
            html += '</div></td></tr>';
        });
        html += '</tbody></table></div>';
        html += '<div style="margin-top:16px"><button class="ailg-btn ailg-btn-primary ailg-bulk-fix-orphan">🤖 Fix All Selected</button></div>';
        $('#ailg-orphaned-wrap').html(html);
    });
}

$(document).on('click', '#ailg-run-indexer-btn', function(){
    var btn = $(this);
    var orig = btn.html();
    btn.html('<span class="ailg-spinner"></span> Processing…').prop('disabled', true);

    function runBatch() {
        $.post(AILG.ajax_url, {action:'ailg_run_index_batch', nonce:AILG.nonce}, function(r){
            if(r.success) {
                if(r.data.remaining > 0) {
                    var pct = Math.round(((r.data.scanned + (r.data.total_links_processed || 0)) / (r.data.remaining + r.data.scanned)) * 100); // Rough estimate
                    // Actually let's just reload the page to refresh progress from server truth
                    loadOrphaned();
                } else {
                    showNotice('✓ Link index complete!', 'success');
                    loadOrphaned();
                }
            } else {
                btn.html(orig).prop('disabled', false);
                showNotice('Indexer failed.', 'error');
            }
        }).fail(function(){
            btn.html(orig).prop('disabled', false);
            showNotice('Request failed.', 'error');
        });
    }

    runBatch();
});

$(document).on('change','#ailg-check-all',function(){
    $('.ailg-orphan-check').prop('checked',$(this).is(':checked'));
});

$(document).on('click','.ailg-fix-orphan',function(){
    var btn = $(this); var pid = btn.data('post');
    var orig = btn.html();
    btn.html('<span class="ailg-spinner"></span> Initializing…').prop('disabled',true);

    $.post(AILG.ajax_url,{action:'ailg_fix_orphaned',nonce:AILG.nonce,post_id:pid},function(r){
        if(r.success && r.data.ids && r.data.ids.length > 0) {
            processReinforcementQueue(r.data.ids, btn, orig, '✓ Link opportunities generated for this post!', pid);
        } else {
            btn.html(orig).prop('disabled',false);
            showNotice('No candidate posts found to link to this orphan.', 'info');
        }
    }).fail(function(){
        btn.html(orig).prop('disabled',false);
        showNotice('Fix request failed.', 'error');
    });
});

$(document).on('click','.ailg-bulk-fix-orphan',function(){
    var ids = [];
    $('.ailg-orphan-check:checked').each(function(){ ids.push($(this).val()); });
    if(!ids.length){ showNotice('Select at least one post first.','warning'); return; }

    var btn = $(this);
    var orig = btn.html();
    btn.html('<span class="ailg-spinner"></span> Initializing Bulk Fix…').prop('disabled',true);

    var allScanItems = [];

    function collectIds() {
        if (ids.length === 0) {
            if (allScanItems.length === 0) {
                btn.html(orig).prop('disabled', false);
                showNotice('No related posts found for the selected orphans.', 'info');
                return;
            }
            processReinforcementQueue(allScanItems, btn, orig, '✓ Bulk fix complete! Suggestions generated.');
            return;
        }

        var currentOrphanId = ids.shift();
        $.post(AILG.ajax_url, {action:'ailg_fix_orphaned', nonce:AILG.nonce, post_id:currentOrphanId}, function(r){
            if(r.success && r.data.ids) {
                $.each(r.data.ids, function(i, candId) {
                    allScanItems.push({ post_id: candId, target_id: currentOrphanId });
                });
            }
            collectIds();
        }).fail(function(){
            collectIds();
        });
    }

    collectIds();
});

/* ─── Broken Links ───────────────────────────────────────────── */
function initBrokenCheck(){
    $(document).on('click','.ailg-check-broken-btn',function(){
        var btn = $(this);
        btn.html('<span class="ailg-spinner"></span> Initializing…').prop('disabled',true);
        $('#ailg-broken-wrap').html('<div style="padding:48px;text-align:center"><span class="ailg-spinner" style="width:28px;height:28px;border-width:3px"></span><p style="color:var(--ailg-text-dim);margin-top:14px">Preparing broken link scan…</p></div>');

        $.post(AILG.ajax_url, {action:'ailg_get_broken_queue', nonce:AILG.nonce}, function(r){
            if(!r.success || !r.data.ids || !r.data.ids.length){
                $('#ailg-broken-wrap').html('<div class="ailg-empty"><div class="ailg-empty-icon">✅</div><p>No posts found to scan.</p></div>');
                btn.html('🔍 Start Broken Link Scan').prop('disabled',false);
                return;
            }

            var ids = r.data.ids;
            var total = ids.length;
            var processed = 0;
            var allBroken = [];

            function scanNext(){
                if(ids.length === 0){
                    btn.html('🔍 Start Broken Link Scan').prop('disabled',false);
                    renderBrokenResults(allBroken, total);
                    return;
                }

                var currentId = ids.shift();
                processed++;
                btn.html('<span class="ailg-spinner"></span> Scanning ' + processed + '/' + total + '…');

                $.post(AILG.ajax_url, {
                    action: 'ailg_scan_broken_single',
                    nonce: AILG.nonce,
                    post_id: currentId
                }, function(res){
                    if(res.success && res.data.links && res.data.links.length){
                        allBroken = allBroken.concat(res.data.links);
                    }
                    if(allBroken.some(function(l){ return l.http_code >= 300 && l.http_code < 400; })){
                        $('#ailg-groom-redirects-btn').show();
                    }
                    scanNext();
                }).fail(function(){
                    scanNext();
                });
            }

            scanNext();

        }).fail(function(){
            btn.html('🔍 Start Broken Link Scan').prop('disabled',false);
            showNotice('Scan initialization failed.', 'error');
        });
    });

    $(document).on('click','#ailg-groom-redirects-btn',function(){
        var btn = $(this);
        btn.html('<span class="ailg-spinner"></span> Grooming…').prop('disabled',true);
        $.post(AILG.ajax_url, {action:'ailg_groom_redirects', nonce:AILG.nonce}, function(r){
            btn.html('🧹 Groom All Redirects').prop('disabled',false);
            if(r.success){
                showNotice(r.data.message, 'success');
                setTimeout(function(){ location.reload(); }, 1500);
            } else {
                showNotice(r.data || 'Grooming failed', 'error');
            }
        });
    });
}

function renderBrokenResults(links, checkedCount){
    if(!links || !links.length){
        $('#ailg-broken-wrap').html('<div class="ailg-empty"><div class="ailg-empty-icon">✅</div><p>No broken links found across '+checkedCount+' posts!</p></div>');
        return;
    }
    var html = '<div class="ailg-alert ailg-alert-warning">Found <strong>'+links.length+'</strong> problematic links across '+checkedCount+' posts.</div>';
    html += '<div class="ailg-table-wrap"><table class="ailg-table"><thead><tr><th>Source Post</th><th>URL</th><th>Code</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
    $.each(links, function(i, l){
        var code = parseInt(l.http_code)||0;
        var cls  = code>=400?'ailg-tag-red':(code>=300?'ailg-tag-yellow':'ailg-tag-red');
        var lbl  = code>=400?'Broken':(code>=300?'Redirect':'Error');
        html += '<tr>';
        html += '<td><a href="'+escHtml(l.edit_url||'')+'" style="color:var(--ailg-primary);font-weight:600;text-decoration:none">'+escHtml(l.post_title)+'</a></td>';
        html += '<td><span style="font-family:monospace;font-size:12px;color:var(--ailg-text-mid);word-break:break-all">'+escHtml(l.url)+'</span></td>';
        html += '<td><strong>'+escHtml(String(code||'–'))+'</strong></td>';
        html += '<td><span class="ailg-tag '+cls+'">'+lbl+'</span></td>';
        html += '<td><div style="display:flex;gap:5px">';
        html += '<button class="ailg-btn ailg-btn-danger ailg-btn-xs ailg-unlink-btn" data-post="'+escHtml(String(l.post_id))+'" data-url="'+escHtml(l.url)+'">Unlink</button>';
        html += '<a href="'+escHtml(l.edit_url||'')+'" class="ailg-btn ailg-btn-secondary ailg-btn-xs">Edit</a>';
        html += '</div></td>';
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    $('#ailg-broken-wrap').html(html);
}

$(document).on('click', '.ailg-unlink-btn', function(){
    var btn = $(this);
    var postId = btn.data('post');
    var url = btn.data('url');
    if(!confirm('This will remove the link but keep the anchor text. Continue?')) return;

    btn.html('<span class="ailg-spinner"></span>').prop('disabled', true);
    $.post(AILG.ajax_url, {
        action: 'ailg_unlink_broken',
        nonce: AILG.nonce,
        post_id: postId,
        url: url
    }, function(r){
        if(r.success){
            showNotice(r.data.message, 'success');
            btn.closest('tr').fadeOut(300, function(){ $(this).remove(); });
        } else {
            btn.html('Unlink').prop('disabled', false);
            showNotice(r.data || 'Failed to unlink', 'error');
        }
    });
});

/* ─── Automation Rules ───────────────────────────────────────── */
function initAutomation(){
    $(document).on('click','#ailg-add-rule-btn',function(){
        var form = $('#ailg-rule-form');
        form[0].reset();
        form.find('[name="rule_id"]').val('');
        form.find('.ailg-range-val').each(function(){
            var input = $(this).closest('.ailg-range-wrap').find('input');
            $(this).text(input.val());
        });
        $('#ailg-rule-modal h2').text('⚡ Create Automation Rule');
        $('#ailg-modal-overlay,#ailg-rule-modal').fadeIn(200);
    });

    $(document).on('click','.ailg-edit-rule-btn',function(){
        var card = $(this).closest('.ailg-rule-card');
        var form = $('#ailg-rule-form');

        form.find('[name="rule_id"]').val(card.data('id'));
        form.find('[name="rule_name"]').val(card.data('name'));

        var trigger = card.data('trigger');
        if(form.find('[name="trigger_type"] option[value="'+trigger+'"]').length){
            form.find('[name="trigger_type"]').val(trigger);
        }

        var provider = card.data('provider');
        if(form.find('[name="ai_provider"] option[value="'+provider+'"]').length){
            form.find('[name="ai_provider"]').val(provider);
        }

        form.find('[name="max_links"]').val(card.data('max-links'));

        var score = parseFloat(card.data('min-score'));
        if(!isNaN(score)) form.find('[name="min_score"]').val(score);

        form.find('[name="anchor_mode"]').val(card.data('anchor-mode'));
        form.find('[name="auto_insert"]').prop('checked', card.data('auto-insert') == 1);
        form.find('[name="is_active"]').prop('checked', card.data('active') == 1);

        // Post types
        var pts = card.data('post-types');
        form.find('[name="post_types[]"]').prop('checked', false);
        if(Array.isArray(pts)){
            pts.forEach(function(pt){
                form.find('[name="post_types[]"][value="'+pt+'"]').prop('checked', true);
            });
        }

        // Update range labels
        form.find('.ailg-range-val').each(function(){
            var input = $(this).closest('.ailg-range-wrap').find('input');
            $(this).text(input.val());
        });

        $('#ailg-rule-modal h2').text('⚡ Edit Automation Rule');
        $('#ailg-modal-overlay,#ailg-rule-modal').fadeIn(200);
    });
    $(document).on('click','.ailg-modal-close',function(){
        $('#ailg-modal-overlay,#ailg-rule-modal').fadeOut(200);
    });
    $(document).on('click','#ailg-modal-overlay',function(){
        $('#ailg-modal-overlay,#ailg-rule-modal').fadeOut(200);
    });
    $(document).on('submit','#ailg-rule-form',function(e){
        e.preventDefault();
        var formData = $(this).serializeArray();
        formData.push({name:'action',value:'ailg_save_rule'},{name:'nonce',value:AILG.nonce});
        $.post(AILG.ajax_url, formData, function(r){
            if(r.success){
                showNotice('✓ Rule saved!','success');
                $('#ailg-modal-overlay,#ailg-rule-modal').fadeOut(200);
                setTimeout(function(){ location.reload(); }, 800);
            } else { showNotice(r.data||'Save failed','error'); }
        });
    });
    $(document).on('click','.ailg-run-rule-btn',function(){
        var btn = $(this);
        var id = btn.data('id');
        var origText = btn.html();

        btn.html('<span class="ailg-spinner"></span> Initializing…').prop('disabled',true);

        $.post(AILG.ajax_url, {
            action:'ailg_get_automation_queue',
            nonce:AILG.nonce,
            rule_id:id
        }, function(r){
            if(!r.success || !r.data.ids || !r.data.ids.length){
                showNotice(r.data || 'No posts found to process for this rule.', 'info');
                btn.html(origText).prop('disabled',false);
                return;
            }

            var ids = r.data.ids;
            var total = ids.length;
            var processed = 0;
            var totalApplied = 0;

            function processNext(){
                if(ids.length === 0){
                    btn.html('✓ Finished').prop('disabled',false);
                    showNotice('✓ Automation rule complete! Applied ' + totalApplied + ' links.', 'success');
                    setTimeout(function(){ btn.html(origText); }, 3000);
                    return;
                }

                var currentId = ids.shift();
                processed++;
                btn.html('<span class="ailg-spinner"></span> ' + processed + '/' + total + '…');

                $.post(AILG.ajax_url, {
                    action: 'ailg_run_automation_single',
                    nonce: AILG.nonce,
                    rule_id: id,
                    post_id: currentId
                }, function(res){
                    if(res.success){
                        totalApplied += (res.data.applied || 0);
                    }
                    processNext();
                }).fail(function(){
                    processNext();
                });
            }

            processNext();

        }).fail(function(){
            btn.html(origText).prop('disabled',false);
            showNotice('Failed to start automation.','error');
        });
    });
    $(document).on('click','.ailg-delete-rule-btn',function(){
        if(!confirm('Delete this automation rule? This cannot be undone.')) return;
        var btn = $(this); var id = btn.data('id');
        $.post(AILG.ajax_url,{action:'ailg_delete_rule',nonce:AILG.nonce,rule_id:id},function(r){
            if(r.success){ showNotice('Rule deleted.','info'); btn.closest('.ailg-rule-card').fadeOut(300,function(){ $(this).remove(); }); }
        });
    });

    $(document).on('click','.ailg-install-template-btn',function(){
        var btn = $(this);
        var template = btn.data('template');
        var orig = btn.html();
        btn.html('<span class="ailg-spinner"></span> Installing…').prop('disabled',true);
        $.post(AILG.ajax_url,{
            action:'ailg_install_template', nonce:AILG.nonce, template:template
        },function(r){
            if(r.success){
                showNotice('✓ '+r.data.message,'success');
                setTimeout(function(){ location.reload(); }, 800);
            } else {
                btn.html(orig).prop('disabled',false);
                showNotice(r.data||'Installation failed','error');
            }
        });
    });
}

/* ─── Bulk Scan ──────────────────────────────────────────────── */
function initBulkScan(){
    $(document).on('click','.ailg-bulk-scan-btn, #ailg-bulk-scan-btn',function(){
        var btn = $(this);
        var origText = btn.data('orig-text') || '🔍 Bulk Scan All Posts';
        btn.html('<span class="ailg-spinner"></span> Initializing…').prop('disabled',true);
        $('#ailg-bulk-progress').slideDown(200);
        $('#ailg-bulk-bar').css('width','5%');

        $.post(AILG.ajax_url, {action:'ailg_get_scan_queue', nonce:AILG.nonce}, function(r){
            if(!r.success || !r.data.ids || !r.data.ids.length){
                showNotice(r.data || 'No posts found to scan.', 'info');
                btn.html(origText).prop('disabled',false);
                $('#ailg-bulk-progress').slideUp(300);
                return;
            }

            var ids = r.data.ids;
            var total = ids.length;
            var processed = 0;

            function scanNext(){
                if(ids.length === 0){
                    $('#ailg-bulk-bar').css('width','100%');
                    setTimeout(function(){
                        $('#ailg-bulk-progress').slideUp(300);
                        btn.html(origText).prop('disabled',false);
                        showNotice('✓ Bulk scan complete!', 'success');
                        // Refresh suggestions if we are on that page
                        if($('.ailg-suggestion').length || $('.ailg-empty').length) {
                             setTimeout(function(){ location.reload(); }, 1000);
                        }
                    }, 1000);
                    return;
                }

                var currentId = ids.shift();
                processed++;
                var pct = Math.round((processed / total) * 100);
                $('#ailg-bulk-bar').css('width', pct + '%');
                btn.html('<span class="ailg-spinner"></span> Scanning ' + processed + '/' + total + '…');

                $.post(AILG.ajax_url, {
                    action: 'ailg_scan_single_item',
                    nonce: AILG.nonce,
                    post_id: currentId
                }, function(){
                    scanNext();
                }).fail(function(){
                    // If one fails, try to continue with the next
                    scanNext();
                });
            }

            scanNext();

        }).fail(function(){
            btn.html(origText).prop('disabled',false);
            $('#ailg-bulk-progress').slideUp(300);
            showNotice('Initialization failed.', 'error');
        });
    });
    // Store original text on init
    $('#ailg-bulk-scan-btn').each(function(){ $(this).data('orig-text',$(this).html()); });
}

/* ─── Save Settings ──────────────────────────────────────────── */
function initSettings(){
    $(document).on('click','#ailg-swap-btn',function(){
        var oldUrl = $('#ailg-swap-old').val();
        var newUrl = $('#ailg-swap-new').val();
        if(!oldUrl || !newUrl) { showNotice('Both URLs are required.','warning'); return; }
        if(!confirm('This will update all content site-wide. This action is permanent. Continue?')) return;

        var btn = $(this);
        btn.html('<span class="ailg-spinner"></span> Swapping…').prop('disabled',true);
        $.post(AILG.ajax_url, {
            action:'ailg_global_swap', nonce:AILG.nonce, old_url:oldUrl, new_url:newUrl
        }, function(r){
            btn.html('🚀 Execute Global Swap').prop('disabled',false);
            if(r.success) showNotice(r.data.message, 'success');
            else showNotice(r.data||'Swap failed', 'error');
        });
    });

    $(document).on('submit','#ailg-settings-form',function(e){
        e.preventDefault();
        var btn  = $(this).find('[type=submit]');
        var orig = btn.html();
        btn.html('<span class="ailg-spinner"></span> Saving…').prop('disabled',true);
        var formData = $(this).serializeArray();
        formData.push({name:'action',value:'ailg_save_settings'},{name:'nonce',value:AILG.nonce});
        $.post(AILG.ajax_url, formData, function(r){
            btn.html(orig).prop('disabled',false);
            if(r.success) showNotice('✓ '+r.data.message,'success');
            else showNotice(r.data||'Save failed','error');
        }).fail(function(){
            btn.html(orig).prop('disabled',false);
            showNotice('Save request failed.','error');
        });
    });
}

/* ─── Link Map ───────────────────────────────────────────────── */
function initLinkMap(){
    var container = document.getElementById('ailg-link-graph');
    if(!container) return;

    container.innerHTML = '<div style="padding:100px;text-align:center"><span class="ailg-spinner" style="width:40px;height:40px"></span><p>Building your internal link network map…</p></div>';

    $.post(AILG.ajax_url, {action:'ailg_get_link_graph', nonce:AILG.nonce}, function(r){
        if(!r.success || !r.data.nodes || !r.data.nodes.length){
            container.innerHTML = '<div class="ailg-empty"><p>No internal links recorded yet. Generate some suggestions first!</p></div>';
            return;
        }

        var canvas = document.createElement('canvas');
        canvas.width  = container.offsetWidth || 800;
        canvas.height = 500;
        canvas.style.cssText = 'width:100%;height:100%;border-radius:12px;';
        container.innerHTML = '';
        container.appendChild(canvas);

        try {
            drawLinkGraph(canvas.getContext('2d'), canvas.width, canvas.height, r.data.nodes, r.data.edges);
        } catch(e){
            console.error(e);
        }
    });
}

function drawLinkGraph(ctx, w, h, nodes, edges){
    ctx.fillStyle = '#f7f8fc';
    ctx.fillRect(0,0,w,h);

    // Simple force-directed-ish layout (static for now, but dynamic nodes)
    var nodeMap = {};
    nodes.forEach(function(n, i){
        var angle = (i / nodes.length) * Math.PI * 2;
        var dist  = Math.min(w, h) * 0.4;
        n.x = w/2 + Math.cos(angle) * dist * (0.5 + Math.random() * 0.5);
        n.y = h/2 + Math.sin(angle) * dist * (0.5 + Math.random() * 0.5);
        n.r = 8 + (edges.filter(e => e.target === n.id).length * 2); // Radius based on inbound links
        n.color = n.r > 15 ? '#4f46e5' : (n.r > 10 ? '#0891b2' : '#7c3aed');
        nodeMap[n.id] = n;
    });

    edges.forEach(function(e){
        var a=nodeMap[e.source], b=nodeMap[e.target];
        if(!a || !b) return;
        var grd = ctx.createLinearGradient(a.x,a.y,b.x,b.y);
        grd.addColorStop(0, a.color+'55'); grd.addColorStop(1, b.color+'33');
        ctx.beginPath(); ctx.moveTo(a.x,a.y); ctx.lineTo(b.x,b.y);
        ctx.strokeStyle=grd; ctx.lineWidth=1.2; ctx.stroke();
    });

    nodes.forEach(function(n){
        // Soft glow
        var rg = ctx.createRadialGradient(n.x,n.y,0,n.x,n.y,n.r*2.5);
        rg.addColorStop(0,n.color+'22'); rg.addColorStop(1,'transparent');
        ctx.beginPath(); ctx.arc(n.x,n.y,n.r*2.5,0,Math.PI*2);
        ctx.fillStyle=rg; ctx.fill();
        // Circle
        ctx.beginPath(); ctx.arc(n.x,n.y,n.r,0,Math.PI*2);
        ctx.fillStyle=n.color; ctx.fill();
        ctx.strokeStyle='rgba(255,255,255,0.8)'; ctx.lineWidth=1.5; ctx.stroke();

        // Label only for larger nodes
        if(n.r > 12) {
            ctx.fillStyle='#1e2235'; ctx.font='bold 10px Verdana,sans-serif';
            ctx.textAlign='center'; ctx.textBaseline='top';
            var lbl = n.label.length > 20 ? n.label.substring(0,17)+'…' : n.label;
            ctx.fillText(lbl, n.x, n.y+n.r+3);
        }
    });

    ctx.fillStyle='rgba(79,70,229,.7)'; ctx.font='bold 13px Verdana,sans-serif';
    ctx.textAlign='left'; ctx.textBaseline='top';
    ctx.fillText('Live Internal Link Network ('+nodes.length+' nodes)', 16, 14);
}

/* ─── EEAT Authority ─────────────────────────────────────────── */
function initEEAT(){
    $(document).on('click','#ailg-eeat-analyze-btn',function(){
        var btn = $(this);
        var postId = $('#ailg-eeat-post-select').val();
        var res = $('#ailg-eeat-results');

        if(!postId){ showNotice('Please select a post first.','warning'); return; }

        btn.html('<span class="ailg-spinner"></span> Analyzing EEAT…').prop('disabled',true);
        res.html('<div style="padding:48px;text-align:center"><span class="ailg-spinner" style="width:32px;height:32px;border-width:3px"></span><p style="color:var(--ailg-text-dim);margin-top:14px">Scanning for authority gaps and finding reputable citations…</p></div>');

        $.post(AILG.ajax_url,{
            action:'ailg_get_eeat_suggestions', nonce:AILG.nonce, post_id:postId
        },function(r){
            btn.html('🚀 Analyze for EEAT').prop('disabled',false);
            if(r.success && r.data.suggestions && r.data.suggestions.length){
                var html = '<div class="ailg-alert ailg-alert-success">✨ Found <strong>'+r.data.suggestions.length+'</strong> authority-boosting external citations</div>';
                $.each(r.data.suggestions,function(i,s){
                    html += '<div class="ailg-suggestion ailg-eeat-item">';
                    html += '<div class="ailg-suggestion-header">';
                    html += '<div class="ailg-suggestion-meta" style="flex:1">';
                    html += '<div class="ailg-suggestion-title">Source: <a href="'+escHtml(s.url)+'" target="_blank" style="color:var(--ailg-primary)">'+escHtml(s.url)+'</a></div>';
                    html += '<div class="ailg-suggestion-anchor">Anchor: <strong>&ldquo;'+escHtml(s.anchor_text)+'&rdquo;</strong></div>';
                    html += '</div>';
                    html += '<button class="ailg-btn ailg-btn-success ailg-btn-sm ailg-eeat-insert-btn" data-post="'+postId+'" data-url="'+escHtml(s.url)+'" data-anchor="'+escHtml(s.anchor_text)+'">✓ Insert Link</button>';
                    html += '</div>';
                    if(s.reason) html += '<div class="ailg-suggestion-context" style="background:rgba(79,70,229,0.05);border-left-color:var(--ailg-primary)"><strong>EEAT Boost:</strong> '+escHtml(s.reason)+'</div>';
                    html += '</div>';
                });
                res.html(html);
            } else {
                res.html('<div class="ailg-empty"><p>No authority gaps identified for this content.</p></div>');
            }
        });
    });

    $(document).on('click','.ailg-eeat-insert-btn',function(){
        var btn = $(this);
        var data = {
            action: 'ailg_insert_external_link',
            nonce: AILG.nonce,
            post_id: btn.data('post'),
            url: btn.data('url'),
            anchor: btn.data('anchor')
        };
        btn.html('<span class="ailg-spinner"></span>').prop('disabled',true);
        $.post(AILG.ajax_url, data, function(r){
            if(r.success){
                btn.closest('.ailg-suggestion').fadeOut(300);
                showNotice('✓ Authority citation inserted!','success');
            } else {
                btn.html('✓ Insert Link').prop('disabled',false);
                showNotice(r.data||'Failed to insert','error');
            }
        });
    });
}

/* ─── Logs & Revisions ─────────────────────────────────────────────── */
function initLogs(){
    if(!$('#ailg-logs-wrap').length) return;

    function loadLogs(){
        $.post(AILG.ajax_url, {action:'ailg_get_logs', nonce:AILG.nonce}, function(r){
            if(!r.success || !r.data.logs || !r.data.logs.length){
                $('#ailg-logs-wrap').html('<div class="ailg-empty"><p>No activity logged yet.</p></div>');
                return;
            }
            var html = '<div class="ailg-table-wrap" style="border:none;border-radius:0"><table class="ailg-table">';
            html += '<thead><tr><th>Time</th><th>Level</th><th>Component</th><th>Message</th></tr></thead><tbody>';
            $.each(r.data.logs, function(i, l){
                var cls = 'ailg-tag-' + (l.level === 'error' ? 'red' : (l.level === 'warning' ? 'yellow' : 'purple'));
                html += '<tr>';
                html += '<td style="white-space:nowrap;font-size:11px;color:var(--ailg-text-dim)">'+escHtml(l.created_at)+'</td>';
                html += '<td><span class="ailg-tag '+cls+'">'+escHtml(l.level.toUpperCase())+'</span></td>';
                html += '<td><span class="ailg-tag ailg-tag-blue" style="text-transform:uppercase;font-size:10px">'+escHtml(l.component)+'</span></td>';
                html += '<td style="font-family:Verdana,sans-serif;line-height:1.4"><strong>'+escHtml(l.message)+'</strong>';
                if(l.data) html += '<pre style="font-size:10px;margin-top:5px;background:#f8f9fa;padding:5px;border-radius:4px;overflow:auto;max-width:600px">'+escHtml(l.data)+'</pre>';
                html += '</td></tr>';
            });
            html += '</tbody></table></div>';
            $('#ailg-logs-wrap').html(html);
        });
    }

    function loadRevisions(){
        if(!$('#ailg-revisions-wrap').length) return;
        $.post(AILG.ajax_url, {action:'ailg_get_revisions', nonce:AILG.nonce}, function(r){
            if(!r.success || !r.data.revisions || !r.data.revisions.length){
                $('#ailg-revisions-wrap').html('<div class="ailg-empty"><p>No revisions recorded yet. Snapshots are created automatically before content edits.</p></div>');
                return;
            }
            var html = '<div class="ailg-table-wrap" style="border:none;border-radius:0"><table class="ailg-table">';
            html += '<thead><tr><th>Time</th><th>Post</th><th>Reason</th><th>Batch</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
            $.each(r.data.revisions, function(i, rev){
                var statusBadge = rev.status === 'restored' 
                    ? '<span class="ailg-tag ailg-tag-yellow">RESTORED</span>'
                    : '<span class="ailg-tag ailg-tag-green">ACTIVE</span>';
                var title = rev.post_title ? escHtml(rev.post_title) : ('Post #' + rev.post_id);
                var adminBase = (typeof AILG.admin_url !== 'undefined' && AILG.admin_url) ? AILG.admin_url : 'admin.php';
                var editUrl = (adminBase.indexOf('admin.php') !== -1) ? (adminBase.replace('admin.php', 'post.php') + '?post=' + rev.post_id + '&action=edit') : (adminBase + 'post.php?post=' + rev.post_id + '&action=edit');
                var batchInfo = (rev.batch_id && rev.batch_id !== '') ? ('<span class="ailg-tag ailg-tag-blue" title="Batch: ' + escHtml(rev.batch_id) + '">Bulk (' + (rev.batch_size || 1) + ')</span>') : '<span style="color:var(--ailg-text-dim)">Single</span>';

                html += '<tr>';
                html += '<td style="white-space:nowrap;font-size:11px;color:var(--ailg-text-dim)">' + escHtml(rev.created_at) + '</td>';
                html += '<td><strong><a href="' + editUrl + '" target="_blank">' + title + '</a></strong></td>';
                html += '<td style="font-size:12px;color:var(--ailg-text-dim)">' + escHtml(rev.reason || 'Content update') + '</td>';
                html += '<td>' + batchInfo + '</td>';
                html += '<td>' + statusBadge + '</td>';
                html += '<td><div style="display:flex;gap:6px">';
                if(rev.status !== 'restored'){
                    html += '<button type="button" class="ailg-btn ailg-btn-sm ailg-btn-secondary ailg-restore-revision-btn" data-id="' + rev.id + '">↩ Undo</button>';
                    if(rev.batch_id && parseInt(rev.batch_size, 10) > 1){
                        html += '<button type="button" class="ailg-btn ailg-btn-sm ailg-btn-danger ailg-restore-batch-btn" data-batch="' + escHtml(rev.batch_id) + '" title="Undo all changes in this batch">↩ Undo Batch</button>';
                    }
                } else {
                    html += '<span style="font-size:11px;color:var(--ailg-text-dim)">Restored</span>';
                }
                html += '</div></td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            $('#ailg-revisions-wrap').html(html);
        });
    }

    loadLogs();

    $(document).on('click', '.ailg-log-tab-btn', function(){
        var tab = $(this).data('tab');
        $('.ailg-log-tab-btn').removeClass('ailg-btn-primary').addClass('ailg-btn-secondary');
        $(this).removeClass('ailg-btn-secondary').addClass('ailg-btn-primary');
        $('.ailg-tab-content').hide();
        $('#ailg-tab-' + tab).show();
        if(tab === 'revisions') {
            loadRevisions();
        } else {
            loadLogs();
        }
    });

    $(document).on('click', '#ailg-refresh-revisions-btn', function(){
        loadRevisions();
    });

    $(document).on('click', '.ailg-restore-revision-btn', function(){
        var btn = $(this);
        var id = btn.data('id');
        if(!confirm('Restore this post to its state before this modification?')) return;
        btn.prop('disabled', true).text('Restoring…');
        $.post(AILG.ajax_url, {action:'ailg_restore_revision', nonce:AILG.nonce, id: id}, function(r){
            if(r.success){
                showNotice(r.data.message || 'Revision restored successfully.', 'success');
                loadRevisions();
            } else {
                btn.prop('disabled', false).text('↩ Undo');
                showNotice((r.data && r.data.message) ? r.data.message : 'Failed to restore revision.', 'error');
            }
        }).fail(function(){
            btn.prop('disabled', false).text('↩ Undo');
            showNotice('Request failed.', 'error');
        });
    });

    $(document).on('click', '.ailg-restore-batch-btn', function(){
        var btn = $(this);
        var batchId = btn.data('batch');
        if(!confirm('Undo ALL changes in this bulk batch? This will restore each affected post to its prior content.')) return;
        btn.prop('disabled', true).text('Restoring…');
        $.post(AILG.ajax_url, {action:'ailg_restore_batch', nonce:AILG.nonce, batch_id: batchId}, function(r){
            if(r.success){
                showNotice(r.data.message || 'Batch restored.', 'success');
                loadRevisions();
            } else {
                btn.prop('disabled', false).text('↩ Undo Batch');
                showNotice((r.data && r.data.message) ? r.data.message : 'Failed to restore batch.', 'error');
            }
        }).fail(function(){
            btn.prop('disabled', false).text('↩ Undo Batch');
            showNotice('Request failed.', 'error');
        });
    });

    $(document).on('click','#ailg-clear-logs-btn',function(){
        if(!confirm('Clear all system logs?')) return;
        $.post(AILG.ajax_url, {action:'ailg_clear_logs', nonce:AILG.nonce}, function(){
            loadLogs();
            showNotice('Logs cleared.','info');
        });
    });
}

/* ─── Striking Distance ───────────────────────────────────────────── */
function initStrikingDistance(){
    if(!$('#ailg-striking-wrap').length) return;

    function loadStriking(){
        $.post(AILG.ajax_url, {action:'ailg_get_striking_distance', nonce:AILG.nonce}, function(r){
            if(!r.success || !r.data || !r.data.items || !r.data.items.length){
                var hint = r.data && r.data.active 
                    ? 'No queries currently in positions 4–12. Striking-distance opportunities will appear here as your rankings update.'
                    : 'VM SEO Brain is not active. Connect VM SEO Brain to automatically target high-priority striking-distance terms.';
                $('#ailg-striking-wrap').html('<div class="ailg-empty"><div class="ailg-empty-icon">🎯</div><p>' + hint + '</p></div>');
                return;
            }

            var html = '<div class="ailg-table-wrap" style="border:none;border-radius:0"><table class="ailg-table">';
            html += '<thead><tr><th>Target Keyword</th><th>Rank Position</th><th>Target Post</th><th>Silo / Cluster</th><th>Action</th></tr></thead><tbody>';
            $.each(r.data.items, function(i, item){
                html += '<tr>';
                html += '<td><strong>&ldquo;' + escHtml(item.keyword) + '&rdquo;</strong></td>';
                html += '<td><span class="ailg-tag ailg-tag-yellow" style="font-weight:700">#' + escHtml(item.position) + '</span></td>';
                html += '<td><a href="' + escUrl(item.url) + '" target="_blank" rel="noopener" style="font-weight:600">' + escHtml(item.title) + '</a></td>';
                html += '<td><span class="ailg-tag ailg-tag-blue">' + escHtml(item.cluster) + '</span></td>';
                html += '<td><button type="button" class="ailg-btn ailg-btn-sm ailg-btn-primary ailg-boost-striking-btn" data-post="' + item.post_id + '" data-kw="' + escHtml(item.keyword) + '">🚀 Boost with Links</button></td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            $('#ailg-striking-wrap').html(html);
        });
    }

    loadStriking();

    $(document).on('click', '#ailg-refresh-striking-btn', function(){
        $('#ailg-striking-wrap').html('<div style="text-align:center;padding:60px"><span class="ailg-spinner" style="width:32px;height:32px;border-width:3px"></span><p style="color:var(--ailg-text-dim);margin-top:14px">Refreshing striking-distance data…</p></div>');
        loadStriking();
    });

    $(document).on('click', '.ailg-boost-striking-btn', function(){
        var btn = $(this);
        var pid = btn.data('post');
        var kw = btn.data('kw');
        var orig = btn.html();
        btn.html('<span class="ailg-spinner"></span> Finding Opportunities…').prop('disabled', true);

        $.post(AILG.ajax_url, {action:'ailg_fix_orphaned', nonce:AILG.nonce, post_id: pid}, function(r){
            if(r.success && r.data.ids && r.data.ids.length > 0) {
                processReinforcementQueue(r.data.ids, btn, orig, '✓ Inbound links generated to boost "' + kw + '"!', pid);
            } else {
                btn.html(orig).prop('disabled', false);
                showNotice('No related candidate posts found to link to this post.', 'info');
            }
        }).fail(function(){
            btn.html(orig).prop('disabled', false);
            showNotice('Failed to generate boost suggestions.', 'error');
        });
    });
}

/* ─── Cannibalization Radar ───────────────────────────────────────── */
function initCannibalization(){
    if(!$('#ailg-cannibalization-wrap').length) return;

    function loadCannibalization(){
        $.post(AILG.ajax_url, {action:'ailg_get_cannibalization', nonce:AILG.nonce}, function(r){
            if(!r.success || !r.data || !r.data.cannibalization || !r.data.cannibalization.length){
                $('#ailg-cannibalization-wrap').html('<div class="ailg-empty"><div class="ailg-empty-icon">🛡️</div><p>No anchor text cannibalization detected. Your internal links have distinct, healthy target distributions.</p></div>');
                return;
            }

            var html = '<div class="ailg-table-wrap" style="border:none;border-radius:0"><table class="ailg-table">';
            html += '<thead><tr><th>Conflicted Anchor Text</th><th>Competing URLs</th><th>Total Links</th><th>Recommendation</th></tr></thead><tbody>';
            $.each(r.data.cannibalization, function(i, item){
                html += '<tr>';
                html += '<td><strong style="color:var(--ailg-accent)">&ldquo;' + escHtml(item.anchor) + '&rdquo;</strong></td>';
                html += '<td><ul style="margin:0;padding-left:16px;font-size:12px;line-height:1.6">';
                $.each(item.targets, function(j, t){
                    html += '<li><a href="' + escUrl(t.url) + '" target="_blank" rel="noopener">' + escHtml(t.title) + '</a></li>';
                });
                html += '</ul></td>';
                html += '<td><span class="ailg-tag ailg-tag-yellow">' + item.total_links + ' link(s) across ' + item.target_count + ' URLs</span></td>';
                html += '<td style="font-size:12px;color:var(--ailg-text-dim)">Vary your anchor text or designate one primary target to avoid Google keyword cannibalization.</td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            $('#ailg-cannibalization-wrap').html(html);
        });
    }

    loadCannibalization();

    $(document).on('click', '#ailg-refresh-cannibalization-btn', function(){
        $('#ailg-cannibalization-wrap').html('<div style="text-align:center;padding:60px"><span class="ailg-spinner" style="width:28px;height:28px;border-width:3px"></span><p style="color:var(--ailg-text-dim);margin-top:14px">Scanning anchor text distribution…</p></div>');
        loadCannibalization();
    });
}

/* ─── GitHub Updates ─────────────────────────────────────────── */
function initGitHubUpdates(){
    if ( window.location.hash === '#ailg-section-updates' || window.location.hash === '#ailg-tab-updates' ) {
        $('[data-target="ailg-tab-updates"]').trigger('click');
    }

    $('#ailg-check-updates-btn').on('click', function(){
        var btn = $(this);
        btn.prop('disabled', true).text('Checking GitHub…');
        $('#ailg-update-status-badge').html('<span class="ailg-spinner" style="width:16px;height:16px;border-width:2px;display:inline-block;vertical-align:middle"></span> Checking…');

        $.post(AILG.ajax_url, {
            action: 'ailg_check_github_update',
            nonce: AILG.nonce
        }, function(res){
            btn.prop('disabled', false).text('⟳ Check for GitHub Updates');
            if (!res || !res.success) {
                var err = (res && res.data && res.data.message) ? res.data.message : 'Check failed';
                $('#ailg-update-status-badge').html('<span class="ailg-tag ailg-tag-red">Error: ' + escHtml(err) + '</span>');
                showNotice('Update check failed: ' + err, 'error');
                return;
            }

            var d = res.data;
            $('#ailg-update-checked-time').text('Last checked: ' + (d.last_checked || 'just now'));

            if (d.has_update) {
                $('#ailg-update-status-badge').html('<span class="ailg-tag ailg-tag-yellow" style="font-weight:700">UPDATE AVAILABLE: v' + escHtml(d.version) + '</span>');
                $('#ailg-new-ver-label').text('v' + d.version);
                $('#ailg-release-notes-wrap').html(d.release_notes ? escHtml(d.release_notes).replace(/\n/g, '<br>') : 'New release available from GitHub.');
                $('#ailg-update-available-box').slideDown(250);
                showNotice('New version v' + d.version + ' is available!', 'info');
            } else {
                $('#ailg-update-status-badge').html('<span class="ailg-tag ailg-tag-green">Up to Date (v' + escHtml(d.current) + ')</span>');
                $('#ailg-update-available-box').slideUp(200);
                showNotice('AI Link Genius Pro is up to date!', 'success');
            }
        }).fail(function(){
            btn.prop('disabled', false).text('⟳ Check for GitHub Updates');
            $('#ailg-update-status-badge').html('<span class="ailg-tag ailg-tag-red">Connection Failed</span>');
            showNotice('Connection to server failed.', 'error');
        });
    });

    $('#ailg-do-update-btn').on('click', function(){
        if (!confirm('Are you sure you want to update AI Link Genius Pro directly from GitHub?')) {
            return;
        }

        var btn = $(this);
        btn.prop('disabled', true).html('<span class="ailg-spinner" style="width:16px;height:16px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:6px"></span> Downloading & Installing from GitHub…');

        $.post(AILG.ajax_url, {
            action: 'ailg_perform_github_update',
            nonce: AILG.nonce
        }, function(res){
            if (res && res.success) {
                btn.html('✅ Successfully Updated! Reloading…');
                showNotice(res.data.message || 'Updated successfully!', 'success');
                setTimeout(function(){
                    window.location.reload();
                }, 2000);
            } else {
                var err = (res && res.data && res.data.message) ? res.data.message : 'Update failed';
                btn.prop('disabled', false).html('🚀 Try Update Again');
                showNotice('Update failed: ' + err, 'error');
            }
        }).fail(function(){
            btn.prop('disabled', false).html('🚀 Try Update Again');
            showNotice('Failed to communicate with update process.', 'error');
        });
    });
}

/* ─── Init ────────────────────────────────────────────────────── */
$(function(){
    initTabs();
    initRanges();
    initTestConnection();
    initSyncModels();
    initMetabox();
    initBulkScan();
    initBrokenCheck();
    initAutomation();
    initSettings();
    loadDashboardStats();
    loadReports();
    loadOrphaned();
    initLinkMap();
    initEEAT();
    initLogs();
    initStrikingDistance();
    initCannibalization();
    initGitHubUpdates();
});

})(jQuery);