<div class="wrap">
    <h2>Impostazioni Avacy</h2>
    <form method="post" action="options.php">
        <h2>Account</h2>
        <?php

use Jumpgroup\Avacy\AddAdminInterface;

settings_fields('avacy-plugin-settings-group'); ?>
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

        <?php $forms = AddAdminInterface::detectAllForms(); ?>
        <h2>Moduli</h2>
        <table>
            <thead>
                <tr>
                    <th>Form ID</th>
                    <th>Tipo</th>
                    <th>Attivo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $form) : ?>
                    <tr>
                        <td><?php echo $form->getId(); ?></td>
                        <td><?php echo $form->getType(); ?></td>
                        <td></td>
                        <td>
                            <details>
                                <summary>Dettagli</summary>
                                <?php foreach ($form->getFields() as $field) : ?>
                                    <div><input type="checkbox" name="avacy_form_field_<?=$field['type']?>_<?=$field['name']?>"><?=$field['name']?></input></div>
                                <?php endforeach; ?>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php submit_button(); ?>
    </form>
</div>
