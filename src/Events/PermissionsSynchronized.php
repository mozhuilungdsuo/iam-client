<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Events;

use Nagaland\IamClient\DTOs\PermissionDefinition;

final readonly class PermissionsSynchronized
{
    /**
     * @param  list<PermissionDefinition>  $permissions
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public array $permissions,
        public array $response,
    ) {}
}
