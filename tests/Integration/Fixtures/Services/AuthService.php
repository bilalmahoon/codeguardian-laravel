<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration\Fixtures\Services;

use CodeGuardian\Laravel\Tests\Integration\Fixtures\Repositories\UserRepository;

/**
 * Service injected by ApiAuthController.
 * Also injects UserRepository — verifies 2-hop (Controller → Service → Repository) tracing.
 */
class AuthService
{
    public function __construct(
        private UserRepository $userRepository,
    ) {}

    public function authenticate(string $email, string $password): ?array
    {
        return $this->userRepository->findByEmail($email);
    }
}
