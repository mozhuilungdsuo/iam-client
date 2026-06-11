<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Events;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class PermissionsRefreshed
{
    public function __construct(public Authenticatable $user) {}
}
