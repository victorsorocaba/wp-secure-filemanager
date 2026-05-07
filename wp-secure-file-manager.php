<?php
/**
 * Plugin Name: WP Secure File Manager
 * Plugin URI:  https://seusite.com/wp-secure-file-manager
 * Description: Gerenciador de arquivos com controle de acesso baseado
 *              nas permissões nativas do WordPress e Multisite.
 * Version:     1.0.0
 * Author:      Seu Nome
 * Text Domain: wp-secure-fm
 * Network:     true
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Segurança: impede acesso direto ao arquivo
}

define( 'WPSFM_VERSION', '1.0.0' );
define( 'WPSFM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPSFM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Carrega as classes principais
require_once WPSFM_PLUGIN_DIR . 'includes/class-access-control.php';
require_once WPSFM_PLUGIN_DIR . 'includes/class-file-manager.php';
require_once WPSFM_PLUGIN_DIR . 'includes/class-connector.php';
require_once WPSFM_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once WPSFM_PLUGIN_DIR . 'includes/class-multisite-handler.php';

// Inicializa o plugin
function wpsfm_init() {
    $file_manager = new WPSFM_File_Manager();
    $file_manager->init();
}
add_action( 'plugins_loaded', 'wpsfm_init' );

// Hook de ativação
register_activation_hook( __FILE__, 'wpsfm_activate' );
function wpsfm_activate() {
    WPSFM_File_Manager::create_tables();
    WPSFM_File_Manager::create_base_directories();
}

/**
 * Exemplo de uso do filtro para adicionar ou remover extensões.
 * O administrador do site pode colocar este código no functions.php do tema.
 *
 * Adicionar extensão personalizada:
 * add_filter( 'wpsfm_allowed_extensions', function( $exts ) {
 *     $exts[] = 'dwg'; // AutoCAD
 *     $exts[] = 'ai';  // Adobe Illustrator
 *     return $exts;
 * });
 *
 * Remover extensões (ex: bloquear vídeos):
 * add_filter( 'wpsfm_allowed_extensions', function( $exts ) {
 *     return array_diff( $exts, ['mp4', 'avi', 'mov'] );
 * });
 *
 * Definir tamanho máximo de upload (em bytes):
 * add_filter( 'wpsfm_max_upload_size', function() {
 *     return 10 * 1024 * 1024; // 10 MB
 * });
 */

// Garante que o diretório base é protegido mesmo após atualização
add_action( 'upgrader_process_complete', function() {
    WPSFM_File_Manager::create_base_directories();
}, 10, 0 );

/**
 * Garante que só usuários com upload_files (capability nativa do WordPress)
 * possam fazer upload, independentemente das regras de pasta.
 */
add_filter( 'wpsfm_can_upload', function( $can, $user_id ) {
    if ( ! user_can( $user_id, 'upload_files' ) ) {
        return false;
    }
    return $can;
}, 10, 2 );

/**
 * Garante que só usuários com delete_posts possam excluir arquivos.
 */
add_filter( 'wpsfm_can_delete', function( $can, $user_id ) {
    if ( ! user_can( $user_id, 'delete_posts' ) ) {
        return false;
    }
    return $can;
}, 10, 2 );

// Endpoints autenticados
add_action( 'wp_ajax_wpsfm_connector', function() {
    ( new WPSFM_Connector() )->handle_request();
} );
add_action( 'wp_ajax_wpsfm_upload', function() {
    ( new WPSFM_Connector() )->handle_upload();
} );
add_action( 'wp_ajax_wpsfm_mkdir', function() {
    ( new WPSFM_Connector() )->handle_mkdir();
} );
add_action( 'wp_ajax_wpsfm_delete', function() {
    ( new WPSFM_Connector() )->handle_delete();
} );

// Bloqueia acesso anônimo
foreach ( [ 'wpsfm_upload', 'wpsfm_delete', 'wpsfm_mkdir', 'wpsfm_connector' ] as $action ) {
    add_action( 'wp_ajax_nopriv_' . $action, function() {
        wp_send_json_error( [ 'message' => 'Login necessário.' ], 401 );
    } );
}
