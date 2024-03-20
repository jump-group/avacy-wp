<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class FormSubmission
{
    private array $fields;
    private string $identifier;
    private string $ipAddress;
    private string $proofs;
    private array $legalNotices;
    private array $preferences;

    public function __construct(
        $fields,
        $identifier,
        $ipAddress,
        $proofs,
        $legalNotices,
        $preferences
    ) {
        $this->fields = $this->sanitizeFields($fields);
        $this->identifier = sanitize_text_field($identifier);
        $this->ipAddress = sanitize_text_field($ipAddress);
        $this->proofs = sanitize_text_field($proofs);
        $this->legalNotices = $this->sanitizeLegalNotices($legalNotices);
        $this->preferences = $this->sanitizePreferences($preferences);
    }

    public function getPayload(): array
    {
        return [
            'subject' => $this->fields,
            'identifier' => $this->identifier,
            'ip_address' => $this->ipAddress,
            'proofs' => $this->proofs,
            'legal_notices' => $this->legalNotices,
            'preferences' => $this->preferences
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
