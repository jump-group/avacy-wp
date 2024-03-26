<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@shoelace-style/shoelace@2.15.0/cdn/themes/light.css" />
<script type="module" src="https://cdn.jsdelivr.net/npm/@shoelace-style/shoelace@2.15.0/cdn/shoelace-autoloader.js"></script>

<div class="wrap">
    <h2>Impostazioni Avacy</h2>
    <form method="post" action="options.php">
        <h2>Account</h2>
        <?php

        use Jumpgroup\Avacy\AddAdminInterface;

        settings_fields('avacy-plugin-settings-group'); ?>
        <?php do_settings_sections('avacy-plugin-settings-group'); ?>

        <section class="AvacySection AvacySection--Account">
            <sl-input name="avacy_tenant" placeholder="<?php echo __('Inserisci il nome del tuo team')?>" size="small" value="<?php echo esc_attr(get_option('avacy_tenant')); ?>" required>
                <label for="avacy_tenant" slot="label">
                    <span><?php echo __('Nome del Team Avacy')?></span>
                </label>
                <sl-tooltip slot="suffix" content="This is a tooltip" hoist>
                    <sl-icon name="info-circle"></sl-icon>
                </sl-tooltip>
            </sl-input>
            <sl-input name="avacy_webspace_id" placeholder="<?php echo __('Inserisci la chiave specifica del tuo spazio web')?>" size="small" value="<?php echo esc_attr(get_option('avacy_webspace_id')); ?>" required>
                <label for="avacy_webspace_id" slot="label">
                    <span><?php echo __('Chiave Spazio Web')?></span>
                </label>
                <sl-tooltip slot="suffix" content="This is a tooltip" hoist>
                    <sl-icon name="info-circle"></sl-icon>
                </sl-tooltip>
            </sl-input>
            <sl-input name="avacy_api_token" placeholder="<?php echo __('Inserisci l\'API Key')?>" size="small" value="<?php echo esc_attr(get_option('avacy_api_token')); ?>" required>
                <label for="avacy_api_token" slot="label">
                    <span><?php echo __('API Key Token')?></span>
                </label>
                <sl-tooltip slot="suffix" content="This is a tooltip" hoist>
                    <sl-icon name="info-circle"></sl-icon>
                </sl-tooltip>
            </sl-input>
        </section>

        <?php $forms = AddAdminInterface::detectAllForms(); ?>
        <h2>Moduli</h2>
        <section class="AvacySection AvacySection--Table">
            <table class="AvacyForms">
                <thead>
                    <tr>
                        <th>Form ID</th>
                        <th>Type</th>
                        <th>Salva nell'archivio consensi di Avacy</th>
                        <th>Seleziona quali dati salvare su Avacy</th>
                        <th>Campo identificativo dell'utente</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($forms as $form) : ?>
                        <?php 
                            // dd($form);
                            $type = strtolower(str_replace(' ', '_', $form->getType()));
                            $id = $form->getId();
                        ?>
    
                        <tr>
                            <td><?php echo $form->getId(); ?></td>
                            <td><?php echo $form->getType(); ?></td>
                            <td>
                                <div>
                                    <?php $enabled = (get_option('avacy_' . $type . '_' . $id . '_radio_enabled') === 'on')? 'checked' : ''; ?>
                                    <sl-switch name="avacy_<?php echo $type?>_<?php echo $id?>_radio_enabled" value="on" <?php echo $enabled?> size="medium">
                                        <label for="avacy_<?php echo $type?>_<?php echo $id?>_radio_enabled">Salva</label>
                                    </sl-switch>
                                </div>
                            </td>
                            <td>
                                <sl-details summary="Dettagli">
                                    <sl-icon slot="collapse-icon" name="caret-left-fill"></sl-icon>
                                    <sl-icon slot="expand-icon" name="caret-down-fill"></sl-icon>
                                    <?php foreach ($form->getFields() as $field) : ?>
                                        <?php $checked = (get_option('avacy_form_field_' . $field['type'] . '_' . $id . '_' . $field['name']) === 'on')? 'checked' : '';?>
                                        <sl-checkbox size="small" name="avacy_form_field_<?php echo $field['type']?>_<?php echo $id?>_<?php echo $field['name']?>" <?php echo $checked?>><?php echo $field['name']?></sl-checkbox>
                                    <?php endforeach; ?>
                                </sl-details>
                            </td>
                            <td>
                                <sl-select name="avacy_<?php echo $form->getType()?>_<?php echo $id?>_form_user_identifier" id="<?php echo $form->getType()?>_<?php echo $id?>_form_user_identifier" value="<?php echo get_option('avacy_' . $type . '_' . $id . '_form_user_identifier')?>" size="small" placeholder="Select an option" required>
                                    <?php foreach ($form->getFields() as $key => $field) : ?>
                                        <sl-option name="avacy_form_option_<?php echo $key?>_<?php echo $field['type']?>_<?php echo $id?>" value="<?php echo $field['name']?>"><?php echo $field['name']?></sl-option>
                                    <?php endforeach; ?>
                                </sl-select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <h2>Blocco preventivo</h2>
        <div>
            <?php $enabled = (get_option('avacy_enable_preemptive_block') === 'on')? 'checked' : ''; ?>
            <sl-checkbox size="medium"  value="on" <?php echo $enabled?>>Blocca preventivamente tutti gli script</sl-checkbox>
        </div>

        <sl-button class="Submit" variant="primary" type="submit">Salva</sl-button>

    </form>
</div>
