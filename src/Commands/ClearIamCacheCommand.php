<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Commands;

use Illuminate\Console\Command;
use Nagaland\IamClient\Services\NagalandIamManager;

final class ClearIamCacheCommand extends Command
{
    protected $signature = 'iam:clear-cache';

    protected $description = 'Clear cached Nagaland IAM roles and permissions for the current session user.';

    public function handle(NagalandIamManager $iam): int
    {
        $iam->refreshPermissions();

        $this->components->info('IAM cache cleared.');

        return self::SUCCESS;
    }
}
