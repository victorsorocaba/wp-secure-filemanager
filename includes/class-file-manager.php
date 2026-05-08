<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSFM_File_Manager {
    public function init() {
        new WPSFM_Admin_Page();
    }

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpsfm_folders (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            folder_path VARCHAR(500)        NOT NULL,
            folder_name VARCHAR(255)        NOT NULL,
            blog_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            parent_id   BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY folder_path (folder_path, blog_id)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpsfm_access_rules (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            folder_id   BIGINT(20) UNSIGNED NOT NULL,
            rule_type   ENUM('role','user','capability') NOT NULL,
            rule_value  VARCHAR(255)        NOT NULL,
            blog_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            can_read    TINYINT(1)          NOT NULL DEFAULT 0,
            can_write   TINYINT(1)          NOT NULL DEFAULT 0,
            can_delete  TINYINT(1)          NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY folder_id (folder_id)
        ) $charset_collate;";

        $sql3 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpsfm_audit_log (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT(20) UNSIGNED NOT NULL,
            blog_id     BIGINT(20) UNSIGNED NOT NULL,
            action      VARCHAR(50)         NOT NULL,
            item_path   VARCHAR(500)        NOT NULL,
            item_name   VARCHAR(255)        NOT NULL,
            ip_address  VARCHAR(45)         NOT NULL,
            created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql1 );
        dbDelta( $sql2 );
        dbDelta( $sql3 );
    }

    /**
     * Cria o diretório base do plugin e adiciona arquivos de proteção.
     */
    public static function create_base_directories() {
        $base = WP_CONTENT_DIR . '/uploads/wpsfm';

        if ( ! file_exists( $base ) ) {
            wp_mkdir_p( $base );
            chmod( $base, 0755 );
        }

        $htaccess = $base . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            $rules = implode( "\n", [
                '# WP Secure File Manager - Auto-generated',
                'Options -Indexes',
                '',
                '# Bloqueia execução de PHP neste diretório',
                '<FilesMatch "\.(php[0-9]*|phtml|phar)$">',
                '    Deny from all',
                '</FilesMatch>',
                '',
                '# Bloqueia acesso a arquivos de configuração',
                '<FilesMatch "\.(htaccess|htpasswd|ini|env|log)$">',
                '    Deny from all',
                '</FilesMatch>',
            ] );
            file_put_contents( $htaccess, $rules );
        }

        $index = $base . '/index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, '<?php // Silence is golden.' );
        }

        self::ensure_folder_record( $base, 0 );
        self::protect_subdirectories( $base );
    }

    public static function ensure_directory_protected( $dir ) {
        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, '<?php // Silence is golden.' );
        }
    }

    /**
     * Garante que a pasta exista na tabela de controle e retorna o ID do registro.
     *
     * @param string   $path    Caminho absoluto da pasta.
     * @param int|null $blog_id Blog atual ou 0 para global.
     * @return int ID do registro existente ou recém-criado.
     */
    public static function ensure_folder_record( $path, $blog_id = null ) {
        global $wpdb;

        $normalized = wp_normalize_path( $path );
        if ( null === $blog_id ) {
            $blog_id = get_current_blog_id();
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wpsfm_folders WHERE folder_path = %s AND blog_id = %d",
                $normalized,
                $blog_id
            )
        );

        if ( $existing ) {
            return (int) $existing;
        }

        $wpdb->insert(
            $wpdb->prefix . 'wpsfm_folders',
            [
                'folder_path' => $normalized,
                'folder_name' => basename( $normalized ),
                'blog_id'     => $blog_id,
            ],
            [ '%s', '%s', '%d' ]
        );

        return (int) $wpdb->insert_id;
    }

    private static function protect_subdirectories( $dir ) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                self::ensure_directory_protected( $item->getRealPath() );
            }
        }
    }

    public static function get_folders( $blog_id = null ) {
        global $wpdb;

        if ( null === $blog_id ) {
            $blog_id = get_current_blog_id();
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wpsfm_folders
                 WHERE blog_id = %d OR blog_id = 0
                 ORDER BY folder_name ASC",
                $blog_id
            )
        );
    }
}
