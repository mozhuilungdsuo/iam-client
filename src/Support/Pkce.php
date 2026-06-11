<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Support;

use Illuminate\Support\Str;

final class Pkce
{
    public function verifier(): string
    {
        return Str::random(96);
    }

    public function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
