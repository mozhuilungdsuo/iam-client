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
IAM_POST_LOGOUT_REDIRECT_URI="${APP_URL}"
IAM_APPLICATION_CODE=crs
IAM_CACHE_TTL=3600
IAM_VERIFY_ID_TOKEN=true
IAM_ID_TOKEN_LEEWAY=60
IAM_ESIGN_API_KEY=key_shown_once_in_iam_admin
IAM_ESIGN_COMPLETION_URL="${APP_URL}/esign/complete"
```

Use a unique `SESSION_COOKIE` for each local Laravel app. Browsers share cookies by hostname, not by port, so `localhost:8000` and `localhost:8001` will overwrite each other if both use Laravel's default `laravel-session` cookie.

By default, the package requests these OAuth scopes during login:

```text
openid profile email roles permissions govt_employee_details
```

You can change the requested scopes in `config/nagaland-iam.php`:

```php
'scopes' => [
    'openid',
    'profile',
    'email',
    'roles',
    'permissions',
    'govt_employee_details',
],
```

`IAM_VERIFY_ID_TOKEN=true` makes the package verify the OIDC `id_token` signature through the IAM server JWKS endpoint, then check issuer, audience, expiry, and subject before syncing the local user.

## IAM eSign broker

IAM owns the C-DAC private key, request XML, callback, and signed-document storage. Client applications must not install the C-DAC private key or post directly to C-DAC.

For a framework-neutral HTTP integration guide, see [IAM eSign Broker Integration Guide](docs/ESIGN_BROKER_INTEGRATION.md). It covers OAuth setup, API requests, browser handoff, callback validation, and downloads for non-Laravel applications.

To enable eSigning:

1. Allow `esign.request` and `esign.read` for the OAuth client in IAM.
2. Generate the client's eSign API key in IAM Admin and store the displayed value as `IAM_ESIGN_API_KEY`.
3. Register `IAM_ESIGN_COMPLETION_URL` as an OAuth redirect URI in IAM.
4. Run `php artisan migrate` in IAM so its `esign_transactions` table exists.

The browser does not need an IAM login during signing. The client starts the broker request using its server-side eSign API key, then redirects the browser to a single-use, 10-minute IAM handoff URL. IAM submits the signed request XML to C-DAC and receives the C-DAC callback. After completion, IAM redirects to the configured client completion URL.

### `EsignBrokerClient` API

`EsignBrokerClient` is registered as a Laravel singleton and can be type-hinted in a controller or resolved from the container. Each operation uses `IAM_ESIGN_API_KEY`:

| Method | Required scope | Purpose |
| --- | --- | --- |
| `create()` | `esign.request` allowed for the OAuth client | Upload a document and create an eSign transaction. |
| `status()` | `esign.read` allowed for the OAuth client | Retrieve the authoritative transaction state. |
| `download()` | `esign.read` allowed for the OAuth client | Retrieve the completed signed document bytes. |

Missing `IAM_URL`, `IAM_ESIGN_API_KEY`, `IAM_ESIGN_COMPLETION_URL`, or configured eSign endpoints cause an `IamConfigurationException`; non-successful IAM responses are raised by Laravel's HTTP client as `RequestException` instances.

The default endpoint configuration is:

```php
'esign' => [
    'api_key' => env('IAM_ESIGN_API_KEY'),
    'completion_url' => env('IAM_ESIGN_COMPLETION_URL'),
    'endpoints' => [
        'create' => '/api/esign/requests',
        'show' => '/api/esign/requests/{transaction}',
        'download' => '/api/esign/requests/{transaction}/download',
    ],
],
```

`create()` sends multipart form data. IAM accepts PDF, JPG, JPEG, and PNG uploads up to 20 MB. Its `clientReference` must be an application-owned ASCII alpha-dash identifier of at most 160 characters. It is idempotent for an OAuth client: repeating a reference returns its existing transaction instead of creating a second signing request.

`state` is an opaque value that IAM appends to the completion URL. Use a cryptographically random value and store it in the current browser session. The `direct` mode is the default and accepts `signatureFieldName`; `visible` mode may use `signerName`.

The following reference controller implements the full request, completion, and download flow. The default `direct` mode appends an invisible signature field without rebuilding the PDF, which preserves existing signatures. Replace the sample client-reference prefix and views with your application's equivalents.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Nagaland\IamClient\Services\EsignBrokerClient;
use Throwable;

final class EsignController extends Controller
{
    private const SESSION_KEY = 'client_esign_transactions';

    public function create(): View
    {
        return view('esign.create');
    }

    public function store(Request $request, EsignBrokerClient $esign): RedirectResponse
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'signature_field_name' => ['nullable', 'string', 'max:120'],
        ]);
        $state = Str::random(48);

        try {
            $transaction = $esign->create(
                document: $validated['document'],
                clientReference: 'sample-'.Str::uuid(),
                state: $state,
                mode: 'direct',
                signatureFieldName: $validated['signature_field_name'] ?? 'Client approval',
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return back()->withInput()->withErrors(['document' => $throwable->getMessage()]);
        }

        $transactions = $request->session()->get(self::SESSION_KEY, []);
        $transactions[$transaction->transactionId] = [
            'state' => $state,
            'filename' => $validated['document']->getClientOriginalName(),
        ];
        $request->session()->put(self::SESSION_KEY, $transactions);

        if ($transaction->handoffUrl === null) {
            return redirect()->route('esign.complete', [
                'esign_transaction_id' => $transaction->transactionId,
                'state' => $state,
            ]);
        }

        return redirect()->away($transaction->handoffUrl);
    }

    public function complete(Request $request, EsignBrokerClient $esign): View|RedirectResponse
    {
        $transactionId = (string) $request->query('esign_transaction_id', '');
        $transaction = $this->sessionTransaction($request, $transactionId);

        if ($transaction === null || ! hash_equals($transaction['state'], (string) $request->query('state', ''))) {
            abort(403, 'This eSign completion does not belong to the current browser session.');
        }

        try {
            $result = $esign->status($transactionId);
        } catch (Throwable $throwable) {
            report($throwable);

            return redirect()->route('esign.create')->withErrors(['document' => $throwable->getMessage()]);
        }

        return view('esign.complete', compact('result', 'transactionId'));
    }

    public function download(string $transactionId, Request $request, EsignBrokerClient $esign)
    {
        $transaction = $this->sessionTransaction($request, $transactionId);
        abort_if($transaction === null, 403);

        try {
            $result = $esign->status($transactionId);
            abort_unless($result->completed(), 409, 'The signed document is not ready.');
            $contents = $esign->download($transactionId);
        } catch (Throwable $throwable) {
            report($throwable);

            return redirect()->route('esign.complete', ['esign_transaction_id' => $transactionId, 'state' => $transaction['state']])
                ->withErrors(['document' => $throwable->getMessage()]);
        }

        $filename = pathinfo($transaction['filename'], PATHINFO_FILENAME).'-signed.pdf';

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** @return array{state: string, filename: string}|null */
    private function sessionTransaction(Request $request, string $transactionId): ?array
    {
        $transactions = $request->session()->get(self::SESSION_KEY, []);
        $transaction = is_array($transactions) ? ($transactions[$transactionId] ?? null) : null;

        return is_array($transaction) && isset($transaction['state'], $transaction['filename']) ? $transaction : null;
    }
}
```

Register the routes behind your normal Laravel application-authentication middleware. `IAM_ESIGN_COMPLETION_URL` must point to the named completion route.

```php
use App\Http\Controllers\EsignController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('esign', [EsignController::class, 'create'])->name('esign.create');
    Route::post('esign', [EsignController::class, 'store'])->name('esign.store');
    Route::get('esign/complete', [EsignController::class, 'complete'])->name('esign.complete');
    Route::get('esign/{transactionId}/download', [EsignController::class, 'download'])->name('esign.download');
});
```

The returned `EsignRequest` contains `transactionId`, `clientReference`, `status`, and—only for a newly created transaction—`handoffUrl`. A status response can additionally include `signerName` and `failureReason`. `completed()` is true only when `status` is `completed`. The controller validates the browser-session transaction and state before querying IAM, then downloads only completed documents.

During login the package verifies:

- the `id_token` is signed with `RS256`
- the signing key exists in `/.well-known/jwks.json`
- `iss` matches `IAM_URL`
- `aud` matches `IAM_CLIENT_ID`
- `exp`, `nbf`, and `iat` are valid within `IAM_ID_TOKEN_LEEWAY`
- `sub` matches the user returned by `/oauth/userinfo`

After changing `.env`, clear cached config:

```bash
php artisan optimize:clear
```

## IAM Server Setup

Before creating client applications, prepare the IAM server:

```bash
cd nagaland-iam
php artisan migrate
php artisan iam:oidc-keys
```

The migration adds per-client OAuth scope allowlists. The key command creates the RS256 key pair used for OIDC `id_token` signing and the JWKS endpoint.

In the IAM server admin panel:

1. Create or select an application, for example `crs`.
2. Create an OAuth client for that application.
3. Add this redirect URI:

```text
http://localhost:8001/iam/callback
```

4. Add this post logout redirect URI:

```text
http://localhost:8001
```

5. Enable these allowed scopes on the OAuth client:

```text
openid profile email roles permissions govt_employee_details esign.request esign.read
```

6. Copy the generated `client_id` and plain client secret into the client app `.env`.
7. Assign users to the application and give them roles/permissions.

For production, run Laravel's scheduler so expired OAuth records are pruned:

```bash
php artisan schedule:work
```

Or configure cron to run:

```bash
php artisan schedule:run
```

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

`iam.auth` only checks the local Laravel session for a stored IAM token. It does not call IAM on every request.

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
$iamUser = NagalandIam::iamUser();
$govtEmpProfile = NagalandIam::govtEmpProfile();
$roles = NagalandIam::roles();
$permissions = NagalandIam::permissions();

if (NagalandIam::hasPermission('crs.birth.approve')) {
    // ...
}

NagalandIam::setIamActive(true);  // activate the current local user
NagalandIam::setIamActive(false); // deactivate the current local user

$active = NagalandIam::isIamActive();
```

`NagalandIam::user()` returns the local Laravel user. `NagalandIam::iamUser()` returns the full IAM userinfo payload stored during login.

When the `govt_employee_details` scope is granted, `NagalandIam::govtEmpProfile()` returns:

```php
[
    'designation' => 'Registrar',
    'department' => 'Home Department',
    'office' => 'Kohima HQ',
    'pims_code' => 'PIMS-123',
]
```

It returns `null` when IAM did not send a government employee profile.

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
- `403` on roles or permissions: make sure the OAuth client allows `roles` and `permissions`, then log out and log in again.
- Missing government employee profile data: make sure the OAuth client allows `govt_employee_details` and the package requests that scope, then log out and log in again.
- Missing `id_token` or JWKS errors: run `php artisan iam:oidc-keys` on the IAM server.
- `Invalid OAuth client credentials`: rotate or recreate the IAM OAuth client secret and update `IAM_CLIENT_SECRET`.
- `redirect_uri is not registered`: add the exact `IAM_REDIRECT_URI` value to the OAuth client in IAM.
