<?php
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly.
    }
    use Jumpgroup\Avacy\AddAdminInterface;

    global $api_base_url;
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
        <?php wp_nonce_field('avacy-plugin-settings-group-options'); ?>
        <input type="hidden" name="redirectToUrl" value="<?php echo esc_url(admin_url('admin.php?page=avacy-plugin-settings')) ?>">
        <br>
        <section class="AvacySection AvacySection--Account">
            <div class="EditAccountPanel <?php echo !empty(get_option('avacy_webspace_id')) ? 'hidden' : ''?>">
                <sl-input name="avacy_tenant" placeholder="<?php echo esc_html__('Enter the name of your team', 'avacy')?>" size="small" value="<?php echo esc_attr(get_option('avacy_tenant')); ?>" required>
                    <label for="avacy_tenant" slot="label">
                        <span><?php echo esc_html__('Avacy Team Name', 'avacy')?></span>
                    </label>
                    <sl-tooltip slot="suffix" content="<?php echo esc_html__('Enter the name of the team to which the webspace belongs. You can find it at the top right of every page on Avacy.', 'avacy') ?>" hoist>
                        <sl-icon name="info-circle"></sl-icon>
                    </sl-tooltip>
                </sl-input>
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
                <div class="AccountDetail"><span class="AccountDetail__Key"><?php echo esc_html__('Avacy Team Name', 'avacy')?>:</span><span class="AccountDetail__Value"><?php echo esc_attr(get_option('avacy_tenant')); ?></span></div>
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
                <sl-tab slot="nav" panel="consent-archive" <?php echo esc_attr($active_tab) === 'consent-archive'? 'active' : ''?>><?php echo esc_html__('Consent Solution', 'avacy')?></sl-tab>
                <input type="hidden" name="avacy_active_tab" value="<?php echo esc_attr($active_tab)?>">

                <sl-tab-panel name="cookie-banner" <?php echo esc_attr($active_tab) === 'cookie-banner'? 'active' : ''?>>
                    <div>
                        <?php $enabled = (get_option('avacy_show_banner') === 'on')? 'checked' : ''; ?>
                        <sl-checkbox name="avacy_show_banner" size="medium"  value="on" <?php echo esc_attr($enabled)?>><?php echo esc_html__('Show the cookie banner on the website', 'avacy') ?></sl-checkbox>
                        <div class="AvacyDescription">
                            <p>
                                <?php 
                                    $cookie_banner_href = $base_api_url.'/redirect/cookie-banner/'.get_option('avacy_tenant').'/'.get_option('avacy_webspace_key');
                                    echo wp_kses_post(sprintf(__('To modify the appearance of the cookie banner <a href=%s target="_blank">click here</a>.', 'avacy'),esc_url($cookie_banner_href)));
                                ?>
                            </p>
                        </div>
                    </div>
                </sl-tab-panel>
                <sl-tab-panel name="preemptive-block" <?php echo esc_attr($active_tab) === 'preemptive-block'? 'active' : ''?>>
                    <div>
                        <?php $enabled = (get_option('avacy_enable_preemptive_block') === 'on')? 'checked' : ''; ?>
                        <sl-checkbox name="avacy_enable_preemptive_block" size="medium"  value="on" <?php echo esc_attr($enabled)?>><?php echo esc_html__('Preemptively block all scripts.', 'avacy')?></sl-checkbox>
                        <div class="AvacyDescription">
                            <p>
                                <?php 
                                    $vendors_href = esc_url($base_api_url).'/redirect/vendors/'.get_option('avacy_tenant').'/'.get_option('avacy_webspace_key');
                                ?>
                                <?php /* echo esc_html__('Ricorda di inserire su Avacy l\'URL degli script che vuoi bloccare per tutti', 'avacy')?> <a href="<?php echo esc_attr($vendors_href)?>" target="_blank"><?php echo esc_html__('i tuoi fornitori', 'avacy') */?><!--</a>-->
                                <br>
                                <?php /* echo esc_html__('Per maggiori informazioni', 'avacy')?> <a href="<?php echo esc_attr($documention_url)?>" target="_blank"><?php echo esc_html__('consulta la nostra guida', 'avacy') */?><!--</a>-->
                            </p>
                        </div>
                    </div>
                </sl-tab-panel>
                <sl-tab-panel name="consent-archive" <?php echo esc_attr($active_tab) === 'consent-archive'? 'active' : ''?>>
                    <div class="AvacyDescription AvacyDescription--First">
                        <p>
                            <?php echo esc_html__('Set how Avacy records the data collected from your webspace in the consent archive.', 'avacy')?></a>
                        </p>
                        <p>
                            <?php 
                                $consent_solution_href = esc_url($base_api_url).'/redirect/consent-solution/'.get_option('avacy_tenant').'/'.get_option('avacy_webspace_key'); 
                                echo wp_kses_post(sprintf(__('In the <a href=%s target="_blank">consent archive section</a>, create a new token to link the service with WordPress and enter it in the field below.', 'avacy'), esc_url($consent_solution_href))); 
                            ?>
                        </p>
                    </div>

                    <section class="AvacySection AvacySection--Account">
                            <div class="AvacySection__InlineForm">
                                <sl-input name="avacy_api_token" placeholder="<?php echo esc_html__('Consent solution token', 'avacy')?>" size="small" value="<?php echo esc_attr(get_option('avacy_api_token')); ?>">
                                    <label for="avacy_api_token" slot="label">
                                        <span><?php echo esc_html__('Enter the token', 'avacy')?></span>
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
                                        <th><?php echo esc_html__('Form ID', 'avacy')?></th>
                                        <th><?php echo esc_html__('Type', 'avacy')?></th>
                                        <th><?php echo esc_html__('Save in the Avacy consent solution', 'avacy')?></th>
                                        <th><?php echo esc_html__('Select which data to save on Avacy', 'avacy')?></th>
                                        <th><?php echo esc_html__('User identification field', 'avacy')?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($forms as $form) : ?>
                                        <?php 
                                        // dd($form);
                                        $form_type = $form->getType();
                                        $type = strtolower(str_replace(' ', '_', $form_type));
                                        $id = $form->getId();
                                    ?>
                
                                    <tr data-form-id="avacy_<?php echo esc_attr($type) ?>_<?php echo esc_attr($id) ?>">
                                        <td><?php echo esc_attr($id); ?></td>
                                        <td><?php echo esc_attr($form_type); ?></td>
                                        <td>
                                            <div>
                                                <?php $enabled = esc_attr(get_option('avacy_' . esc_attr($type) . '_' . esc_attr($id) . '_radio_enabled') === 'on')? 'checked' : ''; ?>
                                                <sl-switch name="avacy_<?php echo esc_attr($type) ?>_<?php echo esc_attr($id) ?>_radio_enabled" <?php echo esc_attr($enabled) ?> size="medium">
                                                    <label for="avacy_<?php echo esc_attr($type) ?>_<?php echo esc_attr($id) ?>_radio_enabled"><?php echo esc_html__('Save', 'avacy')?></label>
                                                </sl-switch>
                                            </div>
                                        </td>
                                        <td>
                                            <sl-details summary=<?php echo esc_attr(__('Details', 'avacy'))?>>
                                                <sl-icon slot="collapse-icon" name="caret-left-fill"></sl-icon>
                                                <sl-icon slot="expand-icon" name="caret-down-fill"></sl-icon>
                                                <?php foreach ($form->getFields() as $field) : ?>
                                                    <?php $checked = esc_attr(get_option('avacy_form_field_' . esc_attr($field['type']) . '_' . esc_attr($id) . '_' . esc_attr($field['name'])) === 'on')? 'checked' : '';?>
                                                    <sl-checkbox size="small" name="avacy_form_field_<?php echo esc_attr($field['type'])?>_<?php echo esc_attr($id) ?>_<?php echo esc_attr($field['name'])?>" <?php echo esc_attr($checked) ?>><?php echo esc_attr($field['name'])?></sl-checkbox>
                                                <?php endforeach; ?>
                                            </sl-details>
                                        </td>
                                        <td>
                                            <sl-select name="avacy_<?php echo esc_attr($type) ?>_<?php echo esc_attr($id) ?>_form_user_identifier" id="<?php echo esc_attr($type)  ?>_<?php echo esc_attr($id) ?>_form_user_identifier" value="<?php echo esc_attr(get_option('avacy_' . esc_attr($type)  . '_' . esc_attr($id)  . '_form_user_identifier'))?>" size="small" placeholder="Select an option">
                                                <?php foreach ($form->getFields() as $key => $field) : ?>
                                                    <sl-option name="avacy_form_option_<?php echo esc_attr($key)?>_<?php echo esc_attr($field['type'])?>_<?php echo esc_attr($id) ?>" value="<?php echo esc_attr($field['name'])?>"><?php echo esc_attr($field['name'])?></sl-option>
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
                    echo esc_html__('Connect', 'avacy');
                } else {
                    echo esc_html__('Save changes', 'avacy');
                }
            ?>
        </sl-button>
    </form>
</div>
