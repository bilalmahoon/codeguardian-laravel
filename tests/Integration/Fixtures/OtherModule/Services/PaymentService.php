<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration\Fixtures\OtherModule\Services;

/**
 * Lives in a DIFFERENT module.
 * Must NEVER appear in scope when refactoring the Auth module.
 */
class PaymentService
{
    public function charge(float $amount): bool
    {
        return true;
    }
}
