<?php

namespace Jumpgroup\Avacy;

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
        $this->fields = $fields;
        $this->identifier = $identifier;
        $this->ipAddress = $ipAddress;
        $this->proofs = $proofs;
        $this->legalNotices = $legalNotices;
        $this->preferences = $preferences;
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
}
