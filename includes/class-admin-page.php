<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSFM_Admin_Page {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_post_wpsfm_save_rule', [ $this, 'save_rule' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_menu() {
        add_menu_page(
            'WP Secure File Manager',
            'Secure Files',
            'manage_options',
            'wp-secure-fm',
            [ $this, 'render_main_page' ],
            'dashicons-portfolio',
            30
        );

        add_submenu_page(
            'wp-secure-fm',
            'Permissões de Pastas',
            'Permissões',
            'manage_options',
            'wpsfm-permissions',
            [ $this, 'render_permissions_page' ]
        );

        add_submenu_page(
            'wp-secure-fm',
            'Log de Auditoria',
            'Logs',
            'manage_options',
            'wpsfm-logs',
            [ $this, 'render_logs_page' ]
        );
    }

    public function render_main_page() {
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_die( __( 'Acesso negado.', 'wp-secure-fm' ) );
        }

        $template = WPSFM_PLUGIN_DIR . 'templates/admin-page.php';
        if ( file_exists( $template ) ) {
            require $template;
        }
    }

    public function render_permissions_page() {
        $folders  = WPSFM_File_Manager::get_folders();
        $blogs    = $this->get_blogs();
        $template = WPSFM_PLUGIN_DIR . 'templates/folder-permissions.php';

        if ( file_exists( $template ) ) {
            require $template;
        }
    }

    public function render_logs_page() {
        global $wpdb;

        $logs = $wpdb->get_results(
            "SELECT l.*, u.user_login
             FROM {$wpdb->prefix}wpsfm_audit_log l
             LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
             ORDER BY l.created_at DESC LIMIT 200"
        );

        echo '<div class="wrap"><h1>Log de Auditoria</h1>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Data/Hora</th><th>Usuário</th><th>Subsite</th>';
        echo '<th>Ação</th><th>Arquivo/Pasta</th><th>IP</th></tr></thead><tbody>';

        $icons = [
            'upload' => '⬆ Upload',
            'delete' => '🗑 Exclusão',
            'mkdir'  => '📁 Nova Pasta',
        ];

        foreach ( $logs as $log ) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( $log->created_at ),
                esc_html( $log->user_login ),
                (int) $log->blog_id,
                esc_html( $icons[ $log->action ] ?? $log->action ),
                esc_html( $log->item_name ),
                esc_html( $log->ip_address )
            );
        }

        echo '</tbody></table></div>';
    }

    public function save_rule() {
        check_admin_referer( 'wpsfm_save_rule' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Acesso negado' );
        }

        global $wpdb;

        $data = [
            'folder_id'  => absint( $_POST['folder_id'] ?? 0 ),
            'rule_type'  => sanitize_text_field( wp_unslash( $_POST['rule_type'] ?? '' ) ),
            'rule_value' => sanitize_text_field( wp_unslash( $_POST['rule_value'] ?? '' ) ),
            'blog_id'    => absint( $_POST['blog_id'] ?? 0 ),
            'can_read'   => isset( $_POST['can_read'] ) ? 1 : 0,
            'can_write'  => isset( $_POST['can_write'] ) ? 1 : 0,
            'can_delete' => isset( $_POST['can_delete'] ) ? 1 : 0,
        ];

        $wpdb->insert(
            $wpdb->prefix . 'wpsfm_access_rules',
            $data,
            [ '%d', '%s', '%s', '%d', '%d', '%d', '%d' ]
        );

        wp_redirect( admin_url( 'admin.php?page=wpsfm-permissions&saved=1' ) );
        exit;
    }

    public function enqueue_assets( $hook ) {
        $plugin_pages = [
            'toplevel_page_wp-secure-fm',
            'wp-secure-fm_page_wpsfm-permissions',
            'wp-secure-fm_page_wpsfm-logs',
        ];

        if ( ! in_array( $hook, $plugin_pages, true ) ) {
            return;
        }

        $elf = WPSFM_PLUGIN_URL . 'assets/elfinder/';
        $ver = WPSFM_VERSION;

        wp_enqueue_style( 'elfinder-css', $elf . 'css/elfinder.min.css', [], $ver );
        wp_enqueue_style( 'elfinder-theme', $elf . 'css/theme.css', [ 'elfinder-css' ], $ver );
        wp_enqueue_style( 'wpsfm-admin-css', WPSFM_PLUGIN_URL . 'assets/css/admin.css', [ 'elfinder-css' ], $ver );

        wp_enqueue_script( 'elfinder-js', $elf . 'js/elfinder.min.js', [ 'jquery' ], $ver, true );

        $locale    = get_locale();
        $lang_path = WPSFM_PLUGIN_DIR . 'assets/elfinder/js/i18n/elfinder.' . $locale . '.js';
        if ( file_exists( $lang_path ) ) {
            wp_enqueue_script(
                'elfinder-i18n',
                $elf . 'js/i18n/elfinder.' . $locale . '.js',
                [ 'elfinder-js' ],
                $ver,
                true
            );
        }

        wp_enqueue_script(
            'wpsfm-admin-js',
            WPSFM_PLUGIN_URL . 'assets/js/admin.js',
            [ 'elfinder-js' ],
            $ver,
            true
        );

        wp_localize_script( 'wpsfm-admin-js', 'wpsfm_vars', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'connector_url' => admin_url( 'admin-ajax.php?action=wpsfm_connector' ),
            'nonce'         => wp_create_nonce( 'wpsfm_nonce' ),
            'blog_id'       => get_current_blog_id(),
            'lang'          => str_replace( '_', '-', $locale ),
            'i18n'          => [
                'confirm_delete' => __( 'Excluir permanentemente os itens abaixo?', 'wp-secure-fm' ),
                'irreversible'   => __( 'Esta ação NÃO pode ser desfeita.', 'wp-secure-fm' ),
                'server_error'   => __( 'Erro de comunicação com o servidor.', 'wp-secure-fm' ),
            ],
        ] );
    }

    private function get_blogs() {
        if ( ! is_multisite() ) {
            return [
                (object) [
                    'blog_id'  => get_current_blog_id(),
                    'blogname' => get_bloginfo( 'name' ),
                ],
            ];
        }

        $sites = get_sites();
        $blogs = [];

        foreach ( $sites as $site ) {
            $blogname = get_blog_option( $site->blog_id, 'blogname' );
            if ( ! $blogname ) {
                $blogname = sprintf( 'Site %d', (int) $site->blog_id );
            }
            $blogs[] = (object) [
                'blog_id'  => (int) $site->blog_id,
                'blogname' => $blogname,
            ];
        }

        return $blogs;
    }
}
