<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration\Fixtures\Services;

/**
 * Second service injected by ApiAuthController.
 * No further dependencies — verifies the tracer handles leaf nodes cleanly.
 */
class TokenService
{
    public function generate(string $userId): string
    {
        return 'token_' . $userId;
    }
}
