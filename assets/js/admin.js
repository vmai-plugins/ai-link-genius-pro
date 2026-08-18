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

        btn.html('<span class="ailg-spinner"></span> Testing…').prop('disabled',true);
        $.post(AILG.ajax_url,{
            action:'ailg_test_connection',
            nonce:AILG.nonce,
            provider:provider,
            api_key: key,
            host: host
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

        btn.html('<span class="ailg-spinner"></span>').prop('disabled',true);
        $.post(AILG.ajax_url,{
            action:'ailg_sync_models',
            nonce:AILG.nonce,
            provider:provider,
            api_key: key
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
        html += '</div></td>';
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    $('#ailg-reports-wrap').html(html);
}

function processReinforcementQueue(ids, btn, origText, successMsg) {
    var total = ids.length;
    var processed = 0;

    function next() {
        if (ids.length === 0) {
            btn.html('✓ Done').prop('disabled', true);
            showNotice(successMsg, 'success');
            return;
        }

        var currentId = ids.shift();
        processed++;
        btn.html('<span class="ailg-spinner"></span> ' + processed + '/' + total);

        $.post(AILG.ajax_url, {
            action: 'ailg_scan_single_item',
            nonce: AILG.nonce,
            post_id: currentId
        }, function() {
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
            processReinforcementQueue(r.data.ids, btn, orig, '✓ Hub reinforced! Suggestions generated.');
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
        if(r.success) showNotice('✓ Suggestions generated!','success');
        else showNotice(r.data&&r.data.message||'No suggestions found.','info');
    });
});

/* ─── Orphaned Posts ─────────────────────────────────────────── */
function loadOrphaned(){
    if(!$('#ailg-orphaned-wrap').length) return;
    $.post(AILG.ajax_url,{action:'ailg_get_orphaned',nonce:AILG.nonce},function(r){
        if(!r.success){ $('#ailg-orphaned-wrap').html('<div class="ailg-alert ailg-alert-error">Failed to load orphaned posts.</div>'); return; }
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
            html += '<td><button class="ailg-btn ailg-btn-primary ailg-btn-xs ailg-fix-orphan" data-post="'+escHtml(String(p.ID))+'">🤖 Auto Fix</button></td></tr>';
        });
        html += '</tbody></table></div>';
        html += '<div style="margin-top:16px"><button class="ailg-btn ailg-btn-primary ailg-bulk-fix-orphan">🤖 Fix All Selected</button></div>';
        $('#ailg-orphaned-wrap').html(html);
    });
}

$(document).on('change','#ailg-check-all',function(){
    $('.ailg-orphan-check').prop('checked',$(this).is(':checked'));
});

$(document).on('click','.ailg-fix-orphan',function(){
    var btn = $(this); var pid = btn.data('post');
    var orig = btn.html();
    btn.html('<span class="ailg-spinner"></span> Initializing…').prop('disabled',true);

    $.post(AILG.ajax_url,{action:'ailg_fix_orphaned',nonce:AILG.nonce,post_id:pid},function(r){
        if(r.success && r.data.ids && r.data.ids.length > 0) {
            processReinforcementQueue(r.data.ids, btn, orig, '✓ Link opportunities generated for this post!');
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

    var allScanIds = [];

    function collectIds() {
        if (ids.length === 0) {
            if (allScanIds.length === 0) {
                btn.html(orig).prop('disabled', false);
                showNotice('No related posts found for the selected orphans.', 'info');
                return;
            }
            // Start processing the unique IDs
            processReinforcementQueue(Array.from(new Set(allScanIds)), btn, orig, '✓ Bulk fix complete! Suggestions generated.');
            return;
        }

        var currentOrphanId = ids.shift();
        $.post(AILG.ajax_url, {action:'ailg_fix_orphaned', nonce:AILG.nonce, post_id:currentOrphanId}, function(r){
            if(r.success && r.data.ids) {
                allScanIds = allScanIds.concat(r.data.ids);
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
        var btn = $(this); var id = btn.data('id');
        btn.html('<span class="ailg-spinner"></span> Running…').prop('disabled',true);
        $.post(AILG.ajax_url,{action:'ailg_run_automation',nonce:AILG.nonce,rule_id:id},function(r){
            btn.html('▶ Run Now').prop('disabled',false);
            if(r.success) showNotice('✓ Automation ran. Applied '+escHtml(String(r.data.applied))+' links across '+escHtml(String(r.data.posts_processed))+' posts.','success');
            else showNotice(r.data||'Run failed','error');
        }).fail(function(){
            btn.html('▶ Run Now').prop('disabled',false);
            showNotice('Run request failed.','error');
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
});

})(jQuery);