<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null user()
 * @method static array<string, mixed> iamUser()
 * @method static array<string, mixed>|null govtEmpProfile()
 * @method static list<string> roles()
 * @method static list<string> permissions()
 * @method static bool hasRole(string $role)
 * @method static bool hasPermission(string $permission)
 * @method static bool isIamActive(\Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool setIamActive(bool $active, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static string logout()
 * @method static void refreshPermissions()
 */
final class NagalandIam extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'nagaland-iam';
    }
}
