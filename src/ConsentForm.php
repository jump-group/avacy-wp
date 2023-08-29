<?php

namespace Jumpgroup\Avacy;

class ConsentForm
{

    public function __construct(
        private string $name,
        private string $mail,
        private string $message,
    ) {
    }

    public function getData(): string
    {
        return $this->name;
    }
}
