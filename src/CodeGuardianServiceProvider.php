<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel;

use CodeGuardian\Laravel\Analyzers\ArchitectureAnalyzer;
use CodeGuardian\Laravel\Analyzers\PerformanceAnalyzer;
use CodeGuardian\Laravel\Analyzers\SecurityAnalyzer;
use CodeGuardian\Laravel\Analyzers\StaticOrchestrator;
use CodeGuardian\Laravel\Analyzers\StaticTestGenerator;
use CodeGuardian\Laravel\Analyzers\TechDebtAnalyzer;
use CodeGuardian\Laravel\Commands\AnalyzeCommand;
use CodeGuardian\Laravel\Commands\GenerateTestsCommand;
use CodeGuardian\Laravel\Commands\PerformanceScanCommand;
use CodeGuardian\Laravel\Commands\RefactorCommand;
use CodeGuardian\Laravel\Commands\ReportCommand;
use CodeGuardian\Laravel\Commands\SecurityScanCommand;
use CodeGuardian\Laravel\Support\CachedPhpParser;
use CodeGuardian\Laravel\Support\FileTypeDetector;
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

        // Register analyzers as singletons — they are stateless between files
        // but expensive to instantiate, so reuse across command invocations.
        $this->app->singleton(ArchitectureAnalyzer::class);
        $this->app->singleton(SecurityAnalyzer::class);
        $this->app->singleton(PerformanceAnalyzer::class);
        $this->app->singleton(TechDebtAnalyzer::class);
        $this->app->singleton(StaticTestGenerator::class);

        // StaticOrchestrator receives its analyzers via constructor — resolved by the container
        $this->app->singleton(StaticOrchestrator::class, fn($app) => new StaticOrchestrator(
            $app->make(ArchitectureAnalyzer::class),
            $app->make(SecurityAnalyzer::class),
            $app->make(PerformanceAnalyzer::class),
            $app->make(TechDebtAnalyzer::class),
            $app->make(StaticTestGenerator::class),
        ));
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
