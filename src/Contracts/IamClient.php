<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Contracts;

use Nagaland\IamClient\DTOs\IamUser;
use Nagaland\IamClient\DTOs\PermissionDefinition;
use Nagaland\IamClient\DTOs\TokenSet;

interface IamClient
{
    /**
     * @param  array<string, string>  $query
     */
    public function authorizeUrl(array $query): string;

    public function exchangeAuthorizationCode(string $code, string $codeVerifier): TokenSet;

    public function refreshToken(string $refreshToken): TokenSet;

    public function userInfo(TokenSet $tokens): IamUser;

    /**
     * @return list<string>
     */
    public function roles(TokenSet $tokens): array;

    /**
     * @return list<string>
     */
    public function permissions(TokenSet $tokens): array;

    /**
     * @param  list<PermissionDefinition>  $permissions
     * @return array<string, mixed>
     */
    public function syncPermissions(array $permissions): array;

    public function logout(TokenSet $tokens): void;

    public function endSessionUrl(?string $idTokenHint = null, ?string $postLogoutRedirectUri = null, ?string $state = null): string;

    /**
     * @return array<string, mixed>
     */
    public function health(): array;
}
