<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Commands;

use Illuminate\Console\Command;
use Nagaland\IamClient\Services\OAuthIamClient;

final class HealthCheckCommand extends Command
{
    protected $signature = 'iam:health-check';

    protected $description = 'Check Nagaland IAM endpoint reachability.';

    public function handle(OAuthIamClient $client): int
    {
        $results = $client->health();

        foreach ($results as $name => $result) {
            $ok = ($result['ok'] ?? false) === true;
            $this->line(sprintf(
                '%s: %s',
                $name,
                $ok ? '<info>ok</info>' : '<error>failed</error>',
            ));
        }

        return collect($results)->every(fn (array $result): bool => ($result['ok'] ?? false) === true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
