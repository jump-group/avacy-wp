<?php
namespace Jumpgroup\Avacy\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Jumpgroup\Avacy\FormSubmission;

interface Form
{
    static function listen() : void;
    static function convertToFormSubmission($contact_form) : FormSubmission;
    static function sendFormData($contact_form) : void;
    static function detectAllForms() : array;
    static function getHTMLForm($id): string;
}