<?php

declare(strict_types=1);

namespace Nagaland\IamClient\DTOs;

final readonly class RoleDefinition
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description = null,
        public array $permissions = [],
    ) {}

    /**
     * @return array{code: string, name: string, description: string|null, permissions: list<string>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'permissions' => $this->permissions,
        ];
    }
}
