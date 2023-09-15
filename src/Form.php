<?php

namespace Jumpgroup\Avacy;

class Form {

    private string $id;
    private string $type;
    private array $fields;

    public function __construct($id, $type, $fields) {
        $this->id = $id;
        $this->type = $type;
        $this->fields = $fields;
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
}