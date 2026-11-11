# Changelog

Full history of the Avacy CMP plugin. The same entries of the latest versions are in the Changelog section of `readme.md`, which is what WordPress.org shows.

### 1.5.1

A fix for the forms of the "Consent Archive" tab.

* Entering the Access Key again and saving no longer turns your forms off. Before, when Avacy stopped accepting the saved key (for example after you created a new one), every form lost its switch, identification field and data fields, and stopped sending consents.

### 1.5.0

Avada forms, and consents linked to the checkboxes of your forms.

* **Avada forms** now appear in the "Consent Archive" tab, like the other supported forms.
* **Consents linked to checkboxes.** In the "Consent Archive" tab you can link each consent (newsletter, marketing, profiling, tracking pixel, terms and conditions…) to a checkbox of the form: it is recorded only when the checkbox is ticked. Nothing changes until you link them.
* Clearer "Consent Archive" tab: "Save changes" stays off until every form you send has an identification field and at least one data field.
* The WooCommerce checkout now uses the identification field you chose (the email, if that field is empty).
* The WooCommerce thank-you page no longer shows a fatal error when the order can't be found.
* The banner version and the vendor list are refreshed every 2 hours instead of 6.
* With Preemptive Block on, inline scripts that contain HTML or accented characters are no longer altered.
* Forms of a form plugin that is turned off or removed no longer appear in the "Consent Archive" tab. Turn the plugin back on and they come back with their settings.
* Deleting the plugin now also removes your Avacy credentials, Access Key included, and the data it downloads. Your settings stay, so a reinstall only asks you to connect again.
* The Preemptive Block tab now tells you when the last update of the vendor list failed. The rules you already had stay active.
* With the cookie banner off and Preemptive Block on, the Preemptive Block tab now warns you: blocked scripts only run if the cookie banner is installed another way, for example with Google Tag Manager.
* If Avacy can't load the data of your account, the settings page now asks you to contact support instead of trying again later.
* The "Consent Archive" tab now warns you when the tracking pixel shares its checkbox with another consent: the tracking pixel needs its own checkbox.

### 1.4.1

The settings page now uses the same names as Avacy: "Access Key" and "Consent Archive".

* The key that connects the consent archive is now called "Access Key", as in Avacy. The key you already saved keeps working: nothing to do.
* The "Consent Solution" tab is now "Consent Archive".
* The link in the "Consent Archive" tab opens the Access Key page of Avacy directly.

### 1.4.0

Support for the new Avacy cookie banner, and a faster, more reliable preemptive block.

* **Important: after updating, turn on the "Save" switch of every form whose consents you want to keep sending to Avacy.** Forms with the switch off no longer send their consents. You find the switch in the "Consent Archive" tab of the settings page.
* Ready for the new Avacy banner: the plugin loads the version your webspace is set to. **Update the plugin before Avacy switches your webspace over.**
* Faster pages: the vendor list is kept on your site and refreshed every six hours, so an Avacy outage no longer reaches your visitors.
* More reliable preemptive block: it no longer breaks structured data or modern scripts, and a new webspace key takes effect at once.
* The settings page shows the banner version in use and when the blocking rules were last updated, with a button to update them now.
* Fixes: your credentials are no longer cleared when Avacy is briefly unreachable; uploads from Media no longer end up in the wrong year/month folder; no more fatal error on WordPress 5.3–5.8 with PHP 7.4; no more Contact Form 7 notices in the debug log.
* Security hardening.
* Tested up to WordPress 7.1.

### 1.3.1

Verified and extended compatibility testing up to WordPress 7.0 (Playwright/Playground e2e suite plus manual wp-env QA against WordPress 7.0.1, PHP 8.2 — no errors, warnings or deprecation notices found).

### 1.3.0

Integrate WP Consent API: when the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) plugin is active, Avacy auto-registers as a compliant CMP, forces `optin` consent type and bridges the banner `avacy_consent` event to `wp_set_consent` via a small JS adapter. Google Consent Mode flags are mapped to WP Consent API categories (personalization_storage → preferences, analytics_storage → statistics + statistics-anonymous, ad_* → marketing, functional always allow). No admin UI: the integration stays dormant when the WP Consent API plugin is not installed.

### 1.2.6

Check if custom vendor list is correctly downloaded before processing blacklist

### 1.2.5

Fixed some links to the cookie banner section in Avacy SaaS;
Fixed webspace key updating process

### 1.2.4

Fix link to consent archive access key generator

### 1.2.2

Fix compatibility bug with Elementor edit page

### 1.2.1

Fix enqueueing banner script

### 1.2.0

Refactor Consent Solution API

### 1.1.7

Fix fatal error when loading HTML in buffer

### 1.1.6

Do not loop through keywords list if list is empty

### 1.1.5

Fix fatal error when CF7 is not installed or enabled

### 1.1.4

Align code repository on SVN

### 1.1.3

Fix CF7 forms detection

### 1.1.2

Align new repo foldering

### 1.1.1

Add missing classes

### 1.1.0

Change default plugin language to English and generate the .pot file.
Minor bug fixes.
Tested up to WordPress 6.7.

### 1.0.9

Fix minor release issues

### 1.0.8

Add only selected fields in payload

### 1.0.7

Restored vendors folder from repository.

### 1.0.6

Removed vendors folder from repository.

### 1.0.5

Fixed a bug made impossible to access CF7 form list.

### 1.0.4

Fixed bug that crashed the plugin if Contact Form 7 did not exist or was not active.

### 1.0.3

Fixed bug that crashed the plugin if Elementor did not exist or was not active.