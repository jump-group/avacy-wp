<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Form {

    private string $id;
    private string $type;
    private array $fields;

    public function __construct($id, $type, $fields) {
        $this->id = sanitize_text_field($id);
        $this->type = sanitize_text_field($type);
        $this->fields = $this->sanitizeFields($fields);
    }

    public function getFields() : array {
        return $this->fields;
    }

    public function getId() : string {
        return $this->id;
    }

    public function getType() : string {
        return $this->type;
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