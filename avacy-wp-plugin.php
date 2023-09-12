<?php
/*
Plugin Name: Avacy Plugin UI for Wordpress
Description: Consent Solution Wordpress Plugin
*/

// Add an admin menu page
function avacy_plugin_init() {
    add_menu_page('Avacy Plugin', 'Avacy Plugin', 'manage_options', 'avacy-plugin-settings', 'avacy_plugin_settings_page');
}

add_action('admin_menu', 'avacy_plugin_init');

// Pagina delle impostazioni del plugin
function avacy_plugin_settings_page() {
    ?>
    <div class="wrap">
        <h2>Impostazioni Avacy</h2>
        <form method="post" action="options.php">
            <?php settings_fields('avacy-plugin-settings-group'); ?>
            <?php do_settings_sections('avacy-plugin-settings-group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Avacy Identifier</th>
                    <td><input type="text" name="avacy_identifier" value="<?php echo esc_attr(get_option('avacy_identifier')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Avacy Tenant</th>
                    <td><input type="text" name="avacy_tenant" value="<?php echo esc_attr(get_option('avacy_tenant')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Avacy Webspace ID</th>
                    <td><input type="text" name="avacy_webspace_id" value="<?php echo esc_attr(get_option('avacy_webspace_id')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Avacy API Token</th>
                    <td><input type="text" name="avacy_api_token" value="<?php echo esc_attr(get_option('avacy_api_token')); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Registra le opzioni del plugin
add_action('admin_init', 'avacy_plugin_register_settings');

// Registra le opzioni del plugin
function avacy_plugin_register_settings() {
    register_setting('avacy-plugin-settings-group', 'avacy_identifier');
    register_setting('avacy-plugin-settings-group', 'avacy_tenant');
    register_setting('avacy-plugin-settings-group', 'avacy_webspace_id');
    register_setting('avacy-plugin-settings-group', 'avacy_api_token');
}