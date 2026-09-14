( function( wp ) {
    var registerPlugin = wp.plugins.registerPlugin;
    var PluginSidebar = wp.editPost.PluginSidebar;
    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    var Button = wp.components.Button;
    var PanelBody = wp.components.PanelBody;
    var withSelect = wp.data.withSelect;
    var withDispatch = wp.data.withDispatch;
    var compose = wp.compose.compose;
    var select = wp.data.select;

    var AILGSidebar = function( props ) {
        var [ suggestions, setSuggestions ] = wp.element.useState( [] );
        var [ loading, setLoading ] = wp.element.useState( false );

        var getSuggestions = function() {
            setLoading( true );
            var content = select( 'core/editor' ).getEditedPostContent();
            var postId = select( 'core/editor' ).getCurrentPostId();

            jQuery.post( AILG.ajax_url, {
                action: 'ailg_get_suggestions',
                nonce: AILG.nonce,
                post_id: postId,
                content: content // Optional: could be used for real-time unsaved analysis
            }, function( r ) {
                setLoading( false );
                if ( r.success ) {
                    setSuggestions( r.data.suggestions );
                }
            } );
        };

        var insertLink = function( s ) {
            var applied = false;
            if ( s.is_bridge ) {
                var block = wp.blocks.createBlock( 'core/paragraph', {
                    content: '<a href="' + s.target_url + '" class="ailg-link" data-ailg="1">' + s.anchor_text + '</a>'
                } );
                wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
                applied = true;
            } else if ( s.is_image ) {
                alert( 'Image links must be applied via the main dashboard or metabox to ensure proper tag wrapping.' );
                return;
            } else {
                var selectedBlock = wp.data.select( 'core/block-editor' ).getSelectedBlock();
                if ( selectedBlock && selectedBlock.name === 'core/paragraph' ) {
                    var content = selectedBlock.attributes.content || '';
                    if ( content.indexOf( s.anchor_text ) !== -1 ) {
                        var newContent = content.replace( s.anchor_text, '<a href="' + s.target_url + '" class="ailg-link" data-ailg="1">' + s.anchor_text + '</a>' );
                        wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( selectedBlock.clientId, { content: newContent } );
                        applied = true;
                    }
                }
                if ( ! applied ) {
                    alert( 'Please select the paragraph containing "' + s.anchor_text + '" first, or apply this link via the AI Link Genius metabox below the editor.' );
                    return;
                }
            }

            if ( applied && s.id ) {
                jQuery.post( AILG.ajax_url, {
                    action: 'ailg_accept_suggestion',
                    nonce: AILG.nonce,
                    suggestion_id: s.id
                }, function( res ) {
                    if ( res && res.success ) {
                        setSuggestions( function( prev ) {
                            return prev.filter( function( item ) { return item.id !== s.id; } );
                        } );
                    }
                } );
            }
        };

        return el(
            PluginSidebar,
            {
                name: 'ailg-sidebar',
                icon: 'admin-links',
                title: 'AI Link Genius',
            },
            el(
                PanelBody,
                { title: 'Smart Link Suggestions', initialOpen: true },
                el(
                    'p',
                    {},
                    'Get AI-powered internal links for your current content.'
                ),
                el(
                    Button,
                    {
                        isPrimary: true,
                        onClick: getSuggestions,
                        isBusy: loading
                    },
                    loading ? 'Analyzing...' : 'Analyze Content'
                ),
                suggestions.length > 0 && el(
                    'div',
                    { style: { marginTop: '20px' } },
                    suggestions.map( function( s ) {
                        return el(
                            'div',
                            {
                                key: s.id,
                                style: {
                                    padding: '10px',
                                    borderBottom: '1px solid #eee',
                                    marginBottom: '10px'
                                }
                            },
                            el( 'strong', {}, s.target_title ),
                            el( 'p', { style: { fontSize: '12px', margin: '5px 0' } }, 'Anchor: "' + s.anchor_text + '"' ),
                            el(
                                Button,
                                {
                                    isSecondary: true,
                                    isSmall: true,
                                    onClick: function() { insertLink( s ); }
                                },
                                'Insert Link'
                            )
                        );
                    } )
                )
            )
        );
    };

    registerPlugin( 'ailg-sidebar', {
        render: AILGSidebar,
    } );
} )( window.wp );
