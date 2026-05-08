<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<div class="wrap wpsfm-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-portfolio"></span>
        <?php esc_html_e( 'Gerenciador de Arquivos', 'wp-secure-fm' ); ?>
    </h1>

    <?php if ( is_multisite() ) : ?>
        <p class="description">
            <?php
            printf(
                esc_html__( 'Subsite atual: %s (ID: %d)', 'wp-secure-fm' ),
                esc_html( get_bloginfo( 'name' ) ),
                (int) get_current_blog_id()
            );
            ?>
        </p>
    <?php endif; ?>

    <hr class="wp-header-end">

    <?php
    $base = WP_CONTENT_DIR . '/uploads/wpsfm';
    if ( ! is_dir( $base ) ) :
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e( 'Diretório base não encontrado. Desative e reative o plugin.', 'wp-secure-fm' ); ?></p>
        </div>
    <?php endif; ?>

    <div id="wpsfm-elfinder"></div>
</div>
