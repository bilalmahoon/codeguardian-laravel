<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel;

use CodeGuardian\Laravel\Commands\AnalyzeCommand;
use CodeGuardian\Laravel\Commands\GenerateTestsCommand;
use CodeGuardian\Laravel\Commands\PerformanceScanCommand;
use CodeGuardian\Laravel\Commands\RefactorCommand;
use CodeGuardian\Laravel\Commands\ReportCommand;
use CodeGuardian\Laravel\Commands\SecurityScanCommand;
use Illuminate\Support\ServiceProvider;

class CodeGuardianServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/codeguardian.php',
            'codeguardian'
        );

        $this->app->singleton(CodeGuardian::class, fn($app) => new CodeGuardian($app));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__ . '/../config/codeguardian.php' => config_path('codeguardian.php'),
            ], 'codeguardian-config');

            // Register artisan commands
            $this->commands([
                AnalyzeCommand::class,
                SecurityScanCommand::class,
                PerformanceScanCommand::class,
                GenerateTestsCommand::class,
                RefactorCommand::class,
                ReportCommand::class,
            ]);
        }
    }
}
