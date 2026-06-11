<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nagaland\IamClient\Services\RoleDefinitionLoader;
use Symfony\Component\HttpFoundation\Response;

final class RoleDefinitionController extends Controller
{
    public function __invoke(Request $request, RoleDefinitionLoader $roles, ConfigRepository $config): JsonResponse
    {
        $clientId = $config->get('nagaland-iam.client_id');

        abort_unless(
            is_string($clientId) && $clientId !== '',
            Response::HTTP_SERVICE_UNAVAILABLE,
            'IAM client id is not configured.',
        );

        abort_unless(
            hash_equals($clientId, (string) $request->header('X-IAM-Client-Id')),
            Response::HTTP_UNAUTHORIZED,
            'Invalid IAM client id.',
        );

        return response()->json([
            'application_code' => (string) $config->get('nagaland-iam.application_code'),
            'roles' => array_map(
                fn ($role): array => $role->toArray(),
                $roles->load(),
            ),
        ]);
    }
}
