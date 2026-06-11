<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nagaland\IamClient\Services\RoleDefinitionLoader;

final class RoleDefinitionController extends Controller
{
    public function __invoke(RoleDefinitionLoader $roles, ConfigRepository $config): JsonResponse
    {
        return response()->json([
            'application_code' => (string) $config->get('nagaland-iam.application_code'),
            'roles' => array_map(
                fn ($role): array => $role->toArray(),
                $roles->load(),
            ),
        ]);
    }
}
