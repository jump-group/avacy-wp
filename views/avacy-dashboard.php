<?php
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly.
    }
    use Jumpgroup\Avacy\AddAdminInterface;
    // esacape the base api url
    $base_api_url = esc_attr('https://api.avacy.eu/wp');
    $registration_url = esc_attr('https://avacy.eu/registration');
    $documention_url = esc_attr('https://docs.avacysolution.com');

    // dd($_GET);
?>
    
<sl-spinner class="AvacyLoader"></sl-spinner>
<div class="wrap hide">
    <form id="avacy-form" method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <?php if( empty(get_option('avacy_webspace_id'))) : ?>
            <h1><?php echo __('Collega ad Avacy', 'avacy-wp')?></h1>
            <sl-alert variant="warning" open closable class="alert-closable">
                <sl-icon slot="icon" name="exclamation-triangle"></sl-icon>
                <strong><?php echo __('Attenzione!', 'avacy-wp')?></strong><br />
                <?php echo __('Per utilizzare il plugin è necessario avere un account Avacy. Se non sei ancora registrato, puoi farlo <a href="'.$registration_url.'" target="_blank">qui</a>.', 'avacy-wp')?>
            </sl-alert>
        <?php endif; ?>
        <?php if( !empty(get_option('avacy_webspace_id'))) : ?>
            <h1><?php echo __('Collegato ad Avacy', 'avacy-wp')?></h1>
        <?php endif; ?>

        <?php
        // /wp-content/plugins/ct-wp-admin-form/views/alerts.php
        $form_errors = get_transient("settings_errors");
        delete_transient("settings_errors");
        if(!empty($form_errors)){
            foreach($form_errors as $error){ ?>
                    <sl-alert variant="<?php echo $error['type']?>" open closable class="alert-closable">
                        <?php 
                        $icon_name = '';
                        switch($error['type']){
                            case 'danger':
                                $icon_name = 'exclamation-octagon';
                                break;
                            case 'warning':
                                $icon_name = 'exclamation-triangle';
                                break;
                            case 'success':
                                $icon_name = 'check2-circle';
                                break;
                            default:
                                $icon_name = 'info-circle';
                                break;
                        }?>
                        <sl-icon slot="icon" name="<?php echo esc_attr($icon_name)?>"></sl-icon>
                        <?php echo $error['message']?><br />
                    </sl-alert>
            <?php }
        }
        ?>


        <input type="hidden" name="action" value="avacy_admin_save">
        <input type="hidden" name="option_page" value="avacy-plugin-settings-group">
        <?php wp_nonce_field('avacy-plugin-settings-group-options'); ?>
        <input type="hidden" name="redirectToUrl" value="<?php echo esc_url(admin_url('admin.php?page=avacy-plugin-settings')) ?>">
        <br>
        <section class="AvacySection AvacySection--Account">
            <div class="EditAccountPanel <?php echo !empty(get_option('avacy_webspace_id')) ? 'hidden' : ''?>">
                <sl-input name="avacy_tenant" placeholder="<?php echo __('Inserisci il nome del tuo team', 'avacy-wp')?>" size="small" value="<?php echo esc_attr(get_option('avacy_tenant')); ?>" required>
                    <label for="avacy_tenant" slot="label">
                        <span><?php echo __('Nome del Team Avacy', 'avacy-wp')?></span>
                    </label>
                    <sl-tooltip slot="suffix" content="<?php echo __('Inserisci il nome del Team a cui appartiene lo spazio web. Lo trovi in alto a destra in ogni pagina di Avacy.', 'avacy-wp') ?>" hoist>
                        <sl-icon name="info-circle"></sl-icon>
                    </sl-tooltip>
                </sl-input>
                <sl-input name="avacy_webspace_key" placeholder="<?php echo __('Inserisci la chiave specifica del tuo spazio web', 'avacy-wp')?>" size="small" value="<?php echo esc_attr(get_option('avacy_webspace_key')); ?>" required>
                    <label for="avacy_webspace_key" slot="label">
                        <span><?php echo __('Chiave Spazio Web', 'avacy-wp')?></span>
                    </label>
                    <sl-tooltip slot="suffix" content="<?php echo __('Inserisci la chiave identificativa dello spazio web che trovi alla fine della configurazione dello spazio web oppure nelle impostazioni dello spazio web, alla voce "Integrazione di Avacy sul tuo spazio web".', 'avacy-wp') ?>" hoist>
                        <sl-icon name="info-circle"></sl-icon>
                    </sl-tooltip>
                </sl-input>
                <input type="hidden" name="avacy_tenant" value="<?php echo esc_attr(get_option('avacy_tenant')); ?>">
                <input type="hidden" name="avacy_webspace_key" value="<?php echo esc_attr(get_option('avacy_webspace_key')); ?>">
            </div>
            <div class="RenderAccountPanel <?php echo empty(get_option('avacy_webspace_id')) ? 'hidden' : ''?>">
                <div class="AccountDetail"><span class="AccountDetail__Key"><?php echo __('Nome del Team Avacy', 'avacy-wp')?>:</span><span class="AccountDetail__Value"><?php echo esc_attr(get_option('avacy_tenant')); ?></span></div>
                <div class="AccountDetail"><span class="AccountDetail__Key"><?php echo __('Chiave Spazio Web', 'avacy-wp')?>:</span><span class="AccountDetail__Value"><?php echo esc_attr(get_option('avacy_webspace_key')); ?></span></div>
                <sl-button class="Edit" variant="text">
                    <sl-icon slot="prefix" name="pencil"></sl-icon>
                    <?php echo __('Modifica', 'avacy-wp'); ?>
                </sl-button>
            </div>
        </section>
        <?php if(!empty(get_option('avacy_webspace_id'))): ?>
        <section class="AvacySection AvacySection--Table">
            <?php
                $active_tab = get_transient("avacy_active_tab") ? get_transient("avacy_active_tab") : 'cookie-banner';
                delete_transient("avacy_active_tab");
            ?>
            <sl-tab-group>
                <sl-tab slot="nav" panel="cookie-banner" <?php echo $active_tab === 'cookie-banner'? 'active' : ''?>><?php echo __('Cookie banner', 'avacy-wp')?></sl-tab>
                <sl-tab slot="nav" panel="preemptive-block" <?php echo $active_tab === 'preemptive-block'? 'active' : ''?>><?php echo __('Blocco preventivo', 'avacy-wp')?></sl-tab>
                <sl-tab slot="nav" panel="consent-archive" <?php echo $active_tab === 'consent-archive'? 'active' : ''?>><?php echo __('Archivio consensi', 'avacy-wp')?></sl-tab>
                <input type="hidden" name="avacy_active_tab" value="<?php echo esc_attr($active_tab)?>">

                <sl-tab-panel name="cookie-banner" <?php echo $active_tab === 'cookie-banner'? 'active' : ''?>>
                    <div>
                        <?php $enabled = (get_option('avacy_show_banner') === 'on')? 'checked' : ''; ?>
                        <sl-checkbox name="avacy_show_banner" size="medium"  value="on" <?php echo esc_attr($enabled)?>><?php echo __('Mostra il cookie banner sul sito', 'avacy-wp') ?></sl-checkbox>
                        <div class="AvacyDescription">
                            <p>
                                <?php 
                                    $cookie_banner_href = $base_api_url.'/cookie-banner/'.get_option('avacy_tenant').'/'.get_option('avacy_webspace_key');
                                ?>
                                <?php echo __('Per modificare l\'aspetto del cookie banner', 'avacy-wp')?> <a href="<?php echo esc_attr($cookie_banner_href)?>" target="_blank"><?php echo __('clicca qui', 'avacy-wp') ?></a>
                            </p>
                        </div>
                    </div>
                </sl-tab-panel>
                <sl-tab-panel name="preemptive-block" <?php echo $active_tab === 'preemptive-block'? 'active' : ''?>>
                    <div>
                        <?php $enabled = (get_option('avacy_enable_preemptive_block') === 'on')? 'checked' : ''; ?>
                        <sl-checkbox name="avacy_enable_preemptive_block" size="medium"  value="on" <?php echo esc_attr($enabled)?>><?php echo __('Blocca preventivamente tutti gli script', 'avacy-wp')?></sl-checkbox>
                        <div class="AvacyDescription">
                            <p>
                                <?php 
                                    $vendors_href = $base_api_url.'/vendors/'.get_option('avacy_tenant').'/'.get_option('avacy_webspace_key');
                                ?>
                                <?php echo __('Ricorda di inserire su Avacy l\'URL degli script che vuoi bloccare per tutti', 'avacy-wp')?> <a href="<?php echo esc_attr($vendors_href)?>" target="_blank"><?php echo __('i tuoi fornitori', 'avacy-wp')?></a>.
                                <br>
                                <?php echo __('Per maggiori informazioni', 'avacy-wp')?> <a href="<?php echo esc_attr($documention_url)?>" target="_blank"><?php echo __('consulta la nostra guida', 'avacy-wp')?></a>. 
                            </p>
                        </div>
                    </div>
                </sl-tab-panel>
                <sl-tab-panel name="consent-archive" <?php echo $active_tab === 'consent-archive'? 'active' : ''?>>
                    <div class="AvacyDescription AvacyDescription--First">
                        <p>
                            <?php echo __('Imposta come Avacy registra i dati raccolti dal tuo spazio web nell’archivio consensi.', 'avacy-wp')?></a>.
                        </p>
                        <p>
                            <?php 
                                $consent_solution_href = $base_api_url.'/consent-solution/'.get_option('avacy_tenant').'/'.get_option('avacy_webspace_key');
                            ?>
                            <?php echo __('Nella sezione dell\'', 'avacy-wp')?><a href="<?php echo esc_attr($consent_solution_href)?>" target="_blank"><?php echo __('archivio consensi', 'avacy-wp')?></a>, <?php echo __('crea un nuovo token per collegare il servizio con Wordpress e inseriscilo nel campo sottostante', 'avacy-wp')?></a>.
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
        <sl-button class="Submit Submit--Global" variant="primary" type="submit" <?php echo !empty(get_option('avacy_webspace_id')) ? 'disabled' : ''?>>
            <?php 
                if( empty(get_option('avacy_webspace_id'))){
                    echo  __('Collega', 'avacy-wp');
                } else {
                    echo  __('Salva le modifiche', 'avacy-wp');
                }
            ?>
        </sl-button>
    </form>
</div>
