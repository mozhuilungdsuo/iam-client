# Nagaland IAM Client

Laravel client package for applications that authenticate through the Nagaland IAM authorization server.

The package provides:

- OAuth2 authorization-code login with PKCE
- IAM callback/logout routes
- local user synchronization by IAM user id
- role and permission middleware
- cached role/permission lookup
- permission synchronization from the client application to IAM
- a facade for checking the current IAM user, roles, and permissions

## Requirements

- PHP 8.4+
- Laravel 13
- A running Nagaland IAM server
- An OAuth client registered in IAM for this application

## Install

 install the package:

```bash
composer require mozhuilungdsuo/iam-client
```

Laravel auto-discovers the service provider and facade.


## Publish Package Files

```bash
php artisan vendor:publish --tag=nagaland-iam-config
php artisan vendor:publish --tag=nagaland-iam-migrations
php artisan migrate
```

The migrations add `iam_user_id` and `is_iam_active` to the local `users` table. Synced IAM users are created with `is_iam_active = false` by default; the consuming client application decides when to activate them. The local application still has a user record for sessions and Laravel auth, but IAM remains the source of truth for identities, roles, and permissions.

## Environment

Add these values to the consuming application's `.env`:

```dotenv
APP_URL=http://localhost:8001

SESSION_DRIVER=database
SESSION_COOKIE=my_app_iam_client_session

IAM_DRIVER=oauth
IAM_URL=http://localhost:8000
IAM_CLIENT_ID=iam_client_id_from_iam
IAM_CLIENT_SECRET=plain_secret_shown_once_by_iam
IAM_REDIRECT_URI="${APP_URL}/iam/callback"
IAM_APPLICATION_CODE=crs
IAM_CACHE_TTL=3600
```

Use a unique `SESSION_COOKIE` for each local Laravel app. Browsers share cookies by hostname, not by port, so `localhost:8000` and `localhost:8001` will overwrite each other if both use Laravel's default `laravel-session` cookie.

The package requests these OAuth scopes during login:

```text
openid profile email roles permissions
```

After changing `.env`, clear cached config:

```bash
php artisan optimize:clear
```

## IAM Server Setup

In the IAM server admin panel:

1. Create or select an application, for example `crs`.
2. Create an OAuth client for that application.
3. Add this redirect URI:

```text
http://localhost:8001/iam/callback
```

4. Copy the generated `client_id` and plain client secret into the client app `.env`.
5. Assign users to the application and give them roles/permissions.

For local development, keep hostnames consistent. Prefer `localhost` everywhere or `127.0.0.1` everywhere; do not mix them.

## Routes

The package registers these routes by default under the `iam` prefix:

```text
GET  /iam/login
GET  /iam/callback
POST /iam/logout
GET  /iam/role-definitions
```

You can change the prefix or disable route registration in `config/nagaland-iam.php`:

```php
'routes' => [
    'enabled' => true,
    'prefix' => 'iam',
    'middleware' => ['web'],
],
```

## Redirect Guests To IAM

In a Laravel 13 app, redirect unauthenticated users to the package login route from `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(fn (Request $request): string => route('iam.login'));
})
```

Then visiting an auth-protected page like `/dashboard` will start IAM login automatically.

## Protect Routes

Use normal Laravel auth middleware plus IAM role/permission middleware:

```php
Route::middleware(['auth', 'iam.permission:crs.birth.create'])->group(function (): void {
    Route::post('/birth-records', StoreBirthRecordController::class);
});

Route::middleware(['auth', 'iam.role:CRS.Registrar'])->group(function (): void {
    Route::get('/registrar', RegistrarDashboardController::class);
});
```

For routes that must specifically require an active IAM session:

```php
Route::middleware(['iam.auth'])->get('/iam-only', IamOnlyController::class);
```

## Define Permissions

Create `config/iam-permissions.php` in the consuming app:

```php
<?php

declare(strict_types=1);

return [
    'crs.birth.create' => [
        'name' => 'Create Birth Record',
        'description' => 'Create birth records',
    ],

    'crs.birth.approve' => [
        'name' => 'Approve Birth Record',
        'description' => 'Approve birth records',
    ],
];
```

Sync them to IAM:

```bash
php artisan iam:sync-permissions
```

## Define Roles

Publish or create `config/iam-roles.php` in the consuming app:

```bash
php artisan vendor:publish --tag=nagaland-iam-config
```

```php
<?php

declare(strict_types=1);

return [
    'CRS.Registrar' => [
        'name' => 'CRS Registrar',
        'description' => 'Can create and edit civil registration records.',
        'permissions' => [
            'crs.birth.create',
            'crs.birth.edit',
        ],
    ],
];
```

The package exposes these definitions at:

```text
GET /iam/role-definitions
```

IAM can use that endpoint to fetch role codes, names, descriptions, and suggested permission codes while creating roles for the application.
The endpoint accepts requests that include the configured `IAM_CLIENT_ID` in the `X-IAM-Client-Id` header.

The package also registers Laravel gates for each permission code in `config/iam-permissions.php`, so you can use:

```php
Gate::allows('crs.birth.create');

@can('crs.birth.create')
    ...
@endcan
```

## Facade Usage

```php
use Nagaland\IamClient\Facades\NagalandIam;

$user = NagalandIam::user();
$roles = NagalandIam::roles();
$permissions = NagalandIam::permissions();

if (NagalandIam::hasPermission('crs.birth.approve')) {
    // ...
}

NagalandIam::setIamActive(true);  // activate the current local user
NagalandIam::setIamActive(false); // deactivate the current local user

$active = NagalandIam::isIamActive();
```

## Commands

```bash
php artisan iam:health-check
php artisan iam:sync-permissions
php artisan iam:clear-cache
```

## Local Run Example

Run the IAM server:

```bash
cd ../nagaland-iam
php artisan serve --host=localhost --port=8000
```

Run the client app:

```bash
cd ../nagaland-iam-client
php artisan serve --host=localhost --port=8001
```

Open:

```text
http://localhost:8001/dashboard
```

The browser should redirect to IAM, then back to:

```text
http://localhost:8001/iam/callback
```

and finally to the client dashboard.

## Troubleshooting

- `404` on `/oauth/authorize`: make sure the IAM server is running, not the client app, on the URL in `IAM_URL`.
- `400` on `/iam/callback`: clear cookies and confirm both apps use unique `SESSION_COOKIE` names.
- Login loops: keep `APP_URL`, `IAM_URL`, and OAuth redirect URI on the same hostname style, for example all `localhost`.
- `Invalid OAuth client credentials`: rotate or recreate the IAM OAuth client secret and update `IAM_CLIENT_SECRET`.
- `redirect_uri is not registered`: add the exact `IAM_REDIRECT_URI` value to the OAuth client in IAM.
