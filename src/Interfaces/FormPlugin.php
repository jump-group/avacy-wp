<?php

namespace Jumpgroup\Avacy\Interfaces;

use Jumpgroup\Avacy\ConsentForm;

interface FormPlugin
{
    static function listen() : void;
    static function convertToConsentForm($contact_form) : ConsentForm;
    static function sendFormData($contact_form) : void;
}