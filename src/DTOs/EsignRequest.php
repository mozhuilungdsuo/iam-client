<?php

declare(strict_types=1);

namespace Nagaland\IamClient\DTOs;

final readonly class EsignRequest
{
    public function __construct(
        public string $transactionId,
        public string $clientReference,
        public string $status,
        public ?string $handoffUrl = null,
        public ?string $signerName = null,
        public ?string $failureReason = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            transactionId: (string) $payload['transaction_id'],
            clientReference: (string) $payload['client_reference'],
            status: (string) $payload['status'],
            handoffUrl: isset($payload['handoff_url']) ? (string) $payload['handoff_url'] : null,
            signerName: isset($payload['signer_name']) ? (string) $payload['signer_name'] : null,
            failureReason: isset($payload['failure_reason']) ? (string) $payload['failure_reason'] : null,
        );
    }

    public function completed(): bool
    {
        return $this->status === 'completed';
    }
}
