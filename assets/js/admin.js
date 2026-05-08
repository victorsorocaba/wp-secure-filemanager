jQuery(document).ready(function($) {
    var $elfinder = $('#wpsfm-elfinder');
    if (!$elfinder.length || typeof $elfinder.elfinder !== 'function') {
        return;
    }

    $elfinder.elfinder({
        url: wpsfm_vars.connector_url,
        requestType: 'post',
        customData: {
            _nonce: wpsfm_vars.nonce,
            blog_id: wpsfm_vars.blog_id
        },
        lang: wpsfm_vars.lang || 'en',
        uiOptions: {
            toolbar: [
                ['back', 'forward', 'up', 'reload'],
                ['mkdir', 'mkfile'],
                ['upload'],
                ['info'],
                ['quicklook'],
                ['copy', 'cut', 'paste'],
                ['rm'],
                ['search'],
                ['view', 'sort']
            ]
        },
        commands: [
            'open', 'reload', 'home', 'up', 'back', 'forward',
            'getfile', 'quicklook', 'download', 'rm',
            'duplicate', 'rename', 'mkdir', 'mkfile',
            'paste', 'upload', 'info', 'view', 'help', 'sort'
        ],
        commandsOptions: {
            edit: { disabled: true },
            chmod: { disabled: true },
            extract: { disabled: true },
            archive: { disabled: true }
        },
        bind: {
            'before:rm': function(e, fm) {
                e.preventDefault();
                var selected = fm.selected();
                if (selected.length === 0) {
                    return;
                }
                var names = '- ' + selected.map(function(handle) {
                    return fm.file(handle).name;
                }).join('\n- ');

                if (!confirm(
                    wpsfm_vars.i18n.confirm_delete + '\n\n' + names +
                    '\n\n' + wpsfm_vars.i18n.irreversible
                )) {
                    return;
                }

                $.post(wpsfm_vars.ajax_url, {
                    action: 'wpsfm_delete',
                    _nonce: wpsfm_vars.nonce,
                    targets: selected
                })
                .done(function(response) {
                    if (response.success) {
                        fm.remove({ removed: response.data.removed });
                        if (response.data.errors.length > 0) {
                            fm.error(response.data.errors);
                        }
                    } else {
                        fm.error(response.data.message || wpsfm_vars.i18n.server_error);
                    }
                })
                .fail(function() {
                    fm.error(wpsfm_vars.i18n.server_error);
                });
            },
            'before:upload': function(e, fm, data) {
                if (!data || !data.formData) {
                    return;
                }
                // Route uploads through WordPress AJAX; otherwise elFinder defaults apply.
                data.formData.append('_nonce', wpsfm_vars.nonce);
                data.formData.append('blog_id', wpsfm_vars.blog_id);
                data.formData.append('action', 'wpsfm_upload');
                data.url = wpsfm_vars.ajax_url;
            }
        }
    });
});
