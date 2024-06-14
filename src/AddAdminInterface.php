<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Jumpgroup\Avacy\Integrations\ContactForm7;
use Jumpgroup\Avacy\Integrations\HtmlForms;
use Jumpgroup\Avacy\Integrations\ElementorForms;
use Jumpgroup\Avacy\Integrations\WooCommerceCheckoutForm;
use Jumpgroup\Avacy\Integrations\WpForms;

class AddAdminInterface
{
  public static function init()
  {
    add_action( 'admin_post_avacy_admin_save', [static::class, 'AvacyAdminSave'] );
    add_action('admin_enqueue_scripts', [static::class, 'enqueueShoelace']);
    add_action('admin_menu', [static::class, 'registerAvacyDashicon']);
    add_action('admin_menu', [static::class, 'addMenuPage']);
    add_action('admin_init', [static::class, 'registerSettings']);
    add_action('admin_init', [static::class, 'saveFields']);

    // add action on plugin page load
    add_action('load-toplevel_page_avacy-plugin-settings', [static::class, 'checkDataOnPageLoad']);
  }

  public static function checkDataOnPageLoad() {
    $redirect_to = esc_url(admin_url('admin.php?page=avacy-plugin-settings'));
    $tenant = get_option('avacy_tenant');
    $webspaceKey = get_option('avacy_webspace_key');
    $webspaceId = get_option('avacy_webspace_id');
    $apiToken = get_option('avacy_api_token');
    $checkSaasAccount = self::checkSaasAccount($tenant, $webspaceKey);
    

    if(empty($webspaceId) ){
      return;
    }

    if (empty($checkSaasAccount) || ( !empty($checkSaasAccount) && $checkSaasAccount['status'] === 200) ) {
      if(empty($apiToken)){
        return;
      }
      $checkConsentSolutionToken = self::checkConsentSolutionToken($apiToken);
      if (empty($checkConsentSolutionToken) || ( !empty($checkConsentSolutionToken) && $checkConsentSolutionToken['status'] === 200) ) {
        return;
      }

      // dd('ciao', $checkConsentSolutionToken, $apiToken, $checkSaasAccount);
      if ($checkConsentSolutionToken['status'] !== 200) {
        $notices[] = $checkConsentSolutionToken['notice'];
        update_option('avacy_api_token', '');
        set_transient('avacy_active_tab', 'consent-archive', 30);
        // dd('ciao');
      }
    } 

    
    if (!empty($checkSaasAccount) && $checkSaasAccount['status'] !== 200) {
      $notices[] = $checkSaasAccount['notice'];
      update_option('avacy_tenant', '');
      update_option('avacy_webspace_key', '');
      update_option('avacy_webspace_id', '');
      update_option('avacy_api_token', '');
    }

    if (!empty($notices)) {
      foreach ($notices as $notice) {
        if (!empty($notice)) {
          add_settings_error(
            $notice[0],
            $notice[1],
            $notice[2],
            $notice[3]
          );
        }
      }
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    $form_errors = get_transient("settings_errors");
    wp_safe_redirect($redirect_to);
    exit;
  }
  
  public static function AvacyAdminSave() {
    if ( !isset($_REQUEST['_wpnonce']) || !wp_verify_nonce( $_REQUEST['_wpnonce'], 'avacy-plugin-settings-group-options' ) ) {
      die( 'Security check' ); 
    } 
    $redirect_to = isset($_POST['redirectToUrl']) ? esc_url($_POST['redirectToUrl']) : '';
    $tenant = isset($_POST['avacy_tenant']) ? sanitize_text_field($_POST['avacy_tenant']) : '';
    $webspaceKey = isset($_POST['avacy_webspace_key']) ? sanitize_text_field($_POST['avacy_webspace_key']) : '';
    $apiToken = isset($_POST['avacy_api_token']) ? sanitize_text_field($_POST['avacy_api_token']) : '';
    $showBanner = isset($_POST['avacy_show_banner']) ? sanitize_text_field($_POST['avacy_show_banner']) : '';
    $enablePreemptiveBlock = isset($_POST['avacy_enable_preemptive_block']) ? sanitize_text_field($_POST['avacy_enable_preemptive_block']) : '';
    $activeTab = isset($_POST['avacy_active_tab']) ? sanitize_text_field($_POST['avacy_active_tab']) : '';
    $notices = [];

    $warning_error = [
      'avacy_account',
      'account_not_found',
      __('Attenzione! Per utilizzare il plugin è necessario avere un account Avacy. Se non sei ancora registrato, puoi farlo qui.', 'avacy'),
      'warning'
    ];

    if (empty($tenant) || empty($webspaceKey)) {
      add_settings_error($warning_error);
      set_transient('settings_errors', get_settings_errors(), 30);
      wp_safe_redirect($redirect_to);
      exit;
    }

    $can_update = true;

    $checkSaasAccount = self::checkSaasAccount($tenant, $webspaceKey);
    if (!empty($checkSaasAccount)) {
      $notices[] = $checkSaasAccount['notice'];
    } else if (!empty($checkSaasAccount) && $checkSaasAccount['status'] !== 200) {
      $can_update = false;
    }

    $checkConsentSolutionToken = self::checkConsentSolutionToken($apiToken);
    if (!empty($checkConsentSolutionToken)) {
      $notices[] = $checkConsentSolutionToken['notice'];
    } else if (!empty($checkConsentSolutionToken) && $checkConsentSolutionToken['status'] !== 200) {
      $can_update = false;
    }

    if (!empty($can_update) && isset($_POST['avacy_active_tab'])) {
      update_option('avacy_show_banner', esc_attr($showBanner));
      update_option('avacy_enable_preemptive_block', esc_attr($enablePreemptiveBlock));
  
      $notices[] = [
        'avacy_settings',
        'settings_saved',
        __('Le modifiche sono state salvate correttamente.', 'avacy'),
        'success'
      ];
    }

    // For each notice, add a settings error
    foreach ($notices as $notice) {
      if (!empty($notice)) {
        add_settings_error(
          $notice[0],
          $notice[1],
          $notice[2],
          $notice[3]
        );
      }
    }
    set_transient('settings_errors', get_settings_errors(), 30);
    set_transient('avacy_active_tab', $activeTab, 30);
    wp_safe_redirect($redirect_to);
    exit;
  }

  private static function checkSaasAccount($tenant, $webspaceKey) {
    $option_tenant = get_option('avacy_tenant');
    $option_webspace_key = get_option('avacy_webspace_key');
    
    $endpoint = 'https://api.avacy.eu/wp/validate/' . $tenant . '/' . $webspaceKey;

    $response = wp_remote_get($endpoint);
    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    $setting = '';
    $code = '';
    $message = '';
    $type = '';

    if ($status_code !== 200) {
      $error_code = $data['message']['error'] ?? 'team_not_found';
      switch ($error_code) {
        case 'team_not_found':
          $setting = 'avacy_team';
          $code = 'team_not_found';
          $message = __('Team non trovato. Controlla i dati inseriti.', 'avacy');
          $type = 'danger';
          break;
        
        case 'webspace_not_found':
          $setting = 'avacy_webspace';
          $code = 'webspace_not_found';
          $message = __('Webspace non trovato. Controlla i dati inseriti.', 'avacy');
          $type = 'danger';
          break;
      }
    } else {
      if ($tenant === $option_tenant && $webspaceKey === $option_webspace_key) {
        return [];
      }

      $webspaceId = $data['id'];
      if (!empty($tenant)) {
        update_option('avacy_tenant', esc_attr($tenant));
      }
      if (!empty($webspaceKey)) {
        update_option('avacy_webspace_key', esc_attr($webspaceKey));
      }
      if (!empty($webspaceId)) {
        update_option('avacy_webspace_id', esc_attr($webspaceId));
      }
      
      $setting = 'avacy_tenant';
      $code = 'tenant_found';
      $message = __('Le credenziali inserite sono valide.', 'avacy');
      $type = 'success';

    }

    return [
      'status' => $status_code,
      'notice' => [
        $setting,
        $code,
        $message,
        $type
      ]
    ];
  }

  public static function checkConsentSolutionToken($apiToken) {
    $option_api_token = get_option('avacy_api_token');

    $setting = '';
    $code = '';
    $message = '';
    $type = '';
    
    if (!empty($apiToken)) {
      $option_tenant = get_option('avacy_tenant');
      $option_webspace_key = get_option('avacy_webspace_key');

      $endpoint = 'https://api.avacy.eu/wp/validate/' . $option_tenant . '/' . $option_webspace_key . '/' . $apiToken;

      $response = wp_remote_get($endpoint);

      $status_code = wp_remote_retrieve_response_code($response);
      $body = wp_remote_retrieve_body($response);
      $data = json_decode($body, true);
  
      if ($status_code !== 200) {
        $error_code = $data['message']['error'] ?? 'token_not_found_or_expired';
        switch ($error_code) {
          case 'invalid_token':
            $setting = 'avacy_api_token';
            $code = 'invalid_token';
            $message = __('Il token inserito ha un formato non corretto.', 'avacy');
            $type = 'warning';
            break;

          case 'token_not_found_or_expired':
            $setting = 'avacy_api_token';
            $code = 'token_not_found_or_expired';
            $message = __('Token non trovato o scaduto. Controlla i dati inseriti.', 'avacy');
            $type = 'danger';
            break;
        }
      } else {
        if ($apiToken === $option_api_token) {
          return [];
        }
        update_option('avacy_api_token', esc_attr($apiToken));
        
        $setting = 'avacy_api_token';
        $code = 'valid_token';
        $message = __('Il token è stato salvato correttamente.', 'avacy');
        $type = 'success';
      }
    } else {
      if (!empty($option_api_token)) {
        update_option('avacy_api_token', '');
  
        $setting = 'avacy_api';
        $code = 'remove_token';
        $message = __('Il token è stato rimosso correttamente', 'avacy');
        $type = 'success';
        $status_code = 200;
      } else {
        return [];
      }
    }

    return [
      'status' => $status_code,
      'notice' => [
        $setting,
        $code,
        $message,
        $type
      ]
    ];
  }

  public static function registerAvacyDashicon()
  {
    wp_enqueue_style( 'avacy-dashicon', plugins_url( '/../styles/avacy-dashicon.css', __FILE__ ), array('dashicons'), '1.0', 'screen');
  }

  public static function enqueueShoelace() {
    // register cdn for shoelace
    wp_register_script(
      'shoelace-autoloader',
      plugins_url( '/../assets/libraries/@shoelace-style/shoelace/cdn/shoelace-autoloader.js', __FILE__ ),
      array(),
      '2.15.0',
  );
  
    // register css shoelace cdn
    wp_register_style(
        'shoelace-light',
        plugins_url( '/../assets/libraries/@shoelace-style/shoelace/cdn/themes/light.css', __FILE__ ),
        array(),
        '2.15.0',
        'screen'
    );
    wp_enqueue_script('shoelace-autoloader');
    wp_enqueue_style('shoelace-light');

    //add_filter to add type module to script tag shoelace-autoloader
    add_filter('script_loader_tag', function($tag, $handle) {
      if ('shoelace-autoloader' !== $handle) {
        return $tag;
      }
      return str_replace(' src', ' type="module" src', $tag);
    }, 10, 2);
  }

  public static function registerSettingsPage() {
    wp_register_style(
        'avacy-dashboard',
        plugins_url( '/../styles/avacy-dashboard.css', __FILE__ ),
        array(),
        '1.0',
        'screen'
    );
    wp_register_script(
      'avacy-dashboard',
      plugins_url( '/../styles/avacy-dashboard.js', __FILE__ ),
      array(),
      '1.0',
      true
    );

    wp_enqueue_script( 'avacy-dashboard' );
    wp_enqueue_style( 'avacy-dashboard' );

    require_once(__DIR__ . '/../views/avacy-dashboard.php');
  }

  public static function registerSettings()
  {
    register_setting('avacy-plugin-settings-group', 'avacy_tenant', 'sanitize_text_field');
    register_setting('avacy-plugin-settings-group', 'avacy_webspace_key', 'sanitize_text_field');
    register_setting('avacy-plugin-settings-group', 'avacy_webspace_id', 'sanitize_text_field');
    register_setting('avacy-plugin-settings-group', 'avacy_api_token', 'sanitize_text_field');
    register_setting('avacy-plugin-settings-group', 'avacy_show_banner', 'sanitize_text_field');
    register_setting('avacy-plugin-settings-group', 'avacy_enable_preemptive_block', 'sanitize_text_field');
  }

  public static function saveFields() {
    // get all forms saved in the database
    $forms = self::detectAllForms();

    // get all id from $forms
    $formValues = [];

    // create an array of fields that you have to search in $request
    foreach($forms as $form) {
      $type = str_replace(' ', '_',$form->getType());
      $fields = $form->getFields();
      $id = $form->getId();
      
      foreach($fields as $field) {
        $fieldName = str_replace(' ', '_', $field['name']);
        $formFieldOpt = 'avacy_form_field_' . $field['type'] . '_' . $form->getId() . '_' . $fieldName;
        $formValues[$id]['fields'][$formFieldOpt] = get_option($formFieldOpt) ?? 'off';
      }

      $enabledOption = 'avacy_' . $type . '_' . $form->getId() . '_radio_enabled';
      $enabled = get_option($enabledOption) ?? 'off';
      
      $identifierOption = 'avacy_' . $type . '_' . $id . '_form_user_identifier';
      $identifier = get_option($identifierOption) ?? null;

      $formValues[$id][$enabledOption] = $enabled;
      $formValues[$id][$identifierOption] = $identifier;
    }

    if(isset($_REQUEST['option_page']) && $_REQUEST['option_page'] === 'avacy-plugin-settings-group') {
      // for each field in $fields check if it exists in $request
      foreach($formValues as $key => $value) {

        // update fields
        foreach($value['fields'] as $k => $option) {
          if(isset($_REQUEST[$k])) {
            $sanitized_value = sanitize_text_field($_REQUEST[$k]);

            // save the field name in the database then update option
            update_option($k, $sanitized_value);
          } else {
            update_option($k, 'off');
          }
        }

        // take the remaining keys in the array to update
        foreach($value as $k => $v) {
          if($k !== 'fields') {
            if(isset($_REQUEST[$k])) {
              $sanitized_value = sanitize_text_field($_REQUEST[$k]);

              // save the field name in the database
              update_option($k, $sanitized_value);
            } else {
              update_option($k, 'off');
            }
          }
        }
      }
    }
  }

  public static function addMenuPage() {
    add_menu_page('Avacy CMP', 'Avacy CMP', 'manage_options', 'avacy-plugin-settings', [static::class, 'registerSettingsPage'], 'dashicons-avacy');
  }

  public static function detectAllForms()
  {
    $cf7Forms = ContactForm7::detectAllForms();
    $wcForms = WooCommerceCheckoutForm::detectAllForms();
    $wpForms = WpForms::detectAllForms();
    $elForms = ElementorForms::detectAllForms();
    $htmlForms = HtmlForms::detectAllForms();

    // etc. etc.
    return array_merge($cf7Forms, $wcForms, $wpForms, $elForms, $htmlForms);
  }

}
