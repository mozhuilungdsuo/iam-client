<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nagaland\IamClient\Services\NagalandIamManager;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireRole
{
    public function __construct(private NagalandIamManager $iam) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        abort_unless($this->iam->hasRole($role), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
