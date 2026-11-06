<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Form {

    private string $id;
    private string $type;
    private array $fields;
    // Le caselle di consenso del form: [['name' => ..., 'key' => ..., 'required' => true|false|null]].
    // `key`: l'identita' stabile della casella per le caratteristiche (se manca, il suo nome). Null in `required`: non leggibile.
    private array $checkboxes;
    private string $title;

    public function __construct($id, $type, $fields, array $checkboxes = [], $title = '') {
        $this->title = sanitize_text_field((string) $title);
        $this->id = sanitize_text_field($id);
        $this->type = sanitize_text_field($type);
        $this->fields = $this->sanitizeFields($fields);
        $this->checkboxes = array_map(function($box) {
            return [
                'name' => sanitize_text_field($box['name']),
                'key' => sanitize_text_field($box['key'] ?? str_replace(' ', '_', $box['name'])),
                'required' => isset($box['required']) ? (bool) $box['required'] : null,
            ];
        }, $checkboxes);
    }

    // Il nome del form per l'utente: il titolo nel suo builder, altrimenti il tipo con l'id.
    public function getName() : string {
        return $this->title !== '' ? $this->title : $this->type . ' #' . $this->id;
    }

    // Il tipo come lo scrive l'integrazione (es. "Contact Form 7"): getType() lo abbassa per i nomi delle opzioni.
    public function getTypeLabel() : string {
        return $this->type;
    }

    public function getCheckboxes() : array {
        return $this->checkboxes;
    }

    public function getFields() : array {
        return $this->fields;
    }

    public function getId() : string {
        return $this->id;
    }

    public function getType() : string {
        return strtolower($this->type);
    }

    private function sanitizeFields($fields) {
        if (is_array($fields)) {
            foreach ($fields as $field) {
                $field['name'] = sanitize_text_field($field['name']);
            }
        }
        return $fields;
    }
}