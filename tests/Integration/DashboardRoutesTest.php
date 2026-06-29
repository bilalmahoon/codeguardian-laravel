<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use Orchestra\Testbench\TestCase;

/**
 * Integration tests for the web dashboard, booted inside a real Laravel app
 * (Orchestra Testbench). Verifies the routes register, the authorization gate
 * behaves, and the history/new-run pages render.
 */
class DashboardRoutesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\CodeGuardian\Laravel\CodeGuardianServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The dashboard uses the 'web' middleware group (sessions/CSRF/cookie
        // encryption), which requires an app key.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // Dashboard is local-only by default; run the app as local so it's open.
        // environment() reads the container 'env' binding, not config('app.env').
        $app['env'] = 'local';
        $app['config']->set('app.env', 'local');
        $app['config']->set('codeguardian.dashboard.enabled', true);
        $app['config']->set('codeguardian.dashboard.restrict_to_local', true);
    }

    /** @test */
    public function test_history_page_renders(): void
    {
        $this->get('/codeguardian')
            ->assertOk()
            ->assertSee('CodeGuardian')
            ->assertSee('history', false);
    }

    /** @test */
    public function test_new_run_page_renders_with_operations(): void
    {
        $this->get('/codeguardian/new')
            ->assertOk()
            ->assertSee('New run')
            ->assertSee('Refactor');
    }

    /** @test */
    public function test_new_run_page_renders_target_dropdowns(): void
    {
        $this->get('/codeguardian/new')
            ->assertOk()
            ->assertSee('target_type', false)        // target-type <select>
            ->assertSee('Whole project')             // a target label
            ->assertSee('id="dl-api"', false)        // searchable route datalist
            ->assertSee('id="dl-command"', false);   // searchable command datalist
    }

    /** @test */
    public function test_invalid_target_for_operation_is_rejected(): void
    {
        $this->startSession();

        // 'module' is not a valid target for a security audit.
        $this->post('/codeguardian/runs', [
            '_token'       => csrf_token(),
            'operation'    => 'security',
            'target_type'  => 'module',
            'target_value' => 'User',
        ])->assertRedirect();

        $this->assertEquals(
            'That target is not valid for this operation.',
            session('cg_error')
        );
    }

    /** @test */
    public function test_unknown_run_returns_404(): void
    {
        $this->get('/codeguardian/runs/does-not-exist')->assertNotFound();
    }

    /** @test */
    public function test_fix_unknown_run_returns_404(): void
    {
        $this->startSession();
        $this->post('/codeguardian/runs/nope/fix', ['_token' => csrf_token()])
            ->assertNotFound();
    }

    /** @test */
    public function test_fix_rejects_non_analyze_run(): void
    {
        $mock = \Mockery::mock(\CodeGuardian\Laravel\Support\RunStore::class);
        $mock->shouldReceive('find')->andReturn([
            'id' => 's1', 'type' => 'security', 'status' => 'completed', 'options' => [],
        ]);
        $mock->shouldNotReceive('start');
        $this->app->instance(\CodeGuardian\Laravel\Support\RunStore::class, $mock);

        $this->startSession();
        $this->post('/codeguardian/runs/s1/fix', ['_token' => csrf_token()])
            ->assertStatus(302);

        $this->assertEquals('Only analyze runs can be auto-fixed.', session('cg_error'));
    }

    /** @test */
    public function test_fix_starts_safe_refactor_reusing_scope(): void
    {
        $mock = \Mockery::mock(\CodeGuardian\Laravel\Support\RunStore::class);
        $mock->shouldReceive('find')->with('a1')->andReturn([
            'id' => 'a1', 'type' => 'analyze', 'status' => 'completed',
            'options' => ['api' => 'v1/auth/login'],
        ]);
        $mock->shouldReceive('start')->once()->with(
            'refactor',
            'codeguardian:refactor',
            \Mockery::on(fn($o) => ($o['mode'] ?? null) === 'auto'
                && ($o['safe'] ?? false) === true
                && ($o['api'] ?? null) === 'v1/auth/login'),
            \Mockery::type('string')
        )->andReturn('r1');
        $this->app->instance(\CodeGuardian\Laravel\Support\RunStore::class, $mock);

        $this->startSession();
        $this->post('/codeguardian/runs/a1/fix', ['_token' => csrf_token()])
            ->assertRedirect('/codeguardian/runs/r1');
    }

    /** @test */
    public function test_fix_with_selected_files_uses_files_option(): void
    {
        $mock = \Mockery::mock(\CodeGuardian\Laravel\Support\RunStore::class);
        $mock->shouldReceive('find')->with('a2')->andReturn([
            'id' => 'a2', 'type' => 'analyze', 'status' => 'completed', 'options' => [],
        ]);
        $mock->shouldReceive('start')->once()->with(
            'refactor',
            'codeguardian:refactor',
            \Mockery::on(fn($o) => ($o['safe'] ?? false) === true
                && ($o['files'] ?? '') === 'app/A.php,app/B.php'
                && ! isset($o['api'])),
            \Mockery::type('string')
        )->andReturn('r2');
        $this->app->instance(\CodeGuardian\Laravel\Support\RunStore::class, $mock);

        $this->startSession();
        $this->post('/codeguardian/runs/a2/fix', [
            '_token' => csrf_token(),
            'files'  => ['app/A.php', '../../etc/passwd', 'app/B.php'], // traversal dropped
        ])->assertRedirect('/codeguardian/runs/r2');
    }

    /** @test */
    public function test_status_endpoint_404_for_unknown_run(): void
    {
        $this->getJson('/codeguardian/runs/nope/status')->assertNotFound();
    }

    /** @test */
    public function test_dashboard_disabled_returns_404(): void
    {
        config(['codeguardian.dashboard.enabled' => false]);

        // Route group is registered at boot based on config; when disabled the
        // routes are never registered, so the path 404s.
        $this->get('/codeguardian')->assertNotFound();
    }

    /** @test */
    public function test_non_local_is_forbidden_when_restricted(): void
    {
        // Simulate production with local-only restriction and no gate defined.
        $this->app['env'] = 'production';
        config(['codeguardian.dashboard.restrict_to_local' => true]);

        $this->get('/codeguardian')->assertForbidden();
    }
}
