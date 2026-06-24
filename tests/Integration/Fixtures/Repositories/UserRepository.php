<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration\Fixtures\Repositories;

/**
 * Repository injected by AuthService.
 * Leaf node — no constructor dependencies.
 * Verifies 2-hop tracing: ApiAuthController → AuthService → UserRepository.
 */
class UserRepository
{
    public function findByEmail(string $email): ?array
    {
        return null;
    }
}
