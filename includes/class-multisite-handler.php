<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSFM_Multisite_Handler {
    /**
     * Retorna os blogs (subsites) onde o usuário atual tem
     * um papel específico ou qualquer papel
     */
    public function get_user_blogs_by_role( $user_id, $role = null ) {
        if ( ! is_multisite() ) {
            return [ get_current_blog_id() ];
        }

        $blogs = get_blogs_of_user( $user_id );

        if ( ! $role ) {
            return array_column( $blogs, 'userblog_id' );
        }

        $filtered = [];
        foreach ( $blogs as $blog ) {
            $user_in_blog = new WP_User( $user_id, '', $blog->userblog_id );
            if ( in_array( $role, $user_in_blog->roles, true ) ) {
                $filtered[] = $blog->userblog_id;
            }
        }

        return $filtered;
    }

    /**
     * Verifica se o usuário é editor (ou superior) em um subsite
     */
    public function user_is_editor_in_blog( $user_id, $blog_id ) {
        $user         = new WP_User( $user_id, '', $blog_id );
        $editor_roles = [ 'editor', 'administrator' ];

        return ! empty( array_intersect( $user->roles, $editor_roles ) );
    }

    /**
     * Filtra lista de pastas para mostrar somente as visíveis
     * pelo usuário no blog atual
     */
    public function filter_visible_folders( $folders, $user_id ) {
        $ac      = new WPSFM_Access_Control();
        $visible = [];

        foreach ( $folders as $folder ) {
            if ( $ac->can_access( $folder->folder_path, 'read' ) ) {
                $visible[] = $folder;
            }
        }

        return $visible;
    }
}
