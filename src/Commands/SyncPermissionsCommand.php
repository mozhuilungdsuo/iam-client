<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Commands;

use Illuminate\Console\Command;
use Nagaland\IamClient\Events\PermissionsSynchronized;
use Nagaland\IamClient\Services\OAuthIamClient;
use Nagaland\IamClient\Services\PermissionDefinitionLoader;

final class SyncPermissionsCommand extends Command
{
    protected $signature = 'iam:sync-permissions';

    protected $description = 'Synchronize local permission definitions with Nagaland IAM.';

    public function handle(PermissionDefinitionLoader $loader, OAuthIamClient $client): int
    {
        $permissions = $loader->load();
        $response = $client->syncPermissions($permissions);

        event(new PermissionsSynchronized($permissions, $response));

        $this->components->info('Synchronized '.count($permissions).' IAM permissions.');

        return self::SUCCESS;
    }
}
