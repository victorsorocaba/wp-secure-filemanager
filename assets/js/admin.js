jQuery(document).ready(function($) {
    var $elfinder = $('#elfinder');
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
        uiOptions: {
            toolbar: [
                ['back', 'forward'],
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
        bind: {
            'before:rm': function(e, fm) {
                e.preventDefault();
                var selected = fm.selected();
                if (selected.length === 0) {
                    return;
                }
                var names = selected.map(function(handle) {
                    return fm.file(handle).name;
                }).join('\n- ');

                if (!confirm('Excluir permanentemente?\n\n- ' + names + '\n\nEsta ação NÃO pode ser desfeita.')) {
                    return;
                }

                $.post(ajaxurl, {
                    action: 'wpsfm_delete',
                    _nonce: wpsfm_vars.nonce,
                    targets: selected
                }, function(response) {
                    if (response.success) {
                        fm.remove({ removed: response.data.removed });
                        if (response.data.errors.length > 0) {
                            fm.error(response.data.errors);
                        }
                    } else {
                        fm.error(response.data.message || 'Falha ao excluir.');
                    }
                });
            }
        }
    });
});
