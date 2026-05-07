<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="wrap">
  <h1>Permissões de Pastas</h1>
  <?php if ( isset( $_GET['saved'] ) ) : ?>
    <div class="notice notice-success">
      <p>Regra salva com sucesso!</p>
    </div>
  <?php endif; ?>
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'wpsfm_save_rule' ); ?>
    <input type="hidden" name="action" value="wpsfm_save_rule">
    <table class="form-table">
      <tr>
        <th>Pasta</th>
        <td>
          <select name="folder_id">
            <?php foreach ( $folders as $folder ) : ?>
              <option value="<?php echo esc_attr( $folder->id ); ?>">
                <?php echo esc_html( $folder->folder_name ); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <th>Tipo de Regra</th>
        <td>
          <select name="rule_type" id="rule_type">
            <option value="role">Por Papel (Role)</option>
            <option value="user">Por Usuário</option>
            <option value="capability">Por Capability</option>
          </select>
        </td>
      </tr>
      <tr>
        <th><label for="rule_value">Valor</label></th>
        <td>
          <input type="text" id="rule_value" name="rule_value" class="regular-text">
          <p class="description">
            Para Role: editor, administrator, author...<br>
            Para Usuário: ID numérico do usuário<br>
            Para Capability: edit_posts, manage_options...
          </p>
        </td>
      </tr>
      <tr>
        <th>Subsite (blog_id)</th>
        <td>
          <select name="blog_id">
            <option value="0">Todos os subsites</option>
            <?php foreach ( $blogs as $blog ) : ?>
              <option value="<?php echo esc_attr( $blog->blog_id ); ?>">
                <?php echo esc_html( $blog->blogname ); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <th>Permissões</th>
        <td>
          <label><input type="checkbox" name="can_read" value="1"> Leitura</label>&nbsp;&nbsp;
          <label><input type="checkbox" name="can_write" value="1"> Escrita</label>&nbsp;&nbsp;
          <label><input type="checkbox" name="can_delete" value="1"> Exclusão</label>
        </td>
      </tr>
    </table>
    <?php submit_button( 'Salvar Regra' ); ?>
  </form>
</div>
