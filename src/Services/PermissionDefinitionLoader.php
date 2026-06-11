<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Services;

use Nagaland\IamClient\DTOs\PermissionDefinition;

final class PermissionDefinitionLoader
{
    /**
     * @return list<PermissionDefinition>
     */
    public function load(): array
    {
        /** @var array<string, array{name?: string, description?: string|null}> $definitions */
        $definitions = config('iam-permissions', []);

        $permissions = [];

        foreach ($definitions as $code => $metadata) {
            $permissions[] = new PermissionDefinition(
                code: (string) $code,
                name: (string) ($metadata['name'] ?? $code),
                description: isset($metadata['description']) ? (string) $metadata['description'] : null,
            );
        }

        return $permissions;
    }
}
