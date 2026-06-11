<?php

declare(strict_types=1);
use App\Models\User;

return [
    'driver' => env('IAM_DRIVER', 'oauth'),

    'iam_url' => env('IAM_URL'),

    'client_id' => env('IAM_CLIENT_ID'),

    'client_secret' => env('IAM_CLIENT_SECRET'),

    'redirect_uri' => env('IAM_REDIRECT_URI'),

    'application_code' => env('IAM_APPLICATION_CODE'),

    'cache_ttl' => (int) env('IAM_CACHE_TTL', 3600),

    'id_token' => [
        'verify' => env('IAM_VERIFY_ID_TOKEN', true),
        'leeway' => (int) env('IAM_ID_TOKEN_LEEWAY', 60),
    ],

    'routes' => [
        'enabled' => env('IAM_ROUTES_ENABLED', true),
        'prefix' => env('IAM_ROUTE_PREFIX', 'iam'),
        'middleware' => ['web'],
    ],

    'endpoints' => [
        'authorize' => '/oauth/authorize',
        'token' => '/oauth/token',
        'userinfo' => '/oauth/userinfo',
        'permissions_sync' => '/api/permissions/sync',
        'roles' => '/api/me/roles',
        'permissions' => '/api/me/permissions',
        'refresh' => '/api/token/refresh',
        'logout' => '/api/logout',
        'discovery' => '/.well-known/openid-configuration',
        'jwks' => '/.well-known/jwks.json',
    ],

    'session' => [
        'user' => 'nagaland_iam.user',
        'tokens' => 'nagaland_iam.tokens',
        'pkce_verifier' => 'nagaland_iam.pkce_verifier',
        'state' => 'nagaland_iam.state',
    ],

    'user_model' => env('AUTH_MODEL', User::class),
];
