<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Nagaland\IamClient\DTOs\IamUser;

final readonly class UserAuthenticated
{
    public function __construct(
        public Authenticatable $user,
        public IamUser $iamUser,
    ) {}
}
