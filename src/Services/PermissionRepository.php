<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Nagaland\IamClient\DTOs\TokenSet;

final readonly class PermissionRepository
{
    public function __construct(
        private CacheRepository $cache,
        private OAuthIamClient $client,
        private int $ttl,
    ) {}

    /**
     * @return list<string>
     */
    public function roles(Authenticatable $user, TokenSet $tokens): array
    {
        return $this->cache->remember(
            $this->key($user, 'roles'),
            $this->ttl,
            fn (): array => $this->client->roles($tokens),
        );
    }

    /**
     * @return list<string>
     */
    public function permissions(Authenticatable $user, TokenSet $tokens): array
    {
        return $this->cache->remember(
            $this->key($user, 'permissions'),
            $this->ttl,
            fn (): array => $this->client->permissions($tokens),
        );
    }

    public function clear(?Authenticatable $user = null): void
    {
        if ($user === null) {
            return;
        }

        $this->cache->forget($this->key($user, 'roles'));
        $this->cache->forget($this->key($user, 'permissions'));
    }

    private function key(Authenticatable $user, string $type): string
    {
        return 'nagaland_iam:'.$type.':'.(string) $user->getAuthIdentifier();
    }
}
