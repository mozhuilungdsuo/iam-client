<?php

declare(strict_types=1);

namespace Nagaland\IamClient\DTOs;

final readonly class EsignArtifacts
{
    public function __construct(
        public string $transactionId,
        public string $status,
        public ?string $esignXml,
        public ?string $esignResponseXml,
        public ?string $uidToken,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            transactionId: (string) $payload['transaction_id'],
            status: (string) $payload['status'],
            esignXml: isset($payload['esign_xml']) ? (string) $payload['esign_xml'] : null,
            esignResponseXml: isset($payload['esign_response_xml']) ? (string) $payload['esign_response_xml'] : null,
            uidToken: isset($payload['uid_token']) ? (string) $payload['uid_token'] : null,
        );
    }
}
