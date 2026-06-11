<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nagaland\IamClient\Services\NagalandIamManager;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateWithIam
{
    public function __construct(private NagalandIamManager $iam) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->iam->user() === null || $this->iam->tokens() === null) {
            return redirect()->route('iam.login');
        }

        return $next($request);
    }
}
