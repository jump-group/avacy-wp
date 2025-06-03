<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class FormSubmission
{
    private $ipAddress;
    private $consentType;
    private $optin;
    private $consentData;
    private $identifier;
    private $source;
    private $consentFeatures;
    private $proof;

    public function __construct(
        $ipAddress,
        $consentType,
        $optin,
        $consentData,
        $identifier,
        $source,
        $consentFeatures,
        $proof
    ) {
        $this->ipAddress = $ipAddress ?: '0.0.0.0';
        $this->consentType = $consentType;
        $this->optin = $optin;
        $this->consentData = $consentData;
        $this->identifier = $identifier;
        $this->source = $source;
        $this->consentFeatures = $consentFeatures;
        $this->proof = $proof;
    }

    public function getPayload(): array
    {
        return [
            'ip_address' => $this->ipAddress,
            'consent_type' => $this->consentType,
            'optin' => $this->optin,
            'consent_data' => json_decode($this->consentData, true),
            'identifier' => $this->identifier,
            'source' => $this->source,
            'consent_features' => $this->consentFeatures,
            'html_form' => $this->proof,
        ];
    }

    private function sanitizeFields($fields): array
    {
        // Sanitize fields using WordPress sanitize_text_field() or other appropriate functions
        // Example: return array_map('sanitize_text_field', $fields);
        return $fields;
    }

    private function sanitizeLegalNotices($legalNotices): array
    {
        // Sanitize legal notices using WordPress sanitize_text_field() or other appropriate functions
        // Example: return array_map('sanitize_text_field', $legalNotices);
        return $legalNotices;
    }

    private function sanitizePreferences($preferences): array
    {
        // Sanitize preferences using WordPress sanitize_text_field() or other appropriate functions
        // Example: return array_map('sanitize_text_field', $preferences);
        return $preferences;
    }
}
