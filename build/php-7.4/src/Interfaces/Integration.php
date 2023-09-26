<?php

namespace Jumpgroup\Avacy\Interfaces;

use Jumpgroup\Avacy\FormSubmission;

interface Integration
{
    static function listen() : void;
    static function convertToFormSubmission($contact_form) : FormSubmission;
    static function sendFormData($contact_form) : void;
    static function detectAllForms() : array;
}