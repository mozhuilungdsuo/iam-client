<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Session\SessionManager;
use Nagaland\IamClient\DTOs\TokenSet;
use Nagaland\IamClient\Events\PermissionsRefreshed;

readonly class NagalandIamManager
{
    public function __construct(
        private AuthFactory $auth,
        private SessionManager $session,
        private PermissionRepository $permissions,
        private OAuthIamClient $client,
        private array $config,
    ) {}

    public function user(): ?Authenticatable
    {
        return $this->auth->guard()->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function iamUser(): array
    {
        $payload = $this->session->get($this->config['session']['user']);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function govtEmpProfile(): ?array
    {
        $profile = $this->iamUser()['govt_emp_profile'] ?? null;

        return is_array($profile) ? $profile : null;
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        $user = $this->user();
        $tokens = $this->tokens();

        return $user && $tokens ? $this->permissions->roles($user, $tokens) : [];
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        $user = $this->user();
        $tokens = $this->tokens();

        return $user && $tokens ? $this->permissions->permissions($user, $tokens) : [];
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions();

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function isIamActive(?Authenticatable $user = null): bool
    {
        $user ??= $this->user();

        return $user instanceof Model
            && (bool) $user->getAttribute('is_iam_active');
    }

    public function setIamActive(bool $active, ?Authenticatable $user = null): bool
    {
        $user ??= $this->user();

        if (! $user instanceof Model) {
            return false;
        }

        $user->forceFill(['is_iam_active' => $active])->save();

        return true;
    }

    public function refreshPermissions(): void
    {
        $user = $this->user();

        $this->permissions->clear($user);

        if ($user !== null) {
            event(new PermissionsRefreshed($user));
        }
    }

    public function logout(): void
    {
        $tokens = $this->tokens();

        if ($tokens !== null) {
            $this->client->logout($tokens);
        }

        $this->session->forget($this->config['session']['tokens']);
        $this->session->forget($this->config['session']['user']);
        $this->auth->guard()->logout();
        $this->session->invalidate();
        $this->session->regenerateToken();
    }

    public function tokens(): ?TokenSet
    {
        $payload = $this->session->get($this->config['session']['tokens']);

        return is_array($payload) && isset($payload['access_token']) ? TokenSet::fromSession($payload) : null;
    }
}
