<?php
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly.
    }
    use Jumpgroup\Avacy\AddAdminInterface;
    use Jumpgroup\Avacy\PreemptiveBlock;
    use Jumpgroup\Avacy\SendFormsToConsentSolution;
    use Jumpgroup\Avacy\BannerVersionGate;
    use Jumpgroup\Avacy\ConsentFeatures;
    use Jumpgroup\Avacy\Hosts;

    $api_base_url = Hosts::api();
    // esacape the base api url
    $base_api_url = esc_attr($api_base_url . '/wp');
    $registration_url = esc_attr('https://avacy.eu/registration');
    $documention_url = esc_attr('https://docs.avacysolution.com');

    // dd($_GET);
?>
    
<sl-spinner class="AvacyLoader"></sl-spinner>
<div class="wrap hide">
    <?php
        $actionUrl = admin_url('admin-post.php');
    ?>
    <form id="avacy-form" method="post" action="<?php echo esc_url($actionUrl) ?>">
        <?php if( empty(get_option('avacy_webspace_id'))) : ?>
            <h1><?php echo esc_html__('Connect to Avacy', 'avacy')?></h1>
            <sl-alert variant="warning" open closable class="alert-closable">
                <sl-icon slot="icon" name="exclamation-triangle"></sl-icon>
                <strong><?php echo esc_html__('Warning!', 'avacy')?></strong><br />
                <?php echo wp_kses_post(sprintf(__('To use the plugin, you need to have an Avacy account. If you are not registered yet, you can do so <a href=%s target="_blank">here</a>.', 'avacy'), esc_url($registration_url))); ?>
            </sl-alert>
        <?php endif; ?>
        <?php if( !empty(get_option('avacy_webspace_id'))) : ?>
            <h1><?php echo esc_html__('Connected to Avacy', 'avacy')?></h1>
        <?php endif; ?>

        <?php
        // /wp-content/plugins/ct-wp-admin-form/views/alerts.php
        $form_errors = get_transient("settings_errors");
        delete_transient("settings_errors");
        if(!empty($form_errors)){
            foreach($form_errors as $error){ ?>
                    <sl-alert variant="<?php echo esc_attr($error['type']) ?>" open closable class="alert-closable">
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
                        <?php echo esc_attr($error['message'])?><br />
                    </sl-alert>
            <?php }
        }
        ?>


        <input type="hidden" name="action" value="avacy_admin_save">
        <input type="hidden" name="option_page" value="avacy-plugin-settings-group">
        <?php wp_nonce_field(AddAdminInterface::SETTINGS_NONCE); ?>
        <input type="hidden" name="redirectToUrl" value="<?php echo esc_url(admin_url('admin.php?page=avacy-plugin-settings')) ?>">
        <br>
        <section class="AvacySection AvacySection--Account">
            <div class="EditAccountPanel <?php echo !empty(get_option('avacy_webspace_id')) ? 'hidden' : ''?>">
                <!-- <sl-input name="avacy_tenant" placeholder="<?php echo esc_html__('Enter the name of your team', 'avacy')?>" size="small" value="<?php echo esc_attr(get_option('avacy_tenant')); ?>" required>
                    <label for="avacy_tenant" slot="label">
                        <span><?php echo esc_html__('Avacy Team Name', 'avacy')?></span>
                    </label>
                    <sl-tooltip slot="suffix" content="<?php echo esc_html__('Enter the name of the team to which the webspace belongs. You can find it at the top right of every page on Avacy.', 'avacy') ?>" hoist>
                        <sl-icon name="info-circle"></sl-icon>
                    </sl-tooltip>
                </sl-input> -->
                <sl-input name="avacy_webspace_key" placeholder="<?php echo esc_html__('Enter the specific key of your webspace', 'avacy')?>" size="small" value="<?php echo esc_attr(get_option('avacy_webspace_key')); ?>" required>
                    <label for="avacy_webspace_key" slot="label">
                        <span><?php echo esc_html__('Webspace Key', 'avacy')?></span>
                    </label>
                    <sl-tooltip slot="suffix" content="<?php echo esc_html__('Enter the webspace key that you find at the end of the webspace configuration or in the webspace settings under "Avacy integration on your webspace".', 'avacy') ?>" hoist>
                        <sl-icon name="info-circle"></sl-icon>
                    </sl-tooltip>
                </sl-input>
                <input type="hidden" name="avacy_tenant" value="<?php echo esc_attr(get_option('avacy_tenant')); ?>">
                <input type="hidden" name="avacy_webspace_key" value="<?php echo esc_attr(get_option('avacy_webspace_key')); ?>">
            </div>
            <div class="RenderAccountPanel <?php echo empty(get_option('avacy_webspace_id')) ? 'hidden' : ''?>">
                <!-- <div class="AccountDetail"><span class="AccountDetail__Key"><?php echo esc_html__('Avacy Team Name', 'avacy')?>:</span><span class="AccountDetail__Value"><?php echo esc_attr(get_option('avacy_tenant')); ?></span></div> -->
                <div class="AccountDetail"><span class="AccountDetail__Key"><?php echo esc_html__('Webspace Key', 'avacy')?>:</span><span class="AccountDetail__Value"><?php echo esc_attr(get_option('avacy_webspace_key')); ?></span></div>
                <sl-button class="Edit" variant="text">
                    <sl-icon slot="prefix" name="pencil"></sl-icon>
                    <?php echo esc_html__('Edit', 'avacy'); ?>
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
                <sl-tab slot="nav" panel="cookie-banner" <?php echo esc_attr($active_tab) === 'cookie-banner'? 'active' : ''?>><?php echo esc_html__('Cookie banner', 'avacy')?></sl-tab>
                <sl-tab slot="nav" panel="preemptive-block" <?php echo esc_attr($active_tab) === 'preemptive-block'? 'active' : ''?>><?php echo esc_html__('Preemptive Block', 'avacy')?></sl-tab>
                <sl-tab slot="nav" panel="consent-archive" <?php echo esc_attr($active_tab) === 'consent-archive'? 'active' : ''?>><?php echo esc_html__('Consent Archive', 'avacy')?></sl-tab>
                <input type="hidden" name="avacy_active_tab" value="<?php echo esc_attr($active_tab)?>">

                <sl-tab-panel name="cookie-banner" <?php echo esc_attr($active_tab) === 'cookie-banner'? 'active' : ''?>>
                    <div>
                        <?php $enabled = (get_option('avacy_show_banner') === 'on')? 'checked' : ''; ?>
                        <sl-checkbox name="avacy_show_banner" size="medium"  value="on" <?php echo esc_attr($enabled)?>><?php echo esc_html__('Show the cookie banner on the website', 'avacy') ?></sl-checkbox>
                        <div class="AvacyDescription">
                            <p>
                                <?php
                                    $isKeyInOldFormat = strpos(get_option('avacy_webspace_key'), '|') === false;
                                    if( $isKeyInOldFormat) {
                                        $webspaceKey = get_option('avacy_webspace_key');
                                    } else {
                                        $accountToken = explode('|', get_option('avacy_webspace_key'));
                                        $webspaceKey = $accountToken[1];
                                    }
                                    $cookie_banner_href = $base_api_url.'/redirect/cookie-banner/'.get_option('avacy_tenant').'/'.$webspaceKey;
                                    echo wp_kses_post(sprintf(__('To modify the appearance of the cookie banner <a href=%s target="_blank">click here</a>.', 'avacy'),esc_url($cookie_banner_href)));
                                ?>
                            </p>
                            <?php
                                // Lo stesso valore che decide quale banner viene caricato,
                                 // riscritto a ogni apertura di questa pagina. Vuoto solo se il
                                 // SaaS non ha mai risposto.
                                $bannerVersion = BannerVersionGate::current();
                            ?>
                            <p>
                                <?php echo esc_html__('Current banner version:', 'avacy'); ?>
                                <strong><?php echo esc_html($bannerVersion !== '' ? $bannerVersion : '—'); ?></strong>
                            </p>
                        </div>
                    </div>
                </sl-tab-panel>
                <sl-tab-panel name="preemptive-block" <?php echo esc_attr($active_tab) === 'preemptive-block'? 'active' : ''?>>
                    <div>
                        <?php $enabled = (get_option('avacy_enable_preemptive_block') === 'on')? 'checked' : ''; ?>
                        <sl-checkbox name="avacy_enable_preemptive_block" size="medium"  value="on" <?php echo esc_attr($enabled)?>><?php echo esc_html__('Preemptively block all scripts.', 'avacy')?></sl-checkbox>
                        <?php // #667: stesse condizioni di avacy.php, senza banner nessuno sblocca gli script ?>
                        <?php if (empty(get_option('avacy_show_banner')) && !empty(get_option('avacy_enable_preemptive_block'))): ?>
                            <p class="AvacyBannerOff">
                                <sl-icon name="exclamation-triangle"></sl-icon>
                                <span><?php echo esc_html__('The cookie banner is turned off. Blocked scripts only run if the cookie banner is installed another way, for example with Google Tag Manager. If you removed the cookie banner from the site, turn off the preemptive block too.', 'avacy'); ?></span>
                            </p>
                        <?php endif; ?>
                        <div class="AvacyDescription">
                            <p>
                                <?php
                                    // `avacy_webspace_key` porta `tenant|uuid` da quando le due
                                    // informazioni si salvano insieme: senza lo split il link
                                    // finirebbe su .../tenant/tenant|uuid. Stesso trattamento che
                                    // riceve nella scheda Consent Archive.
                                    $vendorsKey = get_option('avacy_webspace_key');
                                    if (strpos($vendorsKey, '|') !== false) {
                                        [$vendorsTenant, $vendorsKey] = explode('|', $vendorsKey);
                                    } else {
                                        $vendorsTenant = get_option('avacy_tenant');
                                    }
                                    $vendors_href = esc_url($base_api_url).'/redirect/vendors/'.$vendorsTenant.'/'.$vendorsKey;
                                ?>
                                <?php /* echo esc_html__('Ricorda di inserire su Avacy l\'URL degli script che vuoi bloccare per tutti', 'avacy')?> <a href="<?php echo esc_attr($vendors_href)?>" target="_blank"><?php echo esc_html__('i tuoi fornitori', 'avacy') */?><!--</a>-->
                                <br>
                                <?php /* echo esc_html__('Per maggiori informazioni', 'avacy')?> <a href="<?php echo esc_attr($documention_url)?>" target="_blank"><?php echo esc_html__('consulta la nostra guida', 'avacy') */?><!--</a>-->
                            </p>
                        </div>
                        <?php
                            // Regole e pulsante solo col blocco acceso (10d). La casella si
                            // legge al salvataggio, quindi il riquadro compare dal caricamento
                            // dopo, non al clic sulla spunta.
                            if (!empty(get_option('avacy_enable_preemptive_block'))):
                                $rulesCount = PreemptiveBlock::getBlackListCount();
                                $lastRefresh = PreemptiveBlock::getLastRefreshTimestamp();
                                // link vestito da bottone: è admin-post.php, non un submit dentro il form (10d)
                                $refreshUrl = wp_nonce_url(admin_url('admin-post.php?action=avacy_refresh_vendor_list'), 'avacy_refresh_vendor_list');
                        ?>
                        <div class="AvacyDescription">
                            <p>
                                <?php if ($lastRefresh > 0): ?>
                                    <?php
                                        /* translators: %s: human-readable time difference, e.g. "2 hours". */
                                        echo esc_html(sprintf(__('Updated %s ago', 'avacy'), human_time_diff($lastRefresh, time())));
                                    ?>
                                <?php else: ?>
                                    <?php echo esc_html__('Not updated yet', 'avacy'); ?>
                                <?php endif; ?>
                            </p>
                            <?php if ($lastRefresh > 0 && $rulesCount === 0): ?>
                                <?php /* Zero regole e' un esito valido, non un errore: il conteggio
                                   si dice solo qui, dove indica dove guardare. */ ?>
                                <p>
                                    <?php echo esc_html__('No vendors to block.', 'avacy'); ?>
                                    <a href="<?php echo esc_url($vendors_href); ?>" target="_blank"><?php echo esc_html__('Check your vendors on Avacy', 'avacy'); ?></a>
                                </p>
                            <?php endif; ?>
                            <div class="AvacyRefresh">
                                <sl-button href="<?php echo esc_url($refreshUrl); ?>" size="small"><?php echo esc_html__('Refresh the vendor list', 'avacy'); ?></sl-button>
                                <?php if (PreemptiveBlock::lastRefreshFailed()): ?>
                                    <span class="AvacyRefreshFailed">
                                        <sl-icon name="exclamation-triangle"></sl-icon>
                                        <?php echo esc_html__('The last update failed. Please try again later.', 'avacy'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </sl-tab-panel>
                <sl-tab-panel name="consent-archive" <?php echo esc_attr($active_tab) === 'consent-archive'? 'active' : ''?>>
                    <div class="AvacyDescription AvacyDescription--First">
                        <p>
                            <?php echo esc_html__('Set how Avacy records the data collected from your webspace in the consent archive.', 'avacy')?></a>
                        </p>
                        <p>
                            <?php
                                $webspaceKey = get_option('avacy_webspace_key');
                                if(strpos($webspaceKey, '|') !== false) {
                                    $keyParts = explode('|', $webspaceKey);
                                    $tenant = $keyParts[0];
                                    $webspaceKey = $keyParts[1];
                                } else {
                                    $tenant = get_option('avacy_tenant');
                                }
                                $consent_solution_href = esc_url($base_api_url).'/redirect/consent-solution/'.$tenant.'/'.$webspaceKey.'?tab=access-keys';
                            
                                echo wp_kses_post(sprintf(__('In the <a href=%s target="_blank">consent archive section</a>, create a new Access Key to link the service with WordPress and enter it in the field below.', 'avacy'), esc_url($consent_solution_href))); 
                            ?>
                        </p>
                    </div>

                    <section class="AvacySection AvacySection--Account">
                            <div class="AvacySection__InlineForm">
                                <sl-input name="avacy_api_token" placeholder="<?php echo esc_html__('Consent Archive Access Key', 'avacy')?>" size="small" value="<?php echo esc_attr(get_option('avacy_api_token')); ?>">
                                    <label for="avacy_api_token" slot="label">
                                        <span><?php echo esc_html__('Enter the Access Key', 'avacy')?></span>
                                    </label>
                                    <sl-tooltip slot="suffix" content="<?php echo esc_html__('Create the Access Key in Avacy: open the webspace settings, then "Avacy integration on your webspace", tab "Access Key". Only the last key you create works.', 'avacy') ?>" hoist>
                                        <sl-icon name="info-circle"></sl-icon>
                                    </sl-tooltip>
                                </sl-input>
                            </div>
                    </section>

                    <?php if( !empty(get_option('avacy_api_token'))): ?>
                    <?php $forms = AddAdminInterface::detectAllForms(); ?>
                    <?php $consentFeatures = ConsentFeatures::active(); ?>
                        <section class="AvacySection AvacySection--Table">
                            <?php // saveFields() salva i form solo se la tabella era nella pagina ?>
                            <input type="hidden" name="<?php echo esc_attr(AddAdminInterface::FORMS_TABLE_FIELD) ?>" value="1">
                            <div class="AvacyForms__Scroll">
                            <?php // Un blocco per form: ogni controllo ha la sua etichetta, niente intestazioni di colonna lontane ?>
                            <table class="AvacyForms">
                                <?php foreach ($forms as $form) : ?>
                                    <?php
                                    $form_type = $form->getType();
                                    $type = strtolower(str_replace(' ', '_', $form_type));
                                    $id = $form->getId();
                                    $enabledOption = SendFormsToConsentSolution::enabledOptionName($form_type, $id);
                                    $enabled = SendFormsToConsentSolution::isEnabled($form_type, $id) ? 'checked' : '';
                                    ?>
                                <tbody class="AvacyForms__Group" id="avacy-form-<?php echo esc_attr($type) ?>_<?php echo esc_attr($id) ?>" data-form-id="avacy_<?php echo esc_attr($type) ?>_<?php echo esc_attr($id) ?>">
                                    <tr class="AvacyForms__Row">
                                        <td class="AvacyForms__Form">
                                            <span class="AvacyForms__Title"><?php echo esc_html($form->getName()); ?></span>
                                            <span class="AvacyForms__Meta"><?php echo esc_html($form->getTypeLabel()); ?> · ID <?php echo esc_html($id); ?></span>
                                        </td>
                                        <td class="AvacyForms__Save">
                                            <sl-switch name="<?php echo esc_attr($enabledOption) ?>" <?php echo esc_attr($enabled) ?> size="medium">
                                                <?php // testo nello slot dello switch: una <label> annidata assorbiva il clic ?>
                                                <?php echo esc_html__('Send to Consent Archive', 'avacy')?>
                                            </sl-switch>
                                        </td>
                                    </tr>
                                    <tr class="AvacyForms__Data">
                                        <td colspan="2">
                                            <div class="AvacyData">
                                                <div class="AvacyData__Identifier">
                                                    <div class="AvacyForms__Label"><?php echo esc_html__('User identification field', 'avacy')?></div>
                                                    <sl-select name="avacy_<?php echo esc_attr($type) ?>_<?php echo esc_attr($id) ?>_form_user_identifier" id="<?php echo esc_attr($type)  ?>_<?php echo esc_attr($id) ?>_form_user_identifier" value="<?php echo esc_attr(str_replace(' ', '_', (string) get_option('avacy_' . esc_attr($type)  . '_' . esc_attr($id)  . '_form_user_identifier')))?>" size="small" placeholder="Select an option" hoist>
                                                        <?php foreach ($form->getFields() as $key => $field) : ?>
                                                            <sl-option name="avacy_form_option_<?php echo esc_attr($key)?>_<?php echo esc_attr($field['type'])?>_<?php echo esc_attr($id) ?>" value="<?php echo esc_attr(str_replace(' ', '_', $field['name']))?>"><?php echo esc_attr($field['name'])?></sl-option>
                                                        <?php endforeach; ?>
                                                    </sl-select>
                                                    <?php if (AddAdminInterface::hasMissingIdentifier($form)) : ?>
                                                        <div class="AvacyFeatureWarning AvacyFeatureWarning--Identifier"><?php echo esc_html__('The saved identifier field is no longer in the form. Choose another one.', 'avacy') ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="AvacyData__Fields">
                                                    <div class="AvacyForms__Label"><?php echo esc_html__('Select which data to save on Avacy', 'avacy')?></div>
                                                    <div class="AvacyData__Checkboxes">
                                                        <?php foreach ($form->getFields() as $field) : ?>
                                                            <?php // lo stesso nome dell'opzione di saveFields: PHP trasforma gli spazi dei nomi inviati in trattini bassi ?>
                                                            <?php $fieldOption = 'avacy_form_field_' . $field['type'] . '_' . $id . '_' . str_replace(' ', '_', $field['name']); ?>
                                                            <?php $checked = get_option($fieldOption) === 'on' ? 'checked' : '';?>
                                                            <sl-checkbox size="small" name="<?php echo esc_attr($fieldOption) ?>" <?php echo esc_attr($checked) ?>><?php echo esc_attr($field['name'])?></sl-checkbox>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php // Le caratteristiche in una riga sotto il form: la tabella non si allarga ?>
                                    <tr class="AvacyForms__Features">
                                        <td colspan="2">
                                        <div class="AvacyForms__Label"><?php echo esc_html__('Consent features', 'avacy') ?></div>
                                        <div class="AvacyFeatures">
                                        <?php foreach ($consentFeatures as $feature) : ?>
                                            <?php
                                                // Quale casella (o l'invio) accetta questa caratteristica. L'avviso e' solo informativo.
                                                $featureOption = ConsentFeatures::optionName($form_type, $id, $feature['key']);
                                                $featureChoice = ConsentFeatures::choice($form_type, $id, $feature);
                                                $featureWarning = '';
                                                if (ConsentFeatures::isMissingField($featureChoice, $form->getCheckboxes())) {
                                                    $featureWarning = ConsentFeatures::missingText();
                                                }
                                                foreach ($form->getCheckboxes() as $box) {
                                                    if ($featureChoice === ConsentFeatures::fieldChoice($box['key'])) {
                                                        $mismatch = ConsentFeatures::mismatch($feature['field'], $box['required']);
                                                        $featureWarning = $mismatch !== null ? ConsentFeatures::mismatchText($mismatch) : '';
                                                    }
                                                }
                                            ?>
                                            <div class="AvacyFeature">
                                                <sl-select label="<?php echo esc_attr($feature['label']) ?>" name="<?php echo esc_attr($featureOption) ?>" value="<?php echo esc_attr($featureChoice) ?>" size="small" hoist>
                                                    <sl-option value="<?php echo esc_attr(ConsentFeatures::plainChoice($feature)) ?>"><?php echo $feature['ack'] ? esc_html__('Acknowledged', 'avacy') : esc_html__('No field', 'avacy') ?></sl-option>
                                                    <?php foreach ($form->getCheckboxes() as $box) : ?>
                                                        <sl-option value="<?php echo esc_attr(ConsentFeatures::fieldChoice($box['key'])) ?>" data-required="<?php echo esc_attr($box['required'] === null ? '' : (int) $box['required']) ?>"><?php echo esc_html($box['name']) ?></sl-option>
                                                    <?php endforeach; ?>
                                                </sl-select>
                                                <div class="AvacyFeatureWarning" data-field="<?php echo esc_attr($feature['field']) ?>"
                                                    data-text-optional-box="<?php echo esc_attr(ConsentFeatures::mismatchText('optional_box')) ?>"
                                                    data-text-required-box="<?php echo esc_attr(ConsentFeatures::mismatchText('required_box')) ?>"
                                                    <?php echo $featureWarning === '' ? 'hidden' : '' ?>><?php echo esc_html($featureWarning) ?></div>
                                                <?php if ($feature['key'] === 'tracking_pixel') : ?>
                                                    <div class="AvacyFeatureWarning AvacyFeatureWarning--Pixel" <?php echo ConsentFeatures::pixelSharesField($form_type, $id) ? '' : 'hidden' ?>><?php echo esc_html(ConsentFeatures::pixelText()) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        </div>
                                        </td>
                                    </tr>
                                </tbody>
                                <?php endforeach; ?>
                        </table>
                        </div>
                    </section>
                    <?php endif; ?>
                </sl-tab-panel>
            </sl-tab-group>
        </section>
        <?php endif; ?>
        <sl-button class="Submit Submit--Global" variant="primary" type="submit" <?php echo !empty(get_option('avacy_webspace_id')) ? 'disabled' : ''?>>
            <?php 
                if( empty(get_option('avacy_webspace_id'))){
                    echo esc_html__('Connect', 'avacy');
                } else {
                    echo esc_html__('Save changes', 'avacy');
                }
            ?>
        </sl-button>
        <p class="AvacySubmitHint" hidden>
            <?php
            /* translators: %s: link to the Consent Archive tab. */
            printf(esc_html__('To save, complete the highlighted forms in the %s tab: choose the user identification field and at least one data field.', 'avacy'), '<a href="#">' . esc_html__('Consent Archive', 'avacy') . '</a>');
            ?>
        </p>
    </form>
</div>
