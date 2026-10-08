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
  /**
   * I soli verdetti che autorizzano a cancellare quello che l'utente ha salvato.
   * Tutto il resto - un silenzio, un 503, un 404 che non porta un codice noto -
   * non e' un verdetto sulle credenziali, e lascia le opzioni dove sono.
   */
  const SAAS_DENIALS = ['team_not_found', 'webspace_not_found'];
  const TOKEN_DENIALS = ['invalid_token', 'token_not_found_or_expired'];

  private static function isDenial($result, array $denials) {
    return !empty($result)
      && (int) ($result['status'] ?? 0) === 404
      && in_array($result['error'] ?? '', $denials, true);
  }

  /** Il codice d'errore come lo scrive il SaaS, o null se la risposta non ne porta uno. */
  private static function errorCodeOf($data) {
    if (!is_array($data)) {
      return null;
    }

    if (isset($data['error']) && is_string($data['error'])) {
      return $data['error'];
    }

    if (isset($data['message']['error']) && is_string($data['message']['error'])) {
      return $data['message']['error'];
    }

    return null;
  }

  /**
   * Dal punto di vista di chi guarda la pagina, un silenzio e un guasto del
   * SaaS sono lo stesso fatto: non lo sappiamo, e non abbiamo toccato niente.
   */
  private static function unreachableNotice() {
    return [
      'avacy_saas',
      'saas_unreachable',
      __('Avacy could not be reached, so nothing was removed. Please try again later.', 'avacy'),
      'warning'
    ];
  }

  /** La chiamata non e' partita o non e' tornata: non sappiamo niente. */
  private static function unreachable() {
    return [
      'status' => 0,
      'error' => null,
      'notice' => self::unreachableNotice(),
    ];
  }

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

    $notices = [];
    // Il redirect in coda ricarica questa stessa pagina, e l'hook riparte da
    // capo: si puo' fare solo dopo aver cambiato qualcosa che al giro dopo
    // ferma il giro. Un avviso da solo non lo cambia.
    $credentialsCleared = false;

    if (empty($checkSaasAccount) || $checkSaasAccount['status'] === 200) {
      // if tenant and webspace key are not empty, concat them with a pipe
      if (strpos($webspaceKey, '|') === false) {
        $webspaceKey = $tenant . '|' . $webspaceKey;
        update_option('avacy_webspace_key', $webspaceKey);
      }

      if(empty($apiToken)){
        return;
      }
      $checkConsentSolutionToken = self::checkConsentSolutionToken($apiToken);
      if (empty($checkConsentSolutionToken) || $checkConsentSolutionToken['status'] === 200) {
        return;
      }

      $notices[] = $checkConsentSolutionToken['notice'];

      if (self::isDenial($checkConsentSolutionToken, self::TOKEN_DENIALS)) {
        update_option('avacy_api_token', '');
        set_transient('avacy_active_tab', 'consent-archive', 30);
        $credentialsCleared = true;
      }
    } elseif (self::isDenial($checkSaasAccount, self::SAAS_DENIALS)) {
      $notices[] = $checkSaasAccount['notice'];
      update_option('avacy_tenant', '');
      update_option('avacy_webspace_key', '');
      update_option('avacy_webspace_id', '');
      update_option('avacy_api_token', '');
      $credentialsCleared = true;
    } else {
      // Il SaaS non ha detto che queste credenziali sono sbagliate: non lo ha
      // detto affatto. Si avvisa e si tiene tutto.
      $notices[] = $checkSaasAccount['notice'];
    }

    foreach ($notices as $notice) {
      if (!empty($notice)) {
        add_settings_error($notice[0], $notice[1], $notice[2], $notice[3]);
      }
    }

    set_transient('settings_errors', get_settings_errors(), 30);

    if (!$credentialsCleared) {
      return;
    }

    wp_safe_redirect($redirect_to);
    exit;
  }
  
  public static function AvacyAdminSave() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'avacy'));
    }

    if ( !isset($_REQUEST['_wpnonce']) || !wp_verify_nonce( sanitize_text_field($_REQUEST['_wpnonce']), 'avacy-plugin-settings-group-options' ) ) {
      die( 'Security check' ); 
    } 

    // Ripuliti appena letti, come i campi qui sotto: lo stesso valore finisce
    // in explode(), nella chiamata al SaaS e nel database.
    $webspaceKeyField = isset($_POST['avacy_webspace_key']) ? sanitize_text_field($_POST['avacy_webspace_key']) : '';
    $tenantField = isset($_POST['avacy_tenant']) ? sanitize_text_field($_POST['avacy_tenant']) : '';

    $isKeyInOldFormat = strpos($webspaceKeyField, '|') === false;
    if( $isKeyInOldFormat) {
      $webspaceKey = $webspaceKeyField;
      $saveAccountToken = $webspaceKeyField;
    } else {
      $accountToken = explode('|', $webspaceKeyField);
      $webspaceKey = $accountToken[1];
      $saveAccountToken = $webspaceKeyField;
    }
    
    if( $isKeyInOldFormat ) {
      $tenant = $tenantField;
    } else {
      $accountToken = explode('|', $webspaceKeyField);
      $tenant = $accountToken[0];
    }
    
    $redirect_to = isset($_POST['redirectToUrl']) ? esc_url(sanitize_text_field($_POST['redirectToUrl'])) : '';
    $apiToken = isset($_POST['avacy_api_token']) ? sanitize_text_field($_POST['avacy_api_token']) : '';
    $showBanner = isset($_POST['avacy_show_banner']) ? sanitize_text_field($_POST['avacy_show_banner']) : '';
    $enablePreemptiveBlock = isset($_POST['avacy_enable_preemptive_block']) ? sanitize_text_field($_POST['avacy_enable_preemptive_block']) : '';
    $activeTab = isset($_POST['avacy_active_tab']) ? sanitize_text_field($_POST['avacy_active_tab']) : '';
    $notices = [];

    if (empty($tenant) || empty($webspaceKey)) {
      set_transient('settings_errors', get_settings_errors(), 30);
      wp_safe_redirect($redirect_to);
      exit;
    }

    $checkSaasAccount = self::checkSaasAccount($tenant, $webspaceKeyField);
    if (!empty($checkSaasAccount)) {
      $notices[] = $checkSaasAccount['notice'];
    }

    $checkConsentSolutionToken = self::checkConsentSolutionToken($apiToken);
    if (!empty($checkConsentSolutionToken)) {
      $notices[] = $checkConsentSolutionToken['notice'];
    }

    // Una risposta non vuota e' un rilievo del SaaS: si avvisa e non si scrive.
    if (empty($checkSaasAccount)) {
      update_option('avacy_show_banner', esc_attr($showBanner));
      update_option('avacy_enable_preemptive_block', esc_attr($enablePreemptiveBlock));

      // Finche' non e' mai riuscita non c'e' una copia locale, e qui aspettare
      // la rete e' accettabile (10b). Si guarda la riuscita, non il tentativo:
      // cosi' un Salva dopo aver corretto le credenziali riprova sempre.
      // A blocco spento refreshRules() esce da se'.
      if (empty(get_option(PreemptiveBlock::OPTION_LAST_SUCCESS))) {
        PreemptiveBlock::refreshRules();
      }

      $notices[] = [
        'avacy_settings',
        'settings_saved',
        __('The changes have been saved successfully.', 'avacy'),
        'success'
      ];
    }

    // if current avacy_webspace_key and avacy_tenant are not the same as the new ones, update them
    if (get_option('avacy_tenant') !== $tenant || get_option('avacy_webspace_key') !== $webspaceKey) {
      update_option('avacy_tenant', esc_attr($tenant));
      update_option('avacy_webspace_key', esc_attr($saveAccountToken));
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
    global $api_base_url;

    $option_account_token = get_option('avacy_webspace_key');
    
    if( empty($option_account_token) ) {   
      $option_account_token = $webspaceKey;
    } 

    if (strpos($option_account_token, '|') === false) {
      $option_tenant = get_option('avacy_tenant');
      $option_webspace_key = $option_account_token;
      $save_account_token = $option_webspace_key;
    } else {
      $option_tenant = explode('|', $option_account_token)[0] ?? '';
      $option_webspace_key = explode('|', $option_account_token)[1] ?? '';
      $save_account_token = $option_account_token;
    }
    
    $endpoint = $api_base_url . '/wp/validate/' . $option_tenant . '/' . $option_webspace_key;    
    $response = wp_remote_get($endpoint);

    if (is_wp_error($response)) {
      return self::unreachable();
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    $error_code = self::errorCodeOf($data);

    $setting = '';
    $code = '';
    $message = '';
    $type = '';

    if ($status_code !== 200) {
      switch ($error_code ?: ($status_code === 404 ? 'team_not_found' : '')) {
        case 'team_not_found':
          $setting = 'avacy_team';
          $code = 'team_not_found';
          $message = __('Team not found. Please check the entered data.', 'avacy');
          $type = 'danger';
          break;
        
        case 'webspace_not_found':
          $setting = 'avacy_webspace';
          $code = 'webspace_not_found';
          $message = __('Webspace not found. Please check the entered data.', 'avacy');
          $type = 'danger';
          break;

        default:
          // Non e' un verdetto sulle credenziali: un 503, un 502 di un proxy,
          // un codice che non sappiamo leggere. Mandare l'utente a ricontrollare
          // dati che sono giusti e' il modo peggiore di dirgli che la rete e' giu'.
          [$setting, $code, $message, $type] = self::unreachableNotice();
          break;
      }
    } else {
      // La risposta porta anche la versione del banner (14), e questa e' l'unica
      // chiamata che la porta: il gate si aggiorna qui, senza una richiesta in
      // piu'. Prima del `return []` sotto, che e' il caso normale.
      // Solo se il campo c'e' davvero. Una 200 che non porta il JSON atteso — un
      // firewall, un proxy di mezzo — altrimenti riscriverebbe «v2» su un
      // webspace che sappiamo essere v3, e quel sito passerebbe a OIL. Il
      // default verso v2 vale quando non si sa niente, non per cancellare
      // quello che si sapeva.
      if (is_array($data) && array_key_exists('banner_config_version', $data)) {
        BannerVersionGate::store($data['banner_config_version']);
      }

      // Stessa risposta, stessa regola: si raccoglie solo se c'e'. Finche' il
      // SaaS non lo manda, il plugin resta sul dominio che ha gia'.
      if (is_array($data) && array_key_exists('cdn_url', $data)) {
        Hosts::storeCdn($data['cdn_url']);
      }

      if ( ($tenant === $option_tenant && $webspaceKey === $option_webspace_key) || (isset($_POST['avacy_webspace_key']) && $option_account_token === sanitize_text_field($_POST['avacy_webspace_key']))) {
        return [];
      }

      $webspaceId = $data['id'];
      if (!empty($tenant)) {
        update_option('avacy_tenant', esc_attr($tenant));
      }
      if (!empty($webspaceKey)) {
        update_option('avacy_webspace_key', esc_attr($save_account_token));
      }
      if (!empty($webspaceId)) {
        update_option('avacy_webspace_id', esc_attr($webspaceId));
      }
      
      $setting = 'avacy_tenant';
      $code = 'tenant_found';
      $message = __('The entered credentials are valid.', 'avacy');
      $type = 'success';

    }

    return [
      'status' => $status_code,
      'error' => $error_code,
      'notice' => [
        $setting,
        $code,
        $message,
        $type
      ]
    ];
  }

  public static function checkConsentSolutionToken($apiToken) {
    global $api_base_url;
    $option_api_token = get_option('avacy_api_token');

    $setting = '';
    $code = '';
    $message = '';
    $type = '';
    
    if (!empty($apiToken)) {
      $option_tenant = get_option('avacy_tenant');
      $option_webspace_key = get_option('avacy_webspace_key');

      if( strpos($option_webspace_key, '|') === false ) {
        $option_webspace_key = $option_webspace_key;
      } else {
        $option_tenant = explode('|', $option_webspace_key)[0] ?? '';
        $option_webspace_key = explode('|', $option_webspace_key)[1] ?? '';
      }

      $endpoint = $api_base_url . '/wp/validate/' . $option_tenant . '/' . $option_webspace_key . '/' . $apiToken;
      // $endpoint = $api_base_url . '/wp/validate/' . $apiToken;

      $response = wp_remote_get($endpoint);

      if (is_wp_error($response)) {
        return self::unreachable();
      }

      $status_code = (int) wp_remote_retrieve_response_code($response);
      $body = wp_remote_retrieve_body($response);
      $data = json_decode($body, true);
      $error_code = self::errorCodeOf($data);
  
      if ($status_code !== 200) {
        switch ($error_code ?: ($status_code === 404 ? 'token_not_found_or_expired' : '')) {
          case 'invalid_token':
            $setting = 'avacy_api_token';
            $code = 'invalid_token';
            $message = __('The entered token has an incorrect format.', 'avacy');
            $type = 'warning';
            break;

          case 'token_not_found_or_expired':
            $setting = 'avacy_api_token';
            $code = 'token_not_found_or_expired';
            $message = __('Token not found or expired. Please check the entered data.', 'avacy');
            $type = 'danger';
            break;

          default:
            [$setting, $code, $message, $type] = self::unreachableNotice();
            break;
        }
      } else {
        if ($apiToken === $option_api_token) {
          return [];
        }
        update_option('avacy_api_token', esc_attr($apiToken));
        
        $setting = 'avacy_api_token';
        $code = 'valid_token';
        $message = __('The token has been saved successfully.', 'avacy');
        $type = 'success';
      }
    } else {
      if (!empty($option_api_token)) {
        update_option('avacy_api_token', '');
  
        $setting = 'avacy_api';
        $code = 'remove_token';
        $message = __('The token has been removed successfully.', 'avacy');
        $type = 'success';
        $status_code = 200;
      } else {
        return [];
      }
    }

    return [
      'status' => $status_code,
      'error' => $error_code ?? null,
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

    require_once(__DIR__ . '/views/avacy-dashboard.php');
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
