<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nagaland\IamClient\Services\NagalandIamManager;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequirePermission
{
    public function __construct(private NagalandIamManager $iam) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless($this->iam->hasPermission($permission), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
