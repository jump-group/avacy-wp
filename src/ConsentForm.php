<?php

namespace Jumpgroup\Avacy;

class ConsentForm
{
    private string $name;
    private string $mail;
    private string $identifier;
    private string $ipAddress;
    private string $proofs;
    private array $legalNotices;
    private array $preferences;

    public function __construct(
        $name,
        $mail,
        $identifier,
        $ipAddress,
        $proofs,
        $legalNotices,
        $preferences
    ) {
        $this->name = $name;
        $this->mail = $mail;
        $this->identifier = $identifier;
        $this->ipAddress = $ipAddress;
        $this->proofs = $proofs;
        $this->legalNotices = $legalNotices;
        $this->preferences = $preferences;
    }

    public function getPayload(): array
    {
        return [
            'subject' => [
                'email' => $this->mail,
                'name' => $this->name,
            ],
            'identifier' => $this->identifier,
            'ip_address' => $this->ipAddress,
            'proofs' => $this->proofs,
            'legal_notices' => $this->legalNotices,
            'preferences' => $this->preferences
        ];
    }
}
