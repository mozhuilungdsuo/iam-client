<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Services;

use Nagaland\IamClient\DTOs\RoleDefinition;

final class RoleDefinitionLoader
{
    /**
     * @return list<RoleDefinition>
     */
    public function load(): array
    {
        /** @var array<string, array{name?: string, description?: string|null, permissions?: array<int, string>}> $definitions */
        $definitions = config('iam-roles', []);

        $roles = [];

        foreach ($definitions as $code => $metadata) {
            $roles[] = new RoleDefinition(
                code: (string) $code,
                name: (string) ($metadata['name'] ?? $code),
                description: isset($metadata['description']) ? (string) $metadata['description'] : null,
                permissions: array_values(array_map('strval', $metadata['permissions'] ?? [])),
            );
        }

        return $roles;
    }
}
