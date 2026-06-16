<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array analyze(string $path, string $projectType = 'laravel', array|string $agents = 'all')
 * @method static array security(string $path, string $projectType = 'laravel')
 * @method static array performance(string $path, string $projectType = 'laravel')
 * @method static array generateTests(string $path, string $projectType = 'laravel')
 * @method static array scan(string $path, string $projectType = 'laravel')
 *
 * @see \CodeGuardian\Laravel\CodeGuardian
 */
class CodeGuardian extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \CodeGuardian\Laravel\CodeGuardian::class;
    }
}
