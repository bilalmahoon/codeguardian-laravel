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
use CodeGuardian\Laravel\Commands\CommentCommand;
use CodeGuardian\Laravel\Commands\DoctorCommand;
use CodeGuardian\Laravel\Commands\GenerateTestsCommand;
use CodeGuardian\Laravel\Commands\PerformanceScanCommand;
use CodeGuardian\Laravel\Commands\RefactorCommand;
use CodeGuardian\Laravel\Commands\ReportCommand;
use CodeGuardian\Laravel\Commands\RulesCommand;
use CodeGuardian\Laravel\Commands\TrendCommand;
use CodeGuardian\Laravel\Commands\SecurityScanCommand;
use CodeGuardian\Laravel\Http\Middleware\Authorize;
use CodeGuardian\Laravel\Support\CachedPhpParser;
use CodeGuardian\Laravel\Support\FileTypeDetector;
use CodeGuardian\Laravel\Support\RunStore;
use Illuminate\Support\Facades\Route;
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

        // Web dashboard run/history store
        $this->app->singleton(RunStore::class, fn() => RunStore::fromConfig());
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'codeguardian');
        $this->registerDashboardRoutes();

        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__ . '/../config/codeguardian.php' => config_path('codeguardian.php'),
            ], 'codeguardian-config');

            // Publish views (optional — for customising the dashboard UI)
            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/codeguardian'),
            ], 'codeguardian-views');

            // Register artisan commands
            $this->commands([
                AnalyzeCommand::class,
                SecurityScanCommand::class,
                PerformanceScanCommand::class,
                GenerateTestsCommand::class,
                RefactorCommand::class,
                ReportCommand::class,
                DoctorCommand::class,
                RulesCommand::class,
                TrendCommand::class,
                CommentCommand::class,
            ]);
        }
    }

    /**
     * Register the browser dashboard routes (guarded by config + middleware).
     * Mounted at the configured path (default: /codeguardian).
     */
    private function registerDashboardRoutes(): void
    {
        if (! config('codeguardian.dashboard.enabled', true)) {
            return;
        }

        // The Authorize middleware needs an alias so route middleware can use it.
        $router = $this->app['router'];
        $router->aliasMiddleware('codeguardian.auth', Authorize::class);

        $middleware = array_merge(
            (array) config('codeguardian.dashboard.middleware', ['web']),
            ['codeguardian.auth']
        );

        Route::group([
            'prefix'     => config('codeguardian.dashboard.path', 'codeguardian'),
            'middleware' => $middleware,
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        });
    }
}
