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
                    <th>Type</th>
                    <th>Active</th>
                    <th>Fields</th>
                    <th>Identifier</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $form) : ?>
                    <?php 
                        $type = strtolower(str_replace(' ', '_', $form->getType()));
                        $id = $form->getId();
                    ?>

                    <tr>
                        <td><?php echo $form->getId(); ?></td>
                        <td><?php echo $form->getType(); ?></td>
                        <td>
                            <div>
                                <?php $enabled = (get_option('avacy_' . $type . '_' . $id . '_radio_enabled') === 'on')? 'checked' : ''; ?>
                                <?php $disabled = (get_option('avacy_' . $type . '_' . $id . '_radio_enabled') === 'off')? 'checked' : ''; ?>
                                <div><input type="radio" name="avacy_<?php echo $type?>_<?php echo $id?>_radio_enabled" value="on" <?php echo $enabled?>/><label for="<?php echo $type?>_enabled">Attivo</label></div>
                                <div><input type="radio" name="avacy_<?php echo $type?>_<?php echo $id?>_radio_enabled" value="off" <?php echo $disabled?>/><label for="<?php echo $type?>_enabled">Non Attivo</label></div>
                            </div>
                        </td>
                        <td>
                            <details>
                                <summary>Dettagli</summary>
                                <?php foreach ($form->getFields() as $field) : ?>
                                    <?php $checked = (get_option('avacy_form_field_' . $field['type'] . '_' . $id . '_' . $field['name']) === 'on')? 'checked' : '';
                                    
                                    ?>
                                    <div><input type="checkbox" name="avacy_form_field_<?php echo $field['type']?>_<?php echo $id?>_<?php echo $field['name']?>" <?php echo $checked?>><?php echo $field['name']?></input></div>
                                <?php endforeach; ?>
                            </details>
                        </td>
                        <td>
                            <select name="avacy_<?php echo $form->getType()?>_<?php echo $id?>_form_user_identifier" id="<?php echo $form->getType()?>_<?php echo $id?>_form_user_identifier">
                                <option name="avacy_form_option_none" value="" disabled>Choose an option</option>
                                <?php foreach ($form->getFields() as $key => $field) : ?>
                                    <?php
                                        $selected = (get_option('avacy_' . $type . '_' . $id . '_form_user_identifier') === $field['name'])? 'selected' : '';
                                    ?>
                                    <option name="avacy_form_option_<?php echo $key?>_<?php echo $field['type']?>_<?php echo $id?>" value="<?php echo $field['name']?>" <?php echo $selected?>><?php echo $field['name']?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Blocco preventivo</h2>
        <div>
            <?php $enabled = (get_option('avacy_enable_preemptive_block') === 'on')? 'checked' : ''; ?>
            <input type="checkbox" name="avacy_enable_preemptive_block" value="on" <?php echo $enabled?>/><label for="avacy_enable_preemptive_block"> Blocca preventivamente tutti gli script</label>
        </div>
        <?php submit_button(); ?>
    </form>
</div>
