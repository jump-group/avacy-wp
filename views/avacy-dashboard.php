<!-- <sl-spinner class="AvacyLoader"></sl-spinner> -->
<div class="wrap">
    <form id="avacy-form" method="post" action="options.php">
        <?php if( empty(get_option('avacy_tenant')) && empty(get_option('avacy_webspace_id'))) : ?>
            <div class="notice notice-warning">
                <p><?php echo __('Attenzione! Per utilizzare il plugin è necessario avere un account Avacy. Se non sei ancora registrato, puoi farlo qui.', 'avacy-wp')?></p>
            </div>
            <h1><?php echo __('Collega ad Avacy', 'avacy-wp')?></h1>
        <?php endif; ?>
        <?php if( !empty(get_option('avacy_tenant')) && !empty(get_option('avacy_webspace_id'))) : ?>
            <h1><?php echo __('Collegato ad Avacy', 'avacy-wp')?></h1>
        <?php endif; ?>
        <?php
            use Jumpgroup\Avacy\AddAdminInterface;

            settings_fields('avacy-plugin-settings-group');
            do_settings_sections('avacy-plugin-settings-group'); 
        ?>
        <br>
        <section class="AvacySection AvacySection--Account">
            <?php if (!get_option('avacy_tenant') || isset($_GET['edit'])) : ?>
                <sl-input name="avacy_tenant" placeholder="<?php echo __('Inserisci il nome del tuo team', 'avacy-wp')?>" size="small" value="<?php echo esc_attr(get_option('avacy_tenant')); ?>" required>
                    <label for="avacy_tenant" slot="label">
                        <span><?php echo __('Nome del Team Avacy', 'avacy-wp')?></span>
                    </label>
                    <sl-tooltip slot="suffix" content="<?php echo __('Inserisci il nome del Team a cui appartiene lo spazio web. Lo trovi in alto a destra in ogni pagina di Avacy.', 'avacy-wp') ?>" hoist>
                        <sl-icon name="info-circle"></sl-icon>
                    </sl-tooltip>
                </sl-input>
            <?php else: ?>
                <div class="AccountDetail"><span class="AccountDetail__Key"><?php echo __('Nome del Team Avacy', 'avacy-wp')?>:</span><span class="AccountDetail__Value"><?php echo esc_attr(get_option('avacy_tenant')); ?></span></div>
                <!-- hidden input -->
                <input type="hidden" name="avacy_tenant" value="<?php echo esc_attr(get_option('avacy_tenant')); ?>">
            <?php endif; ?>
            <?php if (!get_option('avacy_webspace_id') || isset($_GET['edit'])) : ?>
            <sl-input name="avacy_webspace_id" placeholder="<?php echo __('Inserisci la chiave specifica del tuo spazio web', 'avacy-wp')?>" size="small" value="<?php echo esc_attr(get_option('avacy_webspace_id')); ?>" required>
                <label for="avacy_webspace_id" slot="label">
                    <span><?php echo __('Chiave Spazio Web', 'avacy-wp')?></span>
                </label>
                <sl-tooltip slot="suffix" content="<?php echo __('Inserisci la chiave identificativa dello spazio web che trovi alla fine della configurazione dello spazio web oppure nelle impostazioni dello spazio web, alla voce "Integrazione di Avacy sul tuo spazio web".', 'avacy-wp') ?>" hoist>
                    <sl-icon name="info-circle"></sl-icon>
                </sl-tooltip>
            </sl-input>
            <?php else: ?>
                <div class="AccountDetail"><span class="AccountDetail__Key"><?php echo __('Chiave Spazio Web', 'avacy-wp')?>:</span><span class="AccountDetail__Value"><?php echo esc_attr(get_option('avacy_webspace_id')); ?></span></div>
                <!-- hidden input -->
                <input type="hidden" name="avacy_webspace_id" value="<?php echo esc_attr(get_option('avacy_webspace_id')); ?>">
            <?php endif; ?>
            <?php if( (!empty(get_option('avacy_tenant')) && !empty(get_option('avacy_webspace_id'))) && !isset($_GET['edit'])): ?>
                <sl-button class="Edit" variant="text">
                    <sl-icon slot="prefix" name="pencil"></sl-icon>
                    <?php echo __('Modifica', 'avacy-wp'); ?>
                </sl-button>
            <?php endif; ?>
            <?php if( (empty(get_option('avacy_tenant')) && empty(get_option('avacy_webspace_id')))): ?>
                <sl-button class="Submit" variant="primary" type="submit"><?php echo  __('Collega', 'avacy-wp')?></sl-button>
            <?php endif; ?>
            <?php if( isset($_GET['edit']) ): ?>
                <sl-button class="Submit" variant="primary" type="submit"><?php echo  __('Salva le modifiche', 'avacy-wp')?></sl-button>
            <?php endif; ?>
    
            <?php if( !empty(get_option('avacy_tenant') && !empty(get_option('avacy_webspace_id')))): ?>
        </section>
        <section class="AvacySection AvacySection--Table">
            <sl-tab-group>
                <sl-tab slot="nav" panel="cookie-banner" <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'cookie-banner')? 'active' : ''?>><?php echo __('Cookie banner', 'avacy-wp')?></sl-tab>
                <sl-tab slot="nav" panel="preemptive-block" <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'preemptive-block')? 'active' : ''?>><?php echo __('Blocco preventivo', 'avacy-wp')?></sl-tab>
                <sl-tab slot="nav" panel="consent-archive" <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'consent-archive')? 'active' : ''?>><?php echo __('Archivio consensi', 'avacy-wp')?></sl-tab>

                <sl-tab-panel name="cookie-banner" <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'cookie-banner')? 'active' : ''?>>
                    <div>
                        <?php $enabled = (get_option('avacy_show_banner') === 'on')? 'checked' : ''; ?>
                        <sl-checkbox name="avacy_show_banner" size="medium"  value="on" <?php echo esc_attr($enabled)?>><?php echo __('Mostra il cookie banner sul sito', 'avacy-wp') ?></sl-checkbox>
                        <div class="AvacyDescription">
                            <p>
                                <?php echo __('Per modificare l\'aspetto del cookie banner', 'avacy-wp')?> <a href="#"><?php echo __('clicca qui', 'avacy-wp') ?></a>
                            </p>
                        </div>
                    </div>
                </sl-tab-panel>
                <sl-tab-panel name="preemptive-block" <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'preemptive-block')? 'active' : ''?>>
                    <div>
                        <?php $enabled = (get_option('avacy_enable_preemptive_block') === 'on')? 'checked' : ''; ?>
                        <sl-checkbox name="avacy_enable_preemptive_block" size="medium"  value="on" <?php echo esc_attr($enabled)?>><?php echo __('Blocca preventivamente tutti gli script', 'avacy-wp')?></sl-checkbox>
                        <div class="AvacyDescription">
                            <p>
                                <?php echo __('Ricorda di inserire su Avacy l\'URL degli script che vuoi bloccare per tutti', 'avacy-wp')?> <a href="#"><?php echo __('i tuoi fornitori', 'avacy-wp')?></a>.
                                <br>
                                <?php echo __('Per maggiori informazioni', 'avacy-wp')?> <a href="#"><?php echo __('consulta la nostra guida', 'avacy-wp')?></a>. 
                            </p>
                        </div>
                    </div>
                </sl-tab-panel>
                <sl-tab-panel name="consent-archive" <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'consent-archive')? 'active' : ''?>>
                    <div class="AvacyDescription AvacyDescription--First">
                        <p>
                            <?php echo __('Imposta come Avacy registra i dati raccolti dal tuo spazio web nell’archivio consensi.', 'avacy-wp')?></a>.
                        </p>
                        <p>
                            <?php echo __('Nella sezione dell\'', 'avacy-wp')?><a href="#"><?php echo __('archivio consensi', 'avacy-wp')?></a>, <?php echo __('crea un nuovo token per collegare il servizio con Wordpress e inseriscilo nel campo sottostante', 'avacy-wp')?></a>.
                        </p>
                    </div>

                    <section class="AvacySection AvacySection--Account">
                            <div class="AvacySection__InlineForm">
                                <sl-input name="avacy_api_token" placeholder="<?php echo __('Token archivio consensi', 'avacy-wp')?>" size="small" value="<?php echo esc_attr(get_option('avacy_api_token')); ?>">
                                    <label for="avacy_api_token" slot="label">
                                        <span><?php echo __('Inserisci il token', 'avacy-wp')?></span>
                                    </label>
                                    <sl-tooltip slot="suffix" content="This is a tooltip" hoist>
                                        <sl-icon name="info-circle"></sl-icon>
                                    </sl-tooltip>
                                </sl-input>
                                <sl-button class="Submit" variant="primary" type="submit" size="small"><?php echo  __('Collega', 'avacy-wp')?></sl-button>
                            </div>
                    </section>

                    <?php if( !empty(get_option('avacy_api_token'))): ?>
                    <?php $forms = AddAdminInterface::detectAllForms(); ?>
                        <section class="AvacySection AvacySection--Table">
                            <table class="AvacyForms">
                                <thead>
                                    <tr>
                                        <th><?php echo  __('Form ID', 'avacy-wp')?></th>
                                        <th><?php echo  __('Tipologia', 'avacy-wp')?></th>
                                        <th><?php echo  __('Salva nell\'archivio consensi di Avacy', 'avacy-wp')?></th>
                                        <th><?php echo  __('Seleziona quali dati salvare su Avacy', 'avacy-wp')?></th>
                                        <th><?php echo  __('Campo identificativo dell\'utente', 'avacy-wp')?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($forms as $form) : ?>
                                        <?php 
                                        // dd($form);
                                        $form_type = esc_attr($form->getType());
                                        $type = esc_attr(strtolower(str_replace(' ', '_', $form_type)));
                                        $id = esc_attr($form->getId());
                                    ?>
                
                                    <tr>
                                        <td><?php echo $id; ?></td>
                                        <td><?php echo $form_type; ?></td>
                                        <td>
                                            <div>
                                                <?php $enabled = (get_option('avacy_' . $type . '_' . $id . '_radio_enabled') === 'on')? 'checked' : ''; ?>
                                                <sl-switch name="avacy_<?php echo $type?>_<?php echo $id?>_radio_enabled" value="on" <?php echo $enabled?> size="medium">
                                                    <label for="avacy_<?php echo $type?>_<?php echo $id?>_radio_enabled"><?php echo  __('Salva', 'avacy-wp')?></label>
                                                </sl-switch>
                                            </div>
                                        </td>
                                        <td>
                                            <sl-details summary="Dettagli">
                                                <sl-icon slot="collapse-icon" name="caret-left-fill"></sl-icon>
                                                <sl-icon slot="expand-icon" name="caret-down-fill"></sl-icon>
                                                <?php foreach ($form->getFields() as $field) : ?>
                                                    <?php $checked = (get_option('avacy_form_field_' . esc_attr($field['type']) . '_' . $id . '_' . esc_attr($field['name'])) === 'on')? 'checked' : '';?>
                                                    <sl-checkbox size="small" name="avacy_form_field_<?php echo esc_attr($field['type'])?>_<?php echo $id?>_<?php echo esc_attr($field['name'])?>" <?php echo $checked?>><?php echo esc_attr($field['name'])?></sl-checkbox>
                                                <?php endforeach; ?>
                                            </sl-details>
                                        </td>
                                        <td>
                                            <sl-select name="avacy_<?php echo $type?>_<?php echo $id?>_form_user_identifier" id="<?php echo $type ?>_<?php echo $id?>_form_user_identifier" value="<?php echo get_option('avacy_' . $type . '_' . $id . '_form_user_identifier')?>" size="small" placeholder="Select an option" required>
                                                <?php foreach ($form->getFields() as $key => $field) : ?>
                                                    <sl-option name="avacy_form_option_<?php echo esc_attr($key)?>_<?php echo esc_attr($field['type'])?>_<?php echo $id?>" value="<?php echo esc_attr($field['name'])?>"><?php echo esc_attr($field['name'])?></sl-option>
                                                <?php endforeach; ?>
                                            </sl-select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                    <?php endif; ?>
                </sl-tab-panel>
            </sl-tab-group>
        </section>

        <?php endif; ?>
            
    </form>
</div>
