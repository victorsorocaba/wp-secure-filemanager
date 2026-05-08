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

        WPSFM_File_Manager::create_base_directories();

        $cmd  = isset( $_REQUEST['cmd'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['cmd'] ) ) : '';
        $path = isset( $_REQUEST['target'] ) ? $this->resolve_target_path( wp_unslash( $_REQUEST['target'] ) ) : '';

        $permission = $this->get_required_permission( $cmd );
        $permission_target = $path;
        if ( $cmd === 'paste' ) {
            $permission_target = isset( $_REQUEST['dst'] ) ? $this->resolve_target_path( wp_unslash( $_REQUEST['dst'] ) ) : '';
        }

        if ( in_array( $cmd, [ 'init', 'open', 'ls', 'tmb' ], true ) && empty( $permission_target ) ) {
            $permission_target = $this->get_root_path();
        }

        if ( ! in_array( $cmd, [ 'rm', 'paste' ], true ) && $permission && empty( $permission_target ) ) {
            wp_send_json_error( [ 'message' => 'Destino inválido.' ], 400 );
        }

        if ( $permission && ! empty( $permission_target ) && ! $this->access_control->can_access( $permission_target, $permission ) ) {
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
        $write_cmds  = [ 'mkdir', 'mkfile', 'rename', 'rm', 'paste', 'upload', 'put', 'duplicate' ];
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

        $real            = wp_normalize_path( $real );
        $base            = wp_normalize_path( $base );
        $base_with_slash = trailingslashit( $base );
        if ( strpos( $real, $base_with_slash ) !== 0 && $real !== $base ) {
            return '';
        }

        return $real;
    }

    private function resolve_target_path( $target ) {
        $target  = trim( (string) $target );
        $decoded = $this->decode_target( $target );
        if ( $decoded !== '' ) {
            $path = $this->sanitize_path( $decoded );
            if ( $path !== '' ) {
                return $path;
            }
        }

        return $this->sanitize_path( $target );
    }

    private function get_root_path() {
        $base = realpath( WP_CONTENT_DIR . '/uploads/wpsfm' );
        if ( $base === false ) {
            return '';
        }

        return wp_normalize_path( $base );
    }

    private function encode_target( $path ) {
        return base64_encode( $path );
    }

    private function is_path_within_base( $path ) {
        $base = $this->get_root_path();
        if ( $base === '' ) {
            return false;
        }

        $path = wp_normalize_path( $path );
        $base = wp_normalize_path( $base );

        $base_with_slash = trailingslashit( $base );
        return strpos( $path, $base_with_slash ) === 0 || $path === $base;
    }

    private function build_child_path( $parent_dir, $name ) {
        $parent_dir = $this->sanitize_path( $parent_dir );
        if ( empty( $parent_dir ) || ! is_dir( $parent_dir ) ) {
            return '';
        }

        $name = sanitize_file_name( $name );
        if ( $name === '' ) {
            return '';
        }

        $path = wp_normalize_path( trailingslashit( $parent_dir ) . $name );
        if ( ! $this->is_path_within_base( $path ) ) {
            return '';
        }

        return $path;
    }

    private function get_allowed_mime_values() {
        $mimes = get_allowed_mime_types();
        $mimes['svg'] = 'image/svg+xml';
        $mimes = apply_filters( 'wpsfm_allowed_mimes', $mimes );

        $values = [];
        foreach ( (array) $mimes as $value ) {
            if ( is_array( $value ) ) {
                foreach ( $value as $item ) {
                    $values[] = $item;
                }
                continue;
            }
            $values[] = $value;
        }

        return array_unique( $values );
    }

    private function get_file_url( $path ) {
        $base = $this->get_root_path();
        if ( $base === '' ) {
            return '';
        }

        $relative = ltrim( str_replace( $base, '', wp_normalize_path( $path ) ), '/' );
        return content_url( 'uploads/wpsfm/' . $relative );
    }

    private function directory_has_subdirs( $path ) {
        if ( ! is_dir( $path ) ) {
            return false;
        }

        $iterator = new DirectoryIterator( $path );
        foreach ( $iterator as $item ) {
            if ( $item->isDot() ) {
                continue;
            }
            if ( $item->isDir() ) {
                return true;
            }
        }

        return false;
    }

    private function get_file_info( $path, $parent_hash = null ) {
        $is_dir = is_dir( $path );
        $name   = basename( $path );
        $root   = $this->get_root_path();

        if ( $root !== '' && wp_normalize_path( $path ) === $root ) {
            $name = 'wpsfm';
        }

        $hash = $this->encode_target( $path );
        $timestamp = 0;
        if ( file_exists( $path ) ) {
            $timestamp = filemtime( $path );
        }
        if ( ! $timestamp ) {
            $timestamp = time();
        }

        $size = 0;
        if ( ! $is_dir && file_exists( $path ) ) {
            $size = (int) filesize( $path );
        }

        $info = [
            'name'   => $name,
            'hash'   => $hash,
            'mime'   => $is_dir ? 'directory' : ( wp_check_filetype( $name )['type'] ?? 'application/octet-stream' ),
            'ts'     => $timestamp,
            'size'   => $size,
            'read'   => $this->access_control->can_access( $path, 'read' ) ? 1 : 0,
            'write'  => $this->access_control->can_access( $path, 'write' ) ? 1 : 0,
            'locked' => $this->access_control->can_access( $path, 'delete' ) ? 0 : 1,
        ];

        if ( $parent_hash ) {
            $info['phash'] = $parent_hash;
        }

        if ( $is_dir ) {
            $info['dirs'] = $this->directory_has_subdirs( $path ) ? 1 : 0;
        } else {
            $info['url'] = $this->get_file_url( $path );
        }

        return $info;
    }

    private function list_directory( $dir, $parent_hash = null ) {
        $items = [];

        if ( ! is_dir( $dir ) ) {
            return $items;
        }

        $iterator = new DirectoryIterator( $dir );
        foreach ( $iterator as $item ) {
            if ( $item->isDot() ) {
                continue;
            }
            $items[] = $this->get_file_info( $item->getPathname(), $parent_hash );
        }

        return $items;
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

    private function decode_target( $target ) {
        $decoded = base64_decode( $target, true );
        return $decoded === false ? '' : $decoded;
    }

    public function cmd_init( $path = '' ) {
        $root = $path ?: $this->get_root_path();
        if ( empty( $root ) || ! is_dir( $root ) ) {
            wp_send_json_error( [ 'message' => 'Diretório base indisponível.' ], 500 );
        }

        $cwd   = $this->get_file_info( $root );
        $files = array_merge( [ $cwd ], $this->list_directory( $root, $cwd['hash'] ) );

        wp_send_json( [
            'api'   => '2.1',
            'cwd'   => $cwd,
            'files' => $files,
        ] );
    }

    public function cmd_open( $path = '' ) {
        $target = $path ?: $this->get_root_path();
        if ( empty( $target ) ) {
            wp_send_json_error( [ 'message' => 'Destino inválido.' ], 400 );
        }

        if ( is_file( $target ) ) {
            $target = dirname( $target );
        }

        if ( ! is_dir( $target ) ) {
            wp_send_json_error( [ 'message' => 'Pasta inválida.' ], 400 );
        }

        if ( ! $this->access_control->can_access( $target, 'read' ) ) {
            wp_send_json_error( [ 'message' => 'Sem permissão para acessar esta pasta.' ], 403 );
        }

        $cwd   = $this->get_file_info( $target );
        $files = array_merge( [ $cwd ], $this->list_directory( $target, $cwd['hash'] ) );

        wp_send_json( [
            'cwd'   => $cwd,
            'files' => $files,
        ] );
    }

    public function cmd_ls( $path = '' ) {
        $target = $path ?: $this->get_root_path();
        if ( empty( $target ) || ! is_dir( $target ) ) {
            wp_send_json_error( [ 'message' => 'Pasta inválida.' ], 400 );
        }

        $list = [];
        $it   = new DirectoryIterator( $target );
        foreach ( $it as $item ) {
            if ( $item->isDot() ) {
                continue;
            }
            $list[] = $item->getFilename();
        }

        wp_send_json( [ 'list' => $list ] );
    }

    public function cmd_tmb() {
        wp_send_json( [ 'images' => [] ] );
    }

    public function cmd_file( $path = '' ) {
        $target = $path ?: ( isset( $_REQUEST['target'] ) ? $this->resolve_target_path( wp_unslash( $_REQUEST['target'] ) ) : '' );
        if ( empty( $target ) || ! is_file( $target ) ) {
            wp_send_json_error( [ 'message' => 'Arquivo inválido.' ], 400 );
        }

        if ( ! $this->access_control->can_access( $target, 'read' ) ) {
            wp_send_json_error( [ 'message' => 'Sem permissão para baixar este arquivo.' ], 403 );
        }

        $type = wp_check_filetype( basename( $target ) );
        $mime = $type['type'] ?: 'application/octet-stream';

        nocache_headers();
        header( 'Content-Type: ' . $mime );
        header( 'Content-Disposition: inline; filename="' . basename( $target ) . '"' );
        header( 'Content-Length: ' . filesize( $target ) );
        readfile( $target );
        exit;
    }

    public function cmd_upload() {
        $target = isset( $_REQUEST['target'] ) ? $this->resolve_target_path( wp_unslash( $_REQUEST['target'] ) ) : '';
        if ( empty( $target ) || ! is_dir( $target ) ) {
            wp_send_json_error( [ 'message' => 'Destino inválido.' ], 400 );
        }

        if ( ! $this->access_control->can_access( $target, 'write' ) ) {
            wp_send_json_error( [ 'message' => 'Sem permissão de upload nesta pasta.' ], 403 );
        }

        if ( empty( $_FILES['upload'] ) ) {
            wp_send_json_error( [ 'message' => 'Nenhum arquivo enviado.' ], 400 );
        }

        $uploaded = [];
        $errors   = [];
        $files    = $this->normalize_files_array( $_FILES['upload'] );
        $parent   = $this->encode_target( $target );

        foreach ( $files as $file ) {
            $result = $this->process_single_upload( $file, $target );
            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
                continue;
            }
            $uploaded[] = $this->get_file_info( $result['path'], $parent );
        }

        $response = [ 'added' => $uploaded ];
        if ( ! empty( $errors ) ) {
            $response['warning'] = implode( '; ', $errors );
        }

        wp_send_json( $response );
    }

    public function cmd_mkdir() {
        $target = isset( $_REQUEST['target'] ) ? $this->resolve_target_path( wp_unslash( $_REQUEST['target'] ) ) : '';
        $name   = isset( $_REQUEST['name'] ) ? sanitize_file_name( wp_unslash( $_REQUEST['name'] ) ) : '';

        if ( empty( $target ) || empty( $name ) ) {
            wp_send_json_error( [ 'message' => 'Parâmetros inválidos.' ], 400 );
        }

        if ( ! $this->access_control->can_access( $target, 'write' ) ) {
            wp_send_json_error( [ 'message' => 'Sem permissão para criar pasta aqui.' ], 403 );
        }

        $new_dir = $this->build_child_path( $target, $name );
        if ( empty( $new_dir ) ) {
            wp_send_json_error( [ 'message' => 'Nome de pasta inválido.' ], 400 );
        }

        if ( file_exists( $new_dir ) ) {
            wp_send_json_error( [ 'message' => 'Já existe uma pasta com este nome.' ], 409 );
        }

        if ( ! wp_mkdir_p( $new_dir ) ) {
            wp_send_json_error( [ 'message' => 'Falha ao criar pasta.' ], 500 );
        }

        chmod( $new_dir, 0755 );
        WPSFM_File_Manager::ensure_directory_protected( $new_dir );
        WPSFM_File_Manager::ensure_folder_record( $new_dir );
        $this->log_action( 'mkdir', $new_dir );

        wp_send_json( [ 'added' => [ $this->get_file_info( $new_dir, $this->encode_target( $target ) ) ] ] );
    }

    public function cmd_mkfile() {
        $target = isset( $_REQUEST['target'] ) ? $this->resolve_target_path( wp_unslash( $_REQUEST['target'] ) ) : '';
        $name   = isset( $_REQUEST['name'] ) ? sanitize_file_name( wp_unslash( $_REQUEST['name'] ) ) : '';

        if ( empty( $target ) || empty( $name ) ) {
            wp_send_json_error( [ 'message' => 'Parâmetros inválidos.' ], 400 );
        }

        if ( ! $this->access_control->can_access( $target, 'write' ) ) {
            wp_send_json_error( [ 'message' => 'Sem permissão para criar arquivo aqui.' ], 403 );
        }

        if ( ! $this->is_allowed_extension( $name ) ) {
            wp_send_json_error( [ 'message' => 'Extensão não permitida.' ], 400 );
        }

        $new_file = $this->build_child_path( $target, $name );
        if ( empty( $new_file ) ) {
            wp_send_json_error( [ 'message' => 'Nome de arquivo inválido.' ], 400 );
        }

        if ( file_exists( $new_file ) ) {
            wp_send_json_error( [ 'message' => 'Já existe um arquivo com este nome.' ], 409 );
        }

        if ( false === file_put_contents( $new_file, '' ) ) {
            wp_send_json_error( [ 'message' => 'Falha ao criar arquivo.' ], 500 );
        }

        chmod( $new_file, 0644 );
        $this->log_action( 'upload', $new_file );

        wp_send_json( [ 'added' => [ $this->get_file_info( $new_file, $this->encode_target( $target ) ) ] ] );
    }

    public function cmd_put() {
        $target  = isset( $_REQUEST['target'] ) ? $this->resolve_target_path( wp_unslash( $_REQUEST['target'] ) ) : '';
        $content = isset( $_REQUEST['content'] ) ? wp_unslash( $_REQUEST['content'] ) : '';

        if ( empty( $target ) || ! is_file( $target ) ) {
            wp_send_json_error( [ 'message' => 'Arquivo inválido.' ], 400 );
        }

        if ( ! $this->access_control->can_access( $target, 'write' ) ) {
            wp_send_json_error( [ 'message' => 'Sem permissão para editar este arquivo.' ], 403 );
        }

        if ( ! $this->is_allowed_extension( basename( $target ) ) ) {
            wp_send_json_error( [ 'message' => 'Extensão não permitida.' ], 400 );
        }

        if ( false === file_put_contents( $target, $content ) ) {
            wp_send_json_error( [ 'message' => 'Falha ao salvar arquivo.' ], 500 );
        }

        $this->log_action( 'upload', $target );

        wp_send_json( [ 'changed' => [ $this->get_file_info( $target ) ] ] );
    }

    public function cmd_rename() {
        $target = isset( $_REQUEST['target'] ) ? $this->resolve_target_path( wp_unslash( $_REQUEST['target'] ) ) : '';
        $name   = isset( $_REQUEST['name'] ) ? sanitize_file_name( wp_unslash( $_REQUEST['name'] ) ) : '';

        if ( empty( $target ) || empty( $name ) ) {
            wp_send_json_error( [ 'message' => 'Parâmetros inválidos.' ], 400 );
        }

        if ( $this->is_root_directory( $target ) ) {
            wp_send_json_error( [ 'message' => 'Não é possível renomear a pasta raiz.' ], 403 );
        }

        $parent = dirname( $target );
        $new    = $this->build_child_path( $parent, $name );
        if ( empty( $new ) ) {
            wp_send_json_error( [ 'message' => 'Nome inválido.' ], 400 );
        }

        if ( file_exists( $new ) ) {
            wp_send_json_error( [ 'message' => 'Já existe um item com este nome.' ], 409 );
        }

        if ( is_file( $target ) && ! $this->is_allowed_extension( $name ) ) {
            wp_send_json_error( [ 'message' => 'Extensão não permitida.' ], 400 );
        }

        if ( ! rename( $target, $new ) ) {
            wp_send_json_error( [ 'message' => 'Falha ao renomear.' ], 500 );
        }

        if ( is_dir( $new ) ) {
            $this->update_folder_paths( $target, $new );
        }

        $this->log_action( 'rename', $new );

        wp_send_json( [
            'added'   => [ $this->get_file_info( $new, $this->encode_target( dirname( $new ) ) ) ],
            'removed' => [ $this->encode_target( $target ) ],
        ] );
    }

    public function cmd_duplicate() {
        $target = isset( $_REQUEST['target'] ) ? $this->resolve_target_path( wp_unslash( $_REQUEST['target'] ) ) : '';
        if ( empty( $target ) ) {
            wp_send_json_error( [ 'message' => 'Destino inválido.' ], 400 );
        }

        $parent = dirname( $target );
        $name   = basename( $target );

        if ( is_dir( $target ) ) {
            $new_name = $this->make_unique_directory_name( $parent, $name );
            $new_dir  = $this->build_child_path( $parent, $new_name );
            if ( empty( $new_dir ) ) {
                wp_send_json_error( [ 'message' => 'Nome inválido.' ], 400 );
            }

            $result = $this->copy_directory_recursive( $target, $new_dir );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
            }

            wp_send_json( [ 'added' => [ $this->get_file_info( $new_dir, $this->encode_target( $parent ) ) ] ] );
        }

        $new_name = $this->make_unique_filename( $parent, $name );
        $new_file = $this->build_child_path( $parent, $new_name );
        if ( empty( $new_file ) ) {
            wp_send_json_error( [ 'message' => 'Nome inválido.' ], 400 );
        }

        if ( ! copy( $target, $new_file ) ) {
            wp_send_json_error( [ 'message' => 'Falha ao duplicar arquivo.' ], 500 );
        }

        chmod( $new_file, 0644 );
        $this->log_action( 'upload', $new_file );

        wp_send_json( [ 'added' => [ $this->get_file_info( $new_file, $this->encode_target( $parent ) ) ] ] );
    }

    public function cmd_paste() {
        $dst     = isset( $_REQUEST['dst'] ) ? $this->resolve_target_path( wp_unslash( $_REQUEST['dst'] ) ) : '';
        $targets = isset( $_REQUEST['targets'] ) ? (array) wp_unslash( $_REQUEST['targets'] ) : [];
        $cut     = isset( $_REQUEST['cut'] ) ? (bool) absint( $_REQUEST['cut'] ) : false;

        if ( empty( $dst ) || empty( $targets ) ) {
            wp_send_json_error( [ 'message' => 'Parâmetros inválidos.' ], 400 );
        }

        if ( ! $this->access_control->can_access( $dst, 'write' ) ) {
            wp_send_json_error( [ 'message' => 'Sem permissão para colar aqui.' ], 403 );
        }

        $added   = [];
        $removed = [];

        foreach ( $targets as $raw_target ) {
            $src = $this->resolve_target_path( sanitize_text_field( $raw_target ) );
            if ( empty( $src ) ) {
                continue;
            }

            if ( $cut && ! $this->access_control->can_access( $src, 'delete' ) ) {
                continue;
            }

            if ( ! $cut && ! $this->access_control->can_access( $src, 'read' ) ) {
                continue;
            }

            $name = basename( $src );

            if ( is_dir( $src ) ) {
                $unique = $this->make_unique_directory_name( $dst, $name );
                $dest   = $this->build_child_path( $dst, $unique );
                if ( empty( $dest ) ) {
                    continue;
                }

                if ( $cut ) {
                    if ( ! rename( $src, $dest ) ) {
                        continue;
                    }
                    $this->update_folder_paths( $src, $dest );
                    $removed[] = $this->encode_target( $src );
                } else {
                    $result = $this->copy_directory_recursive( $src, $dest );
                    if ( is_wp_error( $result ) ) {
                        continue;
                    }
                }

                $added[] = $this->get_file_info( $dest, $this->encode_target( $dst ) );
                continue;
            }

            $unique = $this->make_unique_filename( $dst, $name );
            $dest   = $this->build_child_path( $dst, $unique );
            if ( empty( $dest ) ) {
                continue;
            }

            if ( $cut ) {
                if ( ! rename( $src, $dest ) ) {
                    continue;
                }
                $removed[] = $this->encode_target( $src );
            } else {
                if ( ! copy( $src, $dest ) ) {
                    continue;
                }
            }

            chmod( $dest, 0644 );
            $this->log_action( 'upload', $dest );
            $added[] = $this->get_file_info( $dest, $this->encode_target( $dst ) );
        }

        wp_send_json( [
            'added'   => $added,
            'removed' => $removed,
        ] );
    }

    public function cmd_rm() {
        $targets = isset( $_REQUEST['targets'] ) ? (array) wp_unslash( $_REQUEST['targets'] ) : [];
        if ( empty( $targets ) ) {
            wp_send_json_error( [ 'message' => 'Nenhum item selecionado.' ], 400 );
        }

        $removed = [];
        foreach ( $targets as $raw_target ) {
            $path = $this->resolve_target_path( sanitize_text_field( $raw_target ) );
            if ( empty( $path ) ) {
                continue;
            }

            if ( ! $this->access_control->can_access( $path, 'delete' ) ) {
                continue;
            }

            if ( $this->is_root_directory( $path ) ) {
                continue;
            }

            $result = $this->delete_item( $path );
            if ( is_wp_error( $result ) ) {
                continue;
            }

            $removed[] = $this->encode_target( $path );
        }

        wp_send_json( [ 'removed' => $removed ] );
    }

    private function make_unique_directory_name( $dir, $name ) {
        $candidate = $name;
        $counter   = 1;

        while ( file_exists( trailingslashit( $dir ) . $candidate ) ) {
            $candidate = $name . '_' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function copy_directory_recursive( $source, $dest ) {
        if ( ! wp_mkdir_p( $dest ) ) {
            return new WP_Error( 'mkdir_failed', 'Falha ao criar pasta de destino.' );
        }

        chmod( $dest, 0755 );
        WPSFM_File_Manager::ensure_directory_protected( $dest );
        WPSFM_File_Manager::ensure_folder_record( $dest );

        $iterator = new DirectoryIterator( $source );
        foreach ( $iterator as $item ) {
            if ( $item->isDot() ) {
                continue;
            }

            $src_path = $item->getPathname();
            $dst_path = trailingslashit( $dest ) . $item->getFilename();

            if ( $item->isDir() ) {
                $result = $this->copy_directory_recursive( $src_path, $dst_path );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
                continue;
            }

            if ( ! copy( $src_path, $dst_path ) ) {
                return new WP_Error( 'copy_failed', 'Falha ao copiar arquivo: ' . $item->getFilename() );
            }
            chmod( $dst_path, 0644 );
        }

        return true;
    }

    private function update_folder_paths( $old_path, $new_path ) {
        global $wpdb;

        $old_path = wp_normalize_path( $old_path );
        $new_path = wp_normalize_path( $new_path );

        $like = $wpdb->esc_like( $old_path ) . '/%';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}wpsfm_folders
                 SET folder_path = REPLACE(folder_path, %s, %s)
                 WHERE folder_path = %s OR folder_path LIKE %s",
                $old_path,
                $new_path,
                $old_path,
                $like
            )
        );

        $wpdb->update(
            $wpdb->prefix . 'wpsfm_folders',
            [ 'folder_name' => basename( $new_path ) ],
            [ 'folder_path' => $new_path ],
            [ '%s' ],
            [ '%s' ]
        );
    }

    public function handle_upload() {
        check_ajax_referer( 'wpsfm_nonce', '_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Não autenticado.' ], 401 );
        }

        $target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
        $dest   = $this->resolve_target_path( $target );

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

        $allowed_mimes = $this->get_allowed_mime_values();

        if ( function_exists( 'finfo_open' ) ) {
            $finfo     = finfo_open( FILEINFO_MIME_TYPE );
            $mime_type = finfo_file( $finfo, $file['tmp_name'] );
            finfo_close( $finfo );
        } else {
            $mime_type = $file['type'];
        }

        if ( empty( $mime_type ) || ! in_array( $mime_type, $allowed_mimes, true ) ) {
            return new WP_Error( 'forbidden_mime', sprintf( 'Tipo MIME não permitido: %s', $mime_type ) );
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
        $dest_dir = $this->resolve_target_path( $target );

        if ( empty( $dest_dir ) || empty( $name ) ) {
            wp_send_json_error( [ 'message' => 'Parâmetros inválidos.' ], 400 );
        }

        if ( ! $this->access_control->can_access( $dest_dir, 'write' ) ) {
            wp_send_json_error( [ 'message' => 'Sem permissão para criar pasta aqui.' ], 403 );
        }

        $new_dir = $this->build_child_path( $dest_dir, $name );
        if ( empty( $new_dir ) ) {
            wp_send_json_error( [ 'message' => 'Nome de pasta inválido.' ], 400 );
        }
        if ( file_exists( $new_dir ) ) {
            wp_send_json_error( [ 'message' => 'Já existe uma pasta com este nome.' ], 409 );
        }

        if ( ! wp_mkdir_p( $new_dir ) ) {
            wp_send_json_error( [ 'message' => 'Falha ao criar pasta.' ], 500 );
        }

        chmod( $new_dir, 0755 );
        WPSFM_File_Manager::ensure_directory_protected( $new_dir );
        WPSFM_File_Manager::ensure_folder_record( $new_dir );
        $this->log_action( 'mkdir', $new_dir );

        wp_send_json_success( [ 'name' => $name, 'path' => $new_dir ] );
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
            $path = $this->resolve_target_path( sanitize_text_field( $raw_target ) );
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
        $normalized = wp_normalize_path( $path );
        $like       = $wpdb->esc_like( $normalized ) . '/%';

        $folder_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wpsfm_folders
                 WHERE folder_path = %s OR folder_path LIKE %s",
                $normalized,
                $like
            )
        );

        if ( ! empty( $folder_ids ) ) {
            $folder_ids   = array_values( array_filter( array_map( 'absint', $folder_ids ) ) );
            $placeholders = implode( ',', array_fill( 0, count( $folder_ids ), '%d' ) );

            if ( $placeholders !== '' ) {
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->prefix}wpsfm_access_rules WHERE folder_id IN ($placeholders)",
                        $folder_ids
                    )
                );
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->prefix}wpsfm_folders WHERE id IN ($placeholders)",
                        $folder_ids
                    )
                );
            }
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
                if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    $ip = '';
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
