<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel;

use CodeGuardian\Laravel\Analyzers\ArchitectureAnalyzer;
use CodeGuardian\Laravel\Analyzers\DartAnalyzer;
use CodeGuardian\Laravel\Analyzers\DatabaseAnalyzer;
use CodeGuardian\Laravel\Analyzers\PerformanceAnalyzer;
use CodeGuardian\Laravel\Analyzers\SecurityAnalyzer;
use CodeGuardian\Laravel\Analyzers\StaticOrchestrator;
use CodeGuardian\Laravel\Analyzers\StaticTestGenerator;
use CodeGuardian\Laravel\Analyzers\TechDebtAnalyzer;
use CodeGuardian\Laravel\Commands\AnalyzeCommand;
use CodeGuardian\Laravel\Commands\AuditCommand;
use CodeGuardian\Laravel\Commands\CommentCommand;
use CodeGuardian\Laravel\Commands\ConfigCheckCommand;
use CodeGuardian\Laravel\Commands\DoctorCommand;
use CodeGuardian\Laravel\Commands\ExplainCommand;
use CodeGuardian\Laravel\Commands\FixCommand;
use CodeGuardian\Laravel\Commands\GraphCommand;
use CodeGuardian\Laravel\Commands\InitCommand;
use CodeGuardian\Laravel\Commands\GenerateTestsCommand;
use CodeGuardian\Laravel\Commands\NotifyCommand;
use CodeGuardian\Laravel\Commands\PerformanceScanCommand;
use CodeGuardian\Laravel\Commands\RefactorCommand;
use CodeGuardian\Laravel\Commands\ReportCommand;
use CodeGuardian\Laravel\Commands\ReviewCommand;
use CodeGuardian\Laravel\Commands\RulesCommand;
use CodeGuardian\Laravel\Commands\SentryCommand;
use CodeGuardian\Laravel\Commands\TestImpactCommand;
use CodeGuardian\Laravel\Commands\TrendCommand;
use CodeGuardian\Laravel\Commands\SecurityScanCommand;
use CodeGuardian\Laravel\Commands\WatchCommand;
use CodeGuardian\Laravel\Http\Middleware\Authorize;
use CodeGuardian\Laravel\Http\Middleware\VerifySlackSignature;
use CodeGuardian\Laravel\Integrations\ComingSoonIntegration;
use CodeGuardian\Laravel\Integrations\IntegrationRegistry;
use CodeGuardian\Laravel\Integrations\SentryIntegration;
use CodeGuardian\Laravel\Integrations\SlackIntegration;
use CodeGuardian\Laravel\Support\CachedPhpParser;
use CodeGuardian\Laravel\Support\FileTypeDetector;
use CodeGuardian\Laravel\Support\RunStore;
use CodeGuardian\Laravel\Support\SentryClient;
use CodeGuardian\Laravel\Support\SlackService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
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
        $this->app->singleton(DatabaseAnalyzer::class);
        $this->app->singleton(DartAnalyzer::class);
        $this->app->singleton(StaticTestGenerator::class);

        // StaticOrchestrator receives its analyzers via constructor — resolved by the container
        $this->app->singleton(StaticOrchestrator::class, fn($app) => new StaticOrchestrator(
            $app->make(ArchitectureAnalyzer::class),
            $app->make(SecurityAnalyzer::class),
            $app->make(PerformanceAnalyzer::class),
            $app->make(TechDebtAnalyzer::class),
            $app->make(StaticTestGenerator::class),
            $app->make(DatabaseAnalyzer::class),
            $app->make(DartAnalyzer::class),
        ));

        // Web dashboard run/history store
        $this->app->singleton(RunStore::class, fn() => RunStore::fromConfig());

        // Sentry client (bind, not singleton) so tests can swap in a mocked
        // HTTP handler and so config changes are picked up per resolution.
        $this->app->bind(SentryClient::class, fn() => SentryClient::fromConfig());

        // Slack read client for the in-dashboard panel (bind for the same reason).
        $this->app->bind(SlackService::class, fn() => SlackService::fromConfig());

        // Integration catalogue that drives the dashboard navigation. Register
        // built-in integrations here; a future one is a single ->register() call.
        $this->app->singleton(IntegrationRegistry::class, function () {
            return (new IntegrationRegistry())
                ->register(new SentryIntegration())
                ->register(new SlackIntegration())
                ->register(new ComingSoonIntegration('grafana', 'Grafana', '📊', 'Dashboards & metrics (coming soon).', 30))
                ->register(new ComingSoonIntegration('jira', 'Jira', '🧭', 'Issue tracking & tickets (coming soon).', 40))
                ->register(new ComingSoonIntegration('github', 'GitHub', '🐙', 'Pull requests & code hosting (coming soon).', 50));
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'codeguardian');

        // Share the integration-driven navigation with the shared layout so every
        // dashboard page gets a consistent, self-updating nav.
        View::composer('codeguardian::layout', function ($view) {
            $view->with('cgIntegrations', $this->app->make(IntegrationRegistry::class)->navItems());
        });

        $this->registerDashboardRoutes();
        $this->registerSlackRoutes();

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
                InitCommand::class,
                FixCommand::class,
                AuditCommand::class,
                WatchCommand::class,
                GraphCommand::class,
                ReviewCommand::class,
                ExplainCommand::class,
                NotifyCommand::class,
                ConfigCheckCommand::class,
                TestImpactCommand::class,
                SentryCommand::class,
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

    /**
     * Register the Slack App endpoints (slash command + interactivity). These
     * are NOT behind the dashboard's session auth/CSRF — every request is
     * verified by its Slack signature instead. Mounted under the same prefix
     * as the dashboard so one URL base covers everything.
     */
    private function registerSlackRoutes(): void
    {
        if (! config('codeguardian.slack.enabled', false)) {
            return;
        }

        $router = $this->app['router'];
        $router->aliasMiddleware('codeguardian.slack', VerifySlackSignature::class);

        Route::group([
            'prefix'     => config('codeguardian.dashboard.path', 'codeguardian'),
            'middleware' => ['codeguardian.slack'],
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/slack.php');
        });
    }
}
