<?php

declare(strict_types=1);

namespace Nagaland\IamClient\DTOs;

use Carbon\CarbonImmutable;

final readonly class TokenSet
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?string $idToken,
        public ?CarbonImmutable $expiresAt,
        public string $tokenType = 'Bearer',
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $expiresIn = isset($payload['expires_in']) ? (int) $payload['expires_in'] : null;

        return new self(
            accessToken: (string) $payload['access_token'],
            refreshToken: isset($payload['refresh_token']) ? (string) $payload['refresh_token'] : null,
            idToken: isset($payload['id_token']) ? (string) $payload['id_token'] : null,
            expiresAt: $expiresIn ? CarbonImmutable::now()->addSeconds($expiresIn) : null,
            tokenType: (string) ($payload['token_type'] ?? 'Bearer'),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toSession(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'id_token' => $this->idToken,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'token_type' => $this->tokenType,
        ];
    }

    /**
     * @param  array<string, string|null>  $payload
     */
    public static function fromSession(array $payload): self
    {
        return new self(
            accessToken: (string) $payload['access_token'],
            refreshToken: $payload['refresh_token'] ?? null,
            idToken: $payload['id_token'] ?? null,
            expiresAt: isset($payload['expires_at']) && $payload['expires_at'] !== null
                ? CarbonImmutable::parse($payload['expires_at'])
                : null,
            tokenType: $payload['token_type'] ?? 'Bearer',
        );
    }

    public function expired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->isPast();
    }
}
