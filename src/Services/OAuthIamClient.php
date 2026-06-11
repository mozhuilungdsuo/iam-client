<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Nagaland\IamClient\Contracts\IamClient;
use Nagaland\IamClient\DTOs\IamUser;
use Nagaland\IamClient\DTOs\PermissionDefinition;
use Nagaland\IamClient\DTOs\TokenSet;
use Nagaland\IamClient\Exceptions\IamConfigurationException;
use Nagaland\IamClient\Exceptions\IamHttpException;

final readonly class OAuthIamClient implements IamClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private HttpFactory $http,
        private array $config,
    ) {}

    /**
     * @param  array<string, string>  $query
     */
    public function authorizeUrl(array $query): string
    {
        return $this->url($this->endpoint('authorize')).'?'.http_build_query($query);
    }

    public function exchangeAuthorizationCode(string $code, string $codeVerifier): TokenSet
    {
        $payload = $this->baseTokenPayload() + [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) $this->config['redirect_uri'],
            'code_verifier' => $codeVerifier,
        ];

        return TokenSet::fromArray($this->request()->asForm()->post($this->endpoint('token'), $payload)->throw()->json());
    }

    public function refreshToken(string $refreshToken): TokenSet
    {
        $payload = $this->baseTokenPayload() + [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];

        return TokenSet::fromArray($this->request()->asForm()->post($this->endpoint('token'), $payload)->throw()->json());
    }

    public function userInfo(TokenSet $tokens): IamUser
    {
        return IamUser::fromArray($this->request($tokens)->get($this->endpoint('userinfo'))->throw()->json());
    }

    public function roles(TokenSet $tokens): array
    {
        return $this->stringList($this->request($tokens)->get($this->endpoint('roles'))->throw()->json(), 'roles');
    }

    public function permissions(TokenSet $tokens): array
    {
        return $this->stringList($this->request($tokens)->get($this->endpoint('permissions'))->throw()->json(), 'permissions');
    }

    public function syncPermissions(array $permissions): array
    {
        $payload = $this->baseTokenPayload() + [
            'application_code' => (string) $this->config['application_code'],
            'permissions' => array_map(
                fn (PermissionDefinition $permission): array => $permission->toArray(),
                $permissions,
            ),
        ];

        return $this->request()->post($this->endpoint('permissions_sync'), $payload)->throw()->json();
    }

    public function logout(TokenSet $tokens): void
    {
        $this->request($tokens)->post($this->endpoint('logout'))->throw();
    }

    public function health(): array
    {
        $results = [];

        foreach (['discovery', 'userinfo', 'permissions_sync'] as $name) {
            try {
                $response = $this->request()->get($this->endpoint($name));
                $results[$name] = ['ok' => $response->successful(), 'status' => $response->status()];
            } catch (\Throwable $throwable) {
                $results[$name] = ['ok' => false, 'error' => $throwable->getMessage()];
            }
        }

        return $results;
    }

    private function request(?TokenSet $tokens = null): PendingRequest
    {
        $request = $this->http
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->throw(fn ($response) => throw new IamHttpException(
                message: 'IAM request failed with status '.$response->status().'.',
                code: $response->status(),
            ));

        return $tokens ? $request->withToken($tokens->accessToken) : $request;
    }

    /**
     * @return array<string, string>
     */
    private function baseTokenPayload(): array
    {
        return [
            'client_id' => (string) $this->config['client_id'],
            'client_secret' => (string) $this->config['client_secret'],
        ];
    }

    private function endpoint(string $name): string
    {
        $endpoint = Arr::get($this->config, "endpoints.{$name}");

        if (! is_string($endpoint) || $endpoint === '') {
            throw new IamConfigurationException("Missing IAM endpoint [{$name}].");
        }

        return $endpoint;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl(), '/').'/'.ltrim($path, '/');
    }

    private function baseUrl(): string
    {
        $url = $this->config['iam_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new IamConfigurationException('IAM_URL is not configured.');
        }

        return rtrim($url, '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function stringList(array $payload, string $key): array
    {
        $items = $payload[$key] ?? $payload['data'] ?? $payload;

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $items));
    }
}
