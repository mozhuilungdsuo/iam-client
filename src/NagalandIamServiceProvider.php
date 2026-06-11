<?php

declare(strict_types=1);

namespace Nagaland\IamClient;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nagaland\IamClient\Commands\ClearIamCacheCommand;
use Nagaland\IamClient\Commands\HealthCheckCommand;
use Nagaland\IamClient\Commands\SyncPermissionsCommand;
use Nagaland\IamClient\Contracts\IamClient;
use Nagaland\IamClient\Middleware\AuthenticateWithIam;
use Nagaland\IamClient\Middleware\RequirePermission;
use Nagaland\IamClient\Middleware\RequireRole;
use Nagaland\IamClient\Services\IdTokenVerifier;
use Nagaland\IamClient\Services\NagalandIamManager;
use Nagaland\IamClient\Services\OAuthIamClient;
use Nagaland\IamClient\Services\PermissionRepository;
use Nagaland\IamClient\Services\UserSynchronizer;

final class NagalandIamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nagaland-iam.php', 'nagaland-iam');

        if (! $this->app['config']->has('iam-roles')) {
            $this->app['config']->set('iam-roles', require __DIR__.'/../config/iam-roles.php');
        }

        $this->app->singleton(IdTokenVerifier::class, fn ($app): IdTokenVerifier => new IdTokenVerifier(
            http: $app->make(HttpFactory::class),
            config: $app['config']->get('nagaland-iam'),
        ));

        $this->app->singleton(OAuthIamClient::class, fn ($app): OAuthIamClient => new OAuthIamClient(
            http: $app->make(HttpFactory::class),
            verifier: $app->make(IdTokenVerifier::class),
            config: $app['config']->get('nagaland-iam'),
        ));

        $this->app->alias(OAuthIamClient::class, IamClient::class);

        $this->app->singleton(UserSynchronizer::class, fn ($app): UserSynchronizer => new UserSynchronizer(
            userModel: $app['config']->get('nagaland-iam.user_model'),
        ));

        $this->app->singleton(PermissionRepository::class, fn ($app): PermissionRepository => new PermissionRepository(
            cache: $app->make(CacheRepository::class),
            client: $app->make(OAuthIamClient::class),
            ttl: (int) $app['config']->get('nagaland-iam.cache_ttl', 3600),
        ));

        $this->app->singleton(NagalandIamManager::class, fn ($app): NagalandIamManager => new NagalandIamManager(
            auth: $app['auth'],
            session: $app['session'],
            permissions: $app->make(PermissionRepository::class),
            client: $app->make(OAuthIamClient::class),
            config: $app['config']->get('nagaland-iam'),
        ));

        $this->app->alias(NagalandIamManager::class, 'nagaland-iam');
    }

    public function boot(Router $router): void
    {
        $this->publishes([
            __DIR__.'/../config/nagaland-iam.php' => config_path('nagaland-iam.php'),
            __DIR__.'/../config/iam-roles.php' => config_path('iam-roles.php'),
        ], 'nagaland-iam-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2026_01_01_000000_add_iam_columns_to_users_table.php' => database_path('migrations/2026_01_01_000000_add_iam_columns_to_users_table.php'),
            __DIR__.'/../database/migrations/2026_01_01_000001_add_iam_active_column_to_users_table.php' => database_path('migrations/2026_01_01_000001_add_iam_active_column_to_users_table.php'),
        ], 'nagaland-iam-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $router->aliasMiddleware('iam.auth', AuthenticateWithIam::class);
        $router->aliasMiddleware('iam.role', RequireRole::class);
        $router->aliasMiddleware('iam.permission', RequirePermission::class);

        if ((bool) config('nagaland-iam.routes.enabled', true)) {
            Route::middleware(config('nagaland-iam.routes.middleware', ['web']))
                ->prefix(config('nagaland-iam.routes.prefix', 'iam'))
                ->group(__DIR__.'/../routes/web.php');
        }

        $this->registerGates();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncPermissionsCommand::class,
                ClearIamCacheCommand::class,
                HealthCheckCommand::class,
            ]);
        }
    }

    private function registerGates(): void
    {
        /** @var array<string, array{name?: string, description?: string|null}> $permissions */
        $permissions = config('iam-permissions', []);

        foreach (array_keys($permissions) as $permission) {
            Gate::define((string) $permission, fn (): bool => app('nagaland-iam')->hasPermission((string) $permission));
        }
    }
}
