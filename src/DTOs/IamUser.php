<?php

declare(strict_types=1);

namespace Nagaland\IamClient\DTOs;

final readonly class IamUser
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: (string) ($payload['sub'] ?? $payload['id'] ?? $payload['iam_user_id']),
            name: (string) ($payload['name'] ?? ''),
            email: (string) ($payload['email'] ?? ''),
            attributes: $payload,
        );
    }
}
