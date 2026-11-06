<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Le caratteristiche di consenso dei form e la scelta, per ogni form, di quale casella le attiva.
 * La mappa e' solo del plugin: il SaaS riceve le chiavi in consent_features.
 */
class ConsentFeatures
{
    // Scelte oltre alla casella: «presa visione» (inviata sempre) solo per le caratteristiche `ack`, «nessun campo» per le altre.
    public const NONE = 'none';
    public const ACKNOWLEDGED = 'acknowledged';
    private const FIELD_PREFIX = 'field:';

    /**
     * La mappa, nell'ordine in cui le chiavi partono verso il SaaS.
     * `label`: il nome mostrato nell'interfaccia. Al SaaS va solo la chiave.
     * `field`: obbligatorieta' attesa della casella (serve solo per gli avvisi).
     * `ack`: ammette «presa visione» (valore di partenza) e non «nessun campo».
     */
    public static function all() : array
    {
        return [
            ['key' => 'privacy_policy', 'label' => __('Privacy policy', 'avacy'), 'active' => true, 'field' => 'required', 'ack' => true],
            ['key' => 'cookie_policy', 'label' => __('Cookie policy', 'avacy'), 'active' => true, 'field' => 'required', 'ack' => true],
            ['key' => 'newsletter', 'label' => __('Newsletter', 'avacy'), 'active' => true, 'field' => 'optional', 'ack' => false],
            ['key' => 'marketing', 'label' => __('Marketing', 'avacy'), 'active' => true, 'field' => 'optional', 'ack' => false],
            ['key' => 'marketing_third_party', 'label' => __('Third-party marketing', 'avacy'), 'active' => true, 'field' => 'optional', 'ack' => false],
            ['key' => 'profiling', 'label' => __('Profiling', 'avacy'), 'active' => true, 'field' => 'optional', 'ack' => false],
            ['key' => 'tracking_pixel', 'label' => __('Tracking pixel', 'avacy'), 'active' => true, 'field' => 'optional', 'ack' => false],
            ['key' => 'terms_and_conditions', 'label' => __('Terms and conditions', 'avacy'), 'active' => true, 'field' => 'required', 'ack' => false],
        ];
    }

    public static function active() : array
    {
        return array_values(array_filter(self::all(), function($feature) {
            return $feature['active'];
        }));
    }

    // Il valore di partenza: «presa visione» per privacy e cookie (come ha sempre inviato il plugin), le altre nessun campo.
    public static function defaultChoice(array $feature) : string
    {
        return $feature['ack'] ? self::ACKNOWLEDGED : self::NONE;
    }

    // La scelta senza casella ammessa da questa caratteristica.
    public static function plainChoice(array $feature) : string
    {
        return self::defaultChoice($feature);
    }

    // Stesso schema di nome degli altri switch del form: tipo normalizzato e id.
    public static function optionName(string $type, $id, string $key) : string
    {
        return 'avacy_' . strtolower(str_replace(' ', '_', $type)) . '_' . $id . '_consent_feature_' . $key;
    }

    // Lo stesso nome per il menu e per il confronto con la casella: gli spazi diventano underscore.
    public static function slug(string $name) : string
    {
        return str_replace(' ', '_', $name);
    }

    // La scelta di una casella: per la chiave stabile della casella (di norma il suo nome, per WPForms `id:<N>`).
    public static function fieldChoice(string $key) : string
    {
        return self::FIELD_PREFIX . self::slug($key);
    }

    // Una scelta salvata su una casella che nel form non c'e' piu'.
    public static function isMissingField(string $choice, array $checkboxes) : bool
    {
        if (strpos($choice, self::FIELD_PREFIX) !== 0) {
            return false;
        }
        foreach ($checkboxes as $box) {
            if ($choice === self::fieldChoice($box['key'])) {
                return false;
            }
        }
        return true;
    }

    public static function missingText() : string
    {
        return __('The linked field is no longer in the form. Choose another one.', 'avacy');
    }

    // Una scelta valida per questa caratteristica e questo form: quella senza casella, o una sua casella.
    public static function isValidChoice(string $choice, array $feature, array $checkboxes) : bool
    {
        if ($choice === self::plainChoice($feature)) {
            return true;
        }
        foreach ($checkboxes as $box) {
            if ($choice === self::fieldChoice($box['key'])) {
                return true;
            }
        }
        return false;
    }

    // La scelta salvata. Un form mai salvato, o un valore non riconosciuto, ha quella predefinita.
    public static function choice(string $type, $id, array $feature) : string
    {
        $saved = get_option(self::optionName($type, $id, $feature['key']));
        if ($saved === self::plainChoice($feature)
            || (is_string($saved) && strpos($saved, self::FIELD_PREFIX) === 0 && strlen($saved) > strlen(self::FIELD_PREFIX))) {
            return $saved;
        }
        return self::defaultChoice($feature);
    }

    /**
     * Le chiavi da inviare: quelle in «presa visione» e quelle la cui casella e' spuntata.
     * Solo chiavi della mappa, senza doppioni, nell'ordine della mappa.
     * @param array $tickedSlugs gli slug delle caselle spuntate nell'invio
     */
    public static function accepted(string $type, $id, array $tickedSlugs) : array
    {
        $keys = [];
        foreach (self::active() as $feature) {
            $choice = self::choice($type, $id, $feature);
            if ($choice === self::ACKNOWLEDGED) {
                $keys[] = $feature['key'];
            } elseif ($choice !== self::NONE && in_array(substr($choice, strlen(self::FIELD_PREFIX)), $tickedSlugs, true)) {
                $keys[] = $feature['key'];
            }
        }
        return $keys;
    }

    // Una casella e' spuntata se il suo valore inviato non e' vuoto (un array: almeno un valore).
    public static function isTicked($value) : bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::isTicked($item)) {
                    return true;
                }
            }
            return false;
        }
        $value = trim((string) $value);
        return $value !== '' && $value !== '0';
    }

    // Il rilievo informativo: obbligatorieta' della casella incoerente con quella attesa. Null se coerente o non leggibile.
    public static function mismatch(string $field, ?bool $boxRequired) : ?string
    {
        if ($boxRequired === null) {
            return null;
        }
        if ($field === 'required' && !$boxRequired) {
            return 'optional_box';
        }
        if ($field === 'optional' && $boxRequired) {
            return 'required_box';
        }
        return null;
    }

    public static function mismatchText(string $mismatch) : string
    {
        if ($mismatch === 'optional_box') {
            return __('This checkbox is not set as required in the form. Make it required in the form builder.', 'avacy');
        }
        return __('Required checkbox: this consent must be optional. Make it optional in the form builder.', 'avacy');
    }

    // Il pixel su una casella che attiva anche un'altra caratteristica: il consenso al pixel non sarebbe specifico.
    public static function pixelSharesField(string $type, $id) : bool
    {
        $pixel = null;
        $others = [];
        foreach (self::active() as $feature) {
            $choice = self::choice($type, $id, $feature);
            if ($feature['key'] === 'tracking_pixel') {
                $pixel = $choice;
            } else {
                $others[] = $choice;
            }
        }
        return $pixel !== null && strpos($pixel, self::FIELD_PREFIX) === 0 && in_array($pixel, $others, true);
    }

    public static function pixelText() : string
    {
        return __('The tracking pixel needs its own checkbox.', 'avacy');
    }

    // I rilievi di un form con le scelte salvate: [['key' => ..., 'label' => ..., 'text' => ...]]. Servono alla notice.
    public static function warnings(Form $form) : array
    {
        $warnings = [];
        foreach (self::active() as $feature) {
            if ($feature['key'] === 'tracking_pixel' && self::pixelSharesField($form->getType(), $form->getId())) {
                $warnings[] = ['key' => $feature['key'], 'label' => $feature['label'], 'text' => self::pixelText()];
            }
            $choice = self::choice($form->getType(), $form->getId(), $feature);
            if (self::isMissingField($choice, $form->getCheckboxes())) {
                $warnings[] = ['key' => $feature['key'], 'label' => $feature['label'], 'text' => self::missingText()];
                continue;
            }
            foreach ($form->getCheckboxes() as $box) {
                if ($choice !== self::fieldChoice($box['key'])) {
                    continue;
                }
                $mismatch = self::mismatch($feature['field'], $box['required']);
                if ($mismatch !== null) {
                    $warnings[] = ['key' => $feature['key'], 'label' => $feature['label'], 'text' => self::mismatchText($mismatch)];
                }
            }
        }
        return $warnings;
    }
}
