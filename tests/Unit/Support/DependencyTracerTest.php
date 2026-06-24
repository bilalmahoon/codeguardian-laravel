<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\DependencyTracer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DependencyTracer.
 *
 * Because DependencyTracer uses PHP's ReflectionClass, we can test it with
 * real (temp-file) classes. We create throwaway PHP files in a temp directory,
 * require them into the current process, and verify the tracer finds them.
 */
class DependencyTracerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/cg_tracer_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);
        parent::tearDown();
    }

    // ─── Vendor class filter ──────────────────────────────────────────────────

    /** @test */
    public function test_illuminate_classes_are_skipped(): void
    {
        $tracer = new DependencyTracer(sys_get_temp_dir());
        $method = new \ReflectionMethod($tracer, 'isVendorClass');

        $this->assertTrue($method->invoke($tracer, 'Illuminate\\Support\\Facades\\Auth'));
        $this->assertTrue($method->invoke($tracer, 'Laravel\\Sanctum\\PersonalAccessToken'));
        $this->assertTrue($method->invoke($tracer, 'Symfony\\Component\\HttpFoundation\\Request'));
        $this->assertFalse($method->invoke($tracer, 'Modules\\Auth\\Services\\AuthService'));
        $this->assertFalse($method->invoke($tracer, 'App\\Http\\Controllers\\AuthController'));
    }

    // ─── Reflection-based dependency tracing ────────────────────────────────

    /** @test */
    public function test_traces_single_controller_with_no_dependencies(): void
    {
        // Create a minimal controller class with no constructor
        $ns        = 'CgTracerTest' . uniqid();
        $className = $ns . '\\PlainController';
        $file      = $this->tmpDir . '/PlainController.php';

        file_put_contents($file, <<<PHP
            <?php
            namespace {$ns};
            class PlainController {}
            PHP);

        require_once $file;

        $tracer = new DependencyTracer($this->tmpDir);
        $files  = $tracer->trace([$className], 2);

        // The controller file itself must be included
        $this->assertCount(1, $files);
        $this->assertStringContainsString('PlainController.php', array_key_first($files));
    }

    /** @test */
    public function test_traces_controller_to_injected_service(): void
    {
        $ns          = 'CgTracerTest' . uniqid();
        $serviceName = $ns . '\\AuthService';
        $ctrlName    = $ns . '\\AuthController';

        $serviceFile = $this->tmpDir . '/AuthService.php';
        $ctrlFile    = $this->tmpDir . '/AuthController.php';

        file_put_contents($serviceFile, <<<PHP
            <?php
            namespace {$ns};
            class AuthService {}
            PHP);

        file_put_contents($ctrlFile, <<<PHP
            <?php
            namespace {$ns};
            class AuthController {
                public function __construct(private AuthService \$service) {}
            }
            PHP);

        require_once $serviceFile;
        require_once $ctrlFile;

        $tracer = new DependencyTracer($this->tmpDir);
        $files  = $tracer->trace([$ctrlName], 2);

        // Both the controller AND the service must be included
        $this->assertCount(2, $files);
        $paths = array_keys($files);
        $this->assertTrue(
            array_filter($paths, fn($p) => str_contains($p, 'AuthController.php')) !== [],
            'AuthController.php must be in scope'
        );
        $this->assertTrue(
            array_filter($paths, fn($p) => str_contains($p, 'AuthService.php')) !== [],
            'AuthService.php must be in scope'
        );
    }

    /** @test */
    public function test_traces_method_injected_dependency_on_route_handler(): void
    {
        // Action/feature pattern: the dependency is injected as a METHOD parameter
        // of the route handler, NOT in the constructor.
        //   public function authenticateUser(LoginRequest $r, BaseLogin $login)
        $ns        = 'CgTracerTest' . uniqid();
        $featureFqn = $ns . '\\BaseLogin';
        $ctrlFqn    = $ns . '\\ApiAuthController';

        $featureFile = $this->tmpDir . '/BaseLogin.php';
        $ctrlFile    = $this->tmpDir . '/ApiAuthController.php';

        file_put_contents($featureFile, "<?php\nnamespace {$ns};\nclass BaseLogin {}");
        file_put_contents($ctrlFile, <<<PHP
            <?php
            namespace {$ns};
            class ApiAuthController {
                public function authenticateUser(BaseLogin \$login) {
                    return \$login;
                }
            }
            PHP);

        require_once $featureFile;
        require_once $ctrlFile;

        $tracer = new DependencyTracer($this->tmpDir);

        // WITHOUT entryMethods: constructor-only → only the controller is found
        $ctorOnly = $tracer->trace([$ctrlFqn], 2);
        $this->assertCount(1, $ctorOnly,
            'Without entryMethods, method-injected BaseLogin must NOT be traced'
        );

        // WITH entryMethods: the route method params are followed → BaseLogin found
        $withMethod = $tracer->trace([$ctrlFqn], 2, null, [$ctrlFqn => 'authenticateUser']);
        $filenames  = array_map('basename', array_keys($withMethod));

        $this->assertContains('ApiAuthController.php', $filenames);
        $this->assertContains('BaseLogin.php', $filenames,
            'Method-injected BaseLogin must be traced when entryMethods is provided'
        );
    }

    /** @test */
    public function test_includes_parent_class_when_concrete_extends_base(): void
    {
        // Container resolves an abstract BaseLogin to a thin concrete Login that
        // extends it. The real logic lives in the parent — it MUST be included.
        $ns          = 'CgTracerTest' . uniqid();
        $baseFqn     = $ns . '\\BaseLogin';
        $concreteFqn = $ns . '\\Login';

        $baseFile     = $this->tmpDir . '/BaseLogin.php';
        $concreteFile = $this->tmpDir . '/Login.php';

        file_put_contents($baseFile, "<?php\nnamespace {$ns};\nabstract class BaseLogin { public function handle() {} }");
        file_put_contents($concreteFile, "<?php\nnamespace {$ns};\nclass Login extends BaseLogin {}");

        require_once $baseFile;
        require_once $concreteFile;

        $tracer = new DependencyTracer($this->tmpDir);
        $files  = $tracer->trace([$concreteFqn], 2);

        $filenames = array_map('basename', array_keys($files));
        $this->assertContains('Login.php', $filenames, 'Concrete class must be included');
        $this->assertContains('BaseLogin.php', $filenames,
            'Parent class (with the real logic) must be included'
        );
    }

    /** @test */
    public function test_traces_use_imports_referenced_in_method_bodies(): void
    {
        // A feature class that uses a Repository INSIDE a method body (not via
        // constructor or method parameter). Only a `use` import reveals it.
        $ns       = 'CgTracerTest' . uniqid();
        $repoFqn  = $ns . '\\UserRepository';
        $featFqn  = $ns . '\\LoginFeature';

        $repoFile = $this->tmpDir . '/UserRepository.php';
        $featFile = $this->tmpDir . '/LoginFeature.php';

        file_put_contents($repoFile, "<?php\nnamespace {$ns};\nclass UserRepository { public function find() {} }");
        file_put_contents($featFile, <<<PHP
            <?php
            namespace {$ns};
            use {$ns}\\UserRepository;
            class LoginFeature {
                public function handle() {
                    \$repo = new UserRepository();
                    return \$repo->find();
                }
            }
            PHP);

        require_once $repoFile;
        require_once $featFile;

        $tracer = new DependencyTracer($this->tmpDir);
        $files  = $tracer->trace([$featFqn], 2);

        $filenames = array_map('basename', array_keys($files));
        $this->assertContains('LoginFeature.php', $filenames);
        $this->assertContains('UserRepository.php', $filenames,
            'Repository referenced via use-import inside a method body must be traced'
        );
    }

    /** @test */
    public function test_entry_controller_use_imports_are_not_scanned(): void
    {
        // Regression: a controller's `use` block imports EVERY sibling feature it
        // can dispatch (login, logout, register, …). For the ENTRY controller we
        // must only follow the resolved route method's parameters — NOT scan the
        // whole `use` block — otherwise the entire module gets dragged into scope.
        $ns          = 'CgTracerTest' . uniqid();
        $loginFqn    = $ns . '\\LoginFeature';
        $logoutFqn   = $ns . '\\LogoutFeature';
        $registerFqn = $ns . '\\RegisterFeature';
        $ctrlFqn     = $ns . '\\ApiAuthController';

        file_put_contents($this->tmpDir . '/LoginFeature.php', "<?php\nnamespace {$ns};\nclass LoginFeature {}");
        file_put_contents($this->tmpDir . '/LogoutFeature.php', "<?php\nnamespace {$ns};\nclass LogoutFeature {}");
        file_put_contents($this->tmpDir . '/RegisterFeature.php', "<?php\nnamespace {$ns};\nclass RegisterFeature {}");
        file_put_contents($this->tmpDir . '/ApiAuthController.php', <<<PHP
            <?php
            namespace {$ns};
            use {$ns}\\LoginFeature;
            use {$ns}\\LogoutFeature;
            use {$ns}\\RegisterFeature;
            class ApiAuthController {
                public function authenticateUser(LoginFeature \$login) { return \$login; }
                public function logout(LogoutFeature \$logout) { return \$logout; }
                public function register(RegisterFeature \$register) { return \$register; }
            }
            PHP);

        require_once $this->tmpDir . '/LoginFeature.php';
        require_once $this->tmpDir . '/LogoutFeature.php';
        require_once $this->tmpDir . '/RegisterFeature.php';
        require_once $this->tmpDir . '/ApiAuthController.php';

        $tracer = new DependencyTracer($this->tmpDir);
        $files  = $tracer->trace([$ctrlFqn], 2, null, [$ctrlFqn => 'authenticateUser']);
        $names  = array_map('basename', array_keys($files));

        $this->assertContains('ApiAuthController.php', $names);
        $this->assertContains('LoginFeature.php', $names,
            'The route method parameter (LoginFeature) must be traced'
        );
        $this->assertNotContains('LogoutFeature.php', $names,
            'Sibling features imported via use must NOT be pulled into scope from the entry controller'
        );
        $this->assertNotContains('RegisterFeature.php', $names,
            'Sibling features imported via use must NOT be pulled into scope from the entry controller'
        );
    }

    /** @test */
    public function test_traces_two_hops_controller_service_repository(): void
    {
        $ns      = 'CgTracerTest' . uniqid();
        $repoFqn = $ns . '\\UserRepository';
        $svcFqn  = $ns . '\\UserService';
        $ctrlFqn = $ns . '\\UserController';

        $repoFile = $this->tmpDir . '/UserRepository.php';
        $svcFile  = $this->tmpDir . '/UserService.php';
        $ctrlFile = $this->tmpDir . '/UserController.php';

        file_put_contents($repoFile, <<<PHP
            <?php
            namespace {$ns};
            class UserRepository {}
            PHP);

        file_put_contents($svcFile, <<<PHP
            <?php
            namespace {$ns};
            class UserService {
                public function __construct(private UserRepository \$repo) {}
            }
            PHP);

        file_put_contents($ctrlFile, <<<PHP
            <?php
            namespace {$ns};
            class UserController {
                public function __construct(private UserService \$service) {}
            }
            PHP);

        require_once $repoFile;
        require_once $svcFile;
        require_once $ctrlFile;

        $tracer = new DependencyTracer($this->tmpDir);
        $files  = $tracer->trace([$ctrlFqn], maxDepth: 2);

        $this->assertCount(3, $files, 'Controller, Service, and Repository must all be in scope');
    }

    /** @test */
    public function test_does_not_recurse_beyond_max_depth(): void
    {
        $ns       = 'CgTracerTest' . uniqid();
        $level3   = $ns . '\\DeepClass';
        $level2   = $ns . '\\MiddleClass';
        $level1   = $ns . '\\TopClass';

        $file3 = $this->tmpDir . '/DeepClass.php';
        $file2 = $this->tmpDir . '/MiddleClass.php';
        $file1 = $this->tmpDir . '/TopClass.php';

        file_put_contents($file3, "<?php\nnamespace {$ns};\nclass DeepClass {}");
        file_put_contents($file2, "<?php\nnamespace {$ns};\nclass MiddleClass { public function __construct(private DeepClass \$d) {} }");
        file_put_contents($file1, "<?php\nnamespace {$ns};\nclass TopClass   { public function __construct(private MiddleClass \$m) {} }");

        require_once $file3;
        require_once $file2;
        require_once $file1;

        // maxDepth=1 → TopClass + MiddleClass (one hop), DeepClass is hop 2 and must be excluded
        $tracer = new DependencyTracer($this->tmpDir);
        $files  = $tracer->trace([$level1], maxDepth: 1);

        $this->assertCount(2, $files, 'With maxDepth=1 only TopClass and MiddleClass should be included');
    }

    /** @test */
    public function test_vendor_dependencies_are_excluded_from_trace(): void
    {
        // A controller that injects an Illuminate class must NOT try to include
        // that class's file (it is in vendor/) — only the controller itself appears.
        $ns      = 'CgTracerTest' . uniqid();
        $ctrlFqn = $ns . '\\MyController';
        $ctrlFile = $this->tmpDir . '/MyController.php';

        file_put_contents($ctrlFile, <<<PHP
            <?php
            namespace {$ns};
            class MyController {
                public function __construct(
                    private \Illuminate\Http\Request \$request
                ) {}
            }
            PHP);

        require_once $ctrlFile;

        $tracer = new DependencyTracer($this->tmpDir);
        $files  = $tracer->trace([$ctrlFqn], 2);

        $this->assertCount(1, $files, 'Illuminate classes must not add files to scope');
    }

    /** @test */
    public function test_trace_returns_empty_for_nonexistent_class(): void
    {
        $tracer = new DependencyTracer($this->tmpDir);
        $files  = $tracer->trace(['NonExistent\\Class\\That\\DoesNotExist'], 2);

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    /** @test */
    public function test_circular_dependencies_do_not_cause_infinite_loop(): void
    {
        $ns  = 'CgTracerTest' . uniqid();
        $aFqn = $ns . '\\ClassA';
        $bFqn = $ns . '\\ClassB';

        $aFile = $this->tmpDir . '/ClassA.php';
        $bFile = $this->tmpDir . '/ClassB.php';

        // A depends on B, but B also depends on A (circular)
        // We can't actually declare this with strict constructor injection,
        // so simulate it by having each depend on a common third class.
        $cFile = $this->tmpDir . '/ClassC.php';
        $cFqn  = $ns . '\\ClassC';

        file_put_contents($cFile, "<?php\nnamespace {$ns};\nclass ClassC {}");
        file_put_contents($aFile, "<?php\nnamespace {$ns};\nclass ClassA { public function __construct(private ClassC \$c) {} }");
        file_put_contents($bFile, "<?php\nnamespace {$ns};\nclass ClassB { public function __construct(private ClassC \$c) {} }");

        require_once $cFile;
        require_once $aFile;
        require_once $bFile;

        $tracer = new DependencyTracer($this->tmpDir);

        // Should not throw, hang, or recurse infinitely
        $files = $tracer->trace([$aFqn, $bFqn], 2);

        // ClassC must appear only once despite being a dep of both A and B
        $cEntries = array_filter(array_keys($files), fn($p) => str_contains($p, 'ClassC.php'));
        $this->assertCount(1, $cEntries, 'ClassC.php must appear exactly once');
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    private function rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
