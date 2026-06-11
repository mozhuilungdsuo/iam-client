<?php

declare(strict_types=1);

namespace Nagaland\IamClient\DTOs;

final readonly class PermissionDefinition
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description = null,
    ) {}

    /**
     * @return array{code: string, name: string, description: string|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
