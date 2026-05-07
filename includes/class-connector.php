<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSFM_Connector {
    private $access_control;

    public function __construct() {
        $this->access_control = new WPSFM_Access_Control();
    }

    /**
     * Ponto de entrada do conector (chamado via AJAX)
     */
    public function handle_request() {
        check_ajax_referer( 'wpsfm_nonce', '_nonce' );

        $cmd  = isset( $_REQUEST['cmd'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['cmd'] ) ) : '';
        $path = isset( $_REQUEST['target'] ) ? $this->sanitize_path( wp_unslash( $_REQUEST['target'] ) ) : '';

        $permission = $this->get_required_permission( $cmd );
        if ( ! $this->access_control->can_access( $path, $permission ) ) {
            wp_send_json_error(
                [
                    'error'   => 'Access Denied',
                    'message' => __( 'Você não tem permissão para esta operação.', 'wp-secure-fm' ),
                ],
                403
            );
        }

        $method = 'cmd_' . $cmd;
        if ( method_exists( $this, $method ) ) {
            $this->$method( $path );
            return;
        }

        wp_send_json_error( [ 'message' => 'Comando não suportado.' ], 400 );
    }

    private function get_required_permission( $cmd ) {
        $write_cmds  = [ 'mkdir', 'mkfile', 'rename', 'rm', 'paste', 'upload', 'put' ];
        $delete_cmds = [ 'rm', 'trash' ];

        if ( in_array( $cmd, $delete_cmds, true ) ) {
            return 'delete';
        }

        if ( in_array( $cmd, $write_cmds, true ) ) {
            return 'write';
        }

        return 'read';
    }

    /**
     * Valida e normaliza um caminho recebido do cliente.
     */
    private function sanitize_path( $raw_path ) {
        $raw_path = trim( $raw_path );
        $raw_path = str_replace( "\0", '', $raw_path );

        $real = realpath( $raw_path );
        if ( $real === false ) {
            return '';
        }

        $base = realpath( WP_CONTENT_DIR . '/uploads/wpsfm' );
        if ( $base === false ) {
            return '';
        }

        $base_with_slash = trailingslashit( $base );
        if ( stripos( $real, $base_with_slash ) !== 0 && strcasecmp( $real, $base ) !== 0 ) {
            return '';
        }

        return $real;
    }

    private function is_allowed_extension( $filename ) {
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

        $blocked = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8',
            'phtml', 'phar',
            'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb',
            'sh', 'bash', 'exe', 'bat', 'cmd', 'com',
            'htaccess', 'htpasswd', 'ini', 'env',
        ];

        if ( in_array( $ext, $blocked, true ) ) {
            return false;
        }

        $allowed = apply_filters( 'wpsfm_allowed_extensions', [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods',
            'txt', 'csv', 'json', 'xml', 'md',
            'mp4', 'mp3', 'wav', 'avi', 'mov', 'webm',
            'zip', 'rar', '7z', 'tar', 'gz',
            'ttf', 'otf', 'woff', 'woff2',
        ] );

        return in_array( $ext, $allowed, true );
    }

    private function is_root_directory( $path ) {
        $base = realpath( WP_CONTENT_DIR . '/uploads/wpsfm' );
        return $base !== false && realpath( $path ) === $base;
    }

    private function normalize_files_array( $files ) {
        if ( isset( $files['name'] ) && ! is_array( $files['name'] ) ) {
            return [ $files ];
        }

        $normalized = [];
        $count      = count( $files['name'] );

        for ( $i = 0; $i < $count; $i++ ) {
            $normalized[] = [
                'name'     => $files['name'][ $i ],
                'type'     => $files['type'][ $i ],
                'tmp_name' => $files['tmp_name'][ $i ],
                'error'    => $files['error'][ $i ],
                'size'     => $files['size'][ $i ],
            ];
        }

        return $normalized;
    }

    public function handle_upload() {
        check_ajax_referer( 'wpsfm_nonce', '_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Não autenticado.' ], 401 );
        }

        $target  = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
        $decoded = base64_decode( $target, true );
        if ( $decoded === false ) {
            wp_send_json_error( [ 'message' => 'Destino inválido.' ], 400 );
        }
        $dest = $this->sanitize_path( $decoded );

        if ( empty( $dest ) || ! is_dir( $dest ) ) {
            wp_send_json_error( [ 'message' => 'Pasta de destino inválida.' ], 400 );
        }

        if ( ! $this->access_control->can_access( $dest, 'write' ) ) {
            wp_send_json_error( [ 'message' => 'Sem permissão de upload nesta pasta.' ], 403 );
        }

        if ( empty( $_FILES['upload'] ) ) {
            wp_send_json_error( [ 'message' => 'Nenhum arquivo enviado.' ], 400 );
        }

        $uploaded = [];
        $errors   = [];
        $files    = $this->normalize_files_array( $_FILES['upload'] );

        foreach ( $files as $file ) {
            $result = $this->process_single_upload( $file, $dest );
            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
            } else {
                $uploaded[] = $result;
            }
        }

        if ( ! empty( $errors ) && empty( $uploaded ) ) {
            wp_send_json_error( [ 'message' => implode( '; ', $errors ) ], 422 );
        }

        wp_send_json_success( [
            'added'  => $uploaded,
            'errors' => $errors,
        ] );
    }

    private function process_single_upload( $file, $dest_dir ) {
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error(
                'upload_error',
                sprintf( 'Erro no arquivo %s: código %d', $file['name'], $file['error'] )
            );
        }

        $filename  = sanitize_file_name( $file['name'] );
        $filename  = $this->make_unique_filename( $dest_dir, $filename );
        $dest_path = trailingslashit( $dest_dir ) . $filename;

        if ( ! $this->is_allowed_extension( $filename ) ) {
            return new WP_Error( 'forbidden_ext', sprintf( 'Extensão não permitida: %s', $filename ) );
        }

        $max_size = apply_filters( 'wpsfm_max_upload_size', 50 * 1024 * 1024 );
        if ( $file['size'] > $max_size ) {
            return new WP_Error(
                'file_too_large',
                sprintf( 'Arquivo muito grande: %s (máx %s)', $filename, size_format( $max_size ) )
            );
        }

        if ( function_exists( 'finfo_open' ) ) {
            $finfo     = finfo_open( FILEINFO_MIME_TYPE );
            $mime_type = finfo_file( $finfo, $file['tmp_name'] );
            finfo_close( $finfo );

            $allowed_mimes = array_values( apply_filters( 'wpsfm_allowed_mimes', get_allowed_mime_types() ) );
            if ( ! in_array( $mime_type, $allowed_mimes, true ) ) {
                return new WP_Error( 'forbidden_mime', sprintf( 'Tipo MIME não permitido: %s', $mime_type ) );
            }
        } else {
            $mime_type = $file['type'];
        }

        if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
            return new WP_Error( 'move_failed', sprintf( 'Falha ao mover arquivo: %s', $filename ) );
        }

        chmod( $dest_path, 0644 );
        $this->log_action( 'upload', $dest_path );

        return [
            'name' => $filename,
            'path' => $dest_path,
            'size' => $file['size'],
            'mime' => $mime_type,
        ];
    }

    private function make_unique_filename( $dir, $filename ) {
        $info      = pathinfo( $filename );
        $name      = $info['filename'];
        $ext       = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
        $candidate = $filename;
        $counter   = 1;

        while ( file_exists( trailingslashit( $dir ) . $candidate ) ) {
            $candidate = $name . '_' . $counter . $ext;
            $counter++;
        }

        return $candidate;
    }

    public function handle_mkdir() {
        check_ajax_referer( 'wpsfm_nonce', '_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Não autenticado.' ], 401 );
        }

        $target   = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
        $name     = isset( $_POST['name'] ) ? sanitize_file_name( wp_unslash( $_POST['name'] ) ) : '';
        $decoded  = base64_decode( $target, true );
        if ( $decoded === false ) {
            wp_send_json_error( [ 'message' => 'Destino inválido.' ], 400 );
        }
        $dest_dir = $this->sanitize_path( $decoded );

        if ( empty( $dest_dir ) || empty( $name ) ) {
            wp_send_json_error( [ 'message' => 'Parâmetros inválidos.' ], 400 );
        }

        if ( ! $this->access_control->can_access( $dest_dir, 'write' ) ) {
            wp_send_json_error( [ 'message' => 'Sem permissão para criar pasta aqui.' ], 403 );
        }

        $new_dir = trailingslashit( $dest_dir ) . $name;
        if ( file_exists( $new_dir ) ) {
            wp_send_json_error( [ 'message' => 'Já existe uma pasta com este nome.' ], 409 );
        }

        if ( ! wp_mkdir_p( $new_dir ) ) {
            wp_send_json_error( [ 'message' => 'Falha ao criar pasta.' ], 500 );
        }

        chmod( $new_dir, 0755 );
        $this->register_folder_in_db( $new_dir );
        $this->log_action( 'mkdir', $new_dir );

        wp_send_json_success( [ 'name' => $name, 'path' => $new_dir ] );
    }

    private function register_folder_in_db( $path ) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'wpsfm_folders',
            [
                'folder_path' => $path,
                'folder_name' => basename( $path ),
                'blog_id'     => get_current_blog_id(),
            ],
            [ '%s', '%s', '%d' ]
        );
    }

    public function handle_delete() {
        check_ajax_referer( 'wpsfm_nonce', '_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Não autenticado.' ], 401 );
        }

        $targets = isset( $_POST['targets'] ) ? (array) wp_unslash( $_POST['targets'] ) : [];
        if ( empty( $targets ) ) {
            wp_send_json_error( [ 'message' => 'Nenhum item selecionado.' ], 400 );
        }

        $removed = [];
        $errors  = [];

        foreach ( $targets as $raw_target ) {
            $decoded = base64_decode( sanitize_text_field( $raw_target ), true );
            if ( $decoded === false ) {
                $errors[] = 'Caminho inválido: ' . esc_html( $raw_target );
                continue;
            }
            $path = $this->sanitize_path( $decoded );
            if ( empty( $path ) ) {
                $errors[] = 'Caminho inválido: ' . esc_html( $raw_target );
                continue;
            }

            if ( ! $this->access_control->can_access( $path, 'delete' ) ) {
                $errors[] = 'Sem permissão para excluir: ' . basename( $path );
                continue;
            }

            if ( $this->is_root_directory( $path ) ) {
                $errors[] = 'Não é possível excluir a pasta raiz.';
                continue;
            }

            $result = $this->delete_item( $path );
            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
            } else {
                $removed[] = $raw_target;
            }
        }

        wp_send_json_success( [ 'removed' => $removed, 'errors' => $errors ] );
    }

    private function delete_item( $path ) {
        if ( ! file_exists( $path ) ) {
            return new WP_Error( 'not_found', 'Item não encontrado: ' . basename( $path ) );
        }

        $this->log_action( 'delete', $path );

        if ( is_file( $path ) ) {
            if ( ! unlink( $path ) ) {
                return new WP_Error( 'delete_failed', 'Falha ao excluir: ' . basename( $path ) );
            }
            return true;
        }

        if ( is_dir( $path ) ) {
            return $this->delete_directory_recursive( $path );
        }

        return new WP_Error( 'unknown_type', 'Tipo desconhecido: ' . basename( $path ) );
    }

    private function delete_directory_recursive( $dir ) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                if ( ! rmdir( $item->getRealPath() ) ) {
                    return new WP_Error( 'rmdir_failed', 'Falha ao remover subpasta: ' . $item->getFilename() );
                }
            } else {
                if ( ! unlink( $item->getRealPath() ) ) {
                    return new WP_Error( 'unlink_failed', 'Falha ao remover arquivo: ' . $item->getFilename() );
                }
            }
        }

        if ( ! rmdir( $dir ) ) {
            return new WP_Error( 'rmdir_root_failed', 'Falha ao remover pasta: ' . basename( $dir ) );
        }

        $this->remove_folder_from_db( $dir );

        return true;
    }

    private function remove_folder_from_db( $path ) {
        global $wpdb;

        $folder = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wpsfm_folders WHERE folder_path = %s",
                $path
            )
        );

        if ( $folder ) {
            $wpdb->delete( $wpdb->prefix . 'wpsfm_access_rules', [ 'folder_id' => $folder->id ], [ '%d' ] );
            $wpdb->delete( $wpdb->prefix . 'wpsfm_folders', [ 'id' => $folder->id ], [ '%d' ] );
        }
    }

    private function log_action( $action, $path ) {
        global $wpdb;

        $ip = '';
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                if ( strpos( $ip, ',' ) !== false ) {
                    $parts = explode( ',', $ip );
                    $ip    = trim( $parts[0] );
                }
                break;
            }
        }

        $wpdb->insert(
            $wpdb->prefix . 'wpsfm_audit_log',
            [
                'user_id'    => get_current_user_id(),
                'blog_id'    => get_current_blog_id(),
                'action'     => $action,
                'item_path'  => $path,
                'item_name'  => basename( $path ),
                'ip_address' => $ip,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s' ]
        );
    }
}
