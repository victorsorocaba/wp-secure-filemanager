<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSFM_Access_Control {
    /**
     * Verifica se o usuário atual pode acessar uma pasta
     *
     * @param string $folder_path Caminho da pasta
     * @param string $permission  'read', 'write' ou 'delete'
     * @return bool
     */
    public function can_access( $folder_path, $permission = 'read' ) {
        if ( is_multisite() && is_super_admin() ) {
            return true;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }

        $folder = $this->get_folder_by_path( $folder_path );
        if ( ! $folder ) {
            return false;
        }

        if ( $permission === 'write' ) {
            $can = apply_filters( 'wpsfm_can_upload', true, $user_id );
            if ( ! $can ) {
                return false;
            }
        }

        if ( $permission === 'delete' ) {
            $can = apply_filters( 'wpsfm_can_delete', true, $user_id );
            if ( ! $can ) {
                return false;
            }
        }

        return $this->check_rules( $folder->id, $user_id, $permission );
    }

    /**
     * Verifica as regras de acesso no banco de dados
     */
    private function check_rules( $folder_id, $user_id, $permission ) {
        global $wpdb;

        $blog_id = get_current_blog_id();
        $rules   = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wpsfm_access_rules
                 WHERE folder_id = %d
                   AND (blog_id = %d OR blog_id = 0)",
                $folder_id,
                $blog_id
            )
        );

        $col = 'can_' . $permission;

        foreach ( $rules as $rule ) {
            if ( empty( $rule->$col ) ) {
                continue;
            }
            switch ( $rule->rule_type ) {
                case 'user':
                    if ( (int) $rule->rule_value === (int) $user_id ) {
                        return true;
                    }
                    break;
                case 'role':
                    $user_in_blog = new WP_User( $user_id, '', $blog_id );
                    if ( in_array( $rule->rule_value, $user_in_blog->roles, true ) ) {
                        return true;
                    }
                    break;
                case 'capability':
                    if ( user_can( $user_id, $rule->rule_value ) ) {
                        return true;
                    }
                    break;
            }
        }

        return false;
    }

    private function get_folder_by_path( $folder_path ) {
        global $wpdb;

        $blog_id = get_current_blog_id();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wpsfm_folders
                 WHERE folder_path = %s
                   AND (blog_id = %d OR blog_id = 0)
                 LIMIT 1",
                $folder_path,
                $blog_id
            )
        );
    }
}
