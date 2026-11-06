<?php
// Form di prova per le caselle di consenso (spec form-consents): uno per integrazione, con caselle facoltative e obbligatorie.
kses_remove_filters();

function avacy_seed_page( $slug, $content ) {
	wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug, 'post_content' => $content ] );
}

// HTML Forms: casella privacy facoltativa; un secondo form con la casella obbligatoria
$hf = wp_insert_post( [ 'post_type' => 'html-form', 'post_status' => 'publish', 'post_title' => 'HF consensi', 'post_name' => 'hf-consensi',
	'post_content' => '<p><input type="email" name="EMAIL" required></p><p><label><input type="checkbox" name="PRIVACY" value="1"> Accetto la privacy</label></p><p><input type="submit" value="Send"></p>' ] );
avacy_seed_page( 'hf-form', '[hf_form id="' . $hf . '"]' );
wp_insert_post( [ 'post_type' => 'html-form', 'post_status' => 'publish', 'post_title' => 'HF obbligatorio', 'post_name' => 'hf-obbligatorio',
	'post_content' => '<p><input type="email" name="EMAIL" required></p><p><label><input type="checkbox" name="CONSENSO" value="1" required> Accetto</label></p><p><input type="submit" value="Send"></p>' ] );

// HTML Forms: un gruppo di caselle `interessi[]` (un campo solo) e una casella singola
$hfi = wp_insert_post( [ 'post_type' => 'html-form', 'post_status' => 'publish', 'post_title' => 'HF interessi', 'post_name' => 'hf-interessi',
	'post_content' => '<p><input type="email" name="EMAIL" required></p><p><label><input type="checkbox" name="interessi[]" value="newsletter"> Newsletter</label> <label><input type="checkbox" name="interessi[]" value="eventi"> Eventi</label> <label><input type="checkbox" name="interessi[]" value="offerte"> Offerte</label></p><p><label><input type="checkbox" name="PRIVACY" value="1"> Accetto la privacy</label></p><p><input type="submit" value="Send"></p>' ] );
avacy_seed_page( 'hf-interessi-form', '[hf_form id="' . $hfi . '"]' );

// HTML Forms: un campo nascosto e una casella con lo stesso nome (il nascosto manda 0 se la casella non e' spuntata)
$hfn = wp_insert_post( [ 'post_type' => 'html-form', 'post_status' => 'publish', 'post_title' => 'HF nascosto', 'post_name' => 'hf-nascosto',
	'post_content' => '<p><input type="email" name="EMAIL" required></p><input type="hidden" name="OPTIN" value="0"><p><label><input type="checkbox" name="OPTIN" value="1"> Newsletter</label></p><p><input type="submit" value="Send"></p>' ] );
avacy_seed_page( 'hf-nascosto-form', '[hf_form id="' . $hfn . '"]' );

// HTML Forms: un form di settembre 2024, per l'upload da Media (#743): il file va nella cartella del mese corrente
wp_insert_post( [ 'post_type' => 'html-form', 'post_status' => 'publish', 'post_title' => 'HF vecchio', 'post_name' => 'hf-vecchio',
	'post_date' => '2024-09-15 10:00:00', 'post_date_gmt' => '2024-09-15 10:00:00',
	'post_content' => '<p><input type="email" name="EMAIL" required></p><p><input type="submit" value="Send"></p>' ] );

// Contact Form 7: acceptance facoltativo e un secondo form con l'acceptance obbligatorio
$cf = WPCF7_ContactForm::get_template( [ 'title' => 'CF7 consensi' ] );
$cf->set_properties( [ 'form' => "[email* your_email]\n[acceptance privacy optional] Accetto la privacy [/acceptance]\n[submit \"Send\"]" ] );
$cf->save();
avacy_seed_page( 'cf7-form', '[contact-form-7 id="' . $cf->id() . '"]' );
$cf2 = WPCF7_ContactForm::get_template( [ 'title' => 'CF7 obbligatorio' ] );
$cf2->set_properties( [ 'form' => "[email* your_email]\n[acceptance consenso] Accetto [/acceptance]\n[submit \"Send\"]" ] );
$cf2->save();

// WPForms Lite: email + checkbox facoltativa
update_option( 'wpforms_settings', [ 'gdpr' => '1' ] );
$wpf = wp_insert_post( [ 'post_type' => 'wpforms', 'post_status' => 'publish', 'post_title' => 'WPF consensi', 'post_content' => '{}' ] );
$data = [
	'id' => (string) $wpf,
	'field_id' => 4,
	'fields' => [
		'1' => [ 'id' => '1', 'type' => 'email', 'label' => 'Email', 'required' => '1', 'size' => 'medium' ],
		'3' => [ 'id' => '3', 'type' => 'checkbox', 'label' => 'Privacy checkbox', 'required' => '0', 'choices' => [ '1' => [ 'label' => 'Accetto la privacy', 'value' => '', 'image' => '' ] ] ],
	],
	'settings' => [ 'form_title' => 'WPF consensi', 'submit_text' => 'Invia', 'ajax_submit' => '0', 'notification_enable' => '0',
		'confirmations' => [ '1' => [ 'type' => 'message', 'message' => 'Grazie', 'message_scroll' => '1' ] ] ],
];
wp_update_post( [ 'ID' => $wpf, 'post_content' => wp_slash( wp_json_encode( $data ) ) ] );
avacy_seed_page( 'wpf-form', '[wpforms id="' . $wpf . '"]' );

// WPForms Lite: gdpr-checkbox come lo crea il builder (sempre obbligatorio)
$wpg = wp_insert_post( [ 'post_type' => 'wpforms', 'post_status' => 'publish', 'post_title' => 'WPF GDPR', 'post_content' => '{}' ] );
$gdata = $data;
$gdata['id'] = (string) $wpg;
$gdata['fields'] = [
	'1' => $data['fields']['1'],
	'2' => [ 'id' => '2', 'type' => 'gdpr-checkbox', 'label' => 'Privacy GDPR', 'required' => '1', 'choices' => [ '1' => [ 'label' => 'Accetto la privacy', 'value' => '', 'image' => '' ] ] ],
];
$gdata['settings']['form_title'] = 'WPF GDPR';
wp_update_post( [ 'ID' => $wpg, 'post_content' => wp_slash( wp_json_encode( $gdata ) ) ] );
avacy_seed_page( 'wpf-gdpr-form', '[wpforms id="' . $wpg . '"]' );

// WPForms Lite: due caselle con la stessa label (id 2 e 3) e un campo email con lo spazio nella label
$wpd = wp_insert_post( [ 'post_type' => 'wpforms', 'post_status' => 'publish', 'post_title' => 'WPF doppia', 'post_content' => '{}' ] );
$ddata = $data;
$ddata['id'] = (string) $wpd;
$ddata['field_id'] = 4;
$box = function ( $id ) {
	return [ 'id' => (string) $id, 'type' => 'checkbox', 'label' => 'Consenso', 'required' => '0', 'choices' => [ '1' => [ 'label' => 'Accetto', 'value' => '', 'image' => '' ] ] ];
};
$ddata['fields'] = [
	'1' => [ 'id' => '1', 'type' => 'email', 'label' => 'Indirizzo email', 'required' => '1', 'size' => 'medium' ],
	'2' => $box( 2 ),
	'3' => $box( 3 ),
];
$ddata['settings']['form_title'] = 'WPF doppia';
wp_update_post( [ 'ID' => $wpd, 'post_content' => wp_slash( wp_json_encode( $ddata ) ) ] );
avacy_seed_page( 'wpf-doppia-form', '[wpforms id="' . $wpd . '"]' );

// WPForms Lite: un campo email con l'apostrofo nella label (l'identificativo nasce dalla label) e due dati: una label con spazi e una con apostrofo
$wpa = wp_insert_post( [ 'post_type' => 'wpforms', 'post_status' => 'publish', 'post_title' => 'WPF apostrofo', 'post_content' => '{}' ] );
$adata = $data;
$adata['id'] = (string) $wpa;
$adata['fields'] = [
	'1' => [ 'id' => '1', 'type' => 'email', 'label' => "E-mail dell'utente", 'required' => '1', 'size' => 'medium' ],
	'2' => [ 'id' => '2', 'type' => 'text', 'label' => 'Numero di telefono', 'required' => '0', 'size' => 'medium' ],
	'3' => [ 'id' => '3', 'type' => 'text', 'label' => "Nome dell'utente", 'required' => '0', 'size' => 'medium' ],
];
$adata['settings']['form_title'] = 'WPF apostrofo';
wp_update_post( [ 'ID' => $wpa, 'post_content' => wp_slash( wp_json_encode( $adata ) ) ] );
avacy_seed_page( 'wpf-apostrofo-form', '[wpforms id="' . $wpa . '"]' );

// WPForms reindirizza al primo accesso a wp-admin (procedura guidata, o pagina di benvenuto): qui no
update_option( 'wpforms_setup_wizard_disabled', 1 );
delete_transient( 'wpforms_activation_redirect' );
delete_transient( 'wpforms_just_activated' );
update_option( 'wpforms_activation_redirect', true );
