<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\FileTypeDetector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CodeGuardian\Laravel\Support\FileTypeDetector
 */
class FileTypeDetectorTest extends TestCase
{
    // ── isController ────────────────────────────────────────────────────────

    public function test_isController_detects_by_path(): void
    {
        $this->assertTrue(FileTypeDetector::isController('app/Http/Controllers/UserController.php'));
        $this->assertTrue(FileTypeDetector::isController('Modules/User/Http/Controllers/AuthController.php'));
    }

    public function test_isController_detects_by_content(): void
    {
        $content = "<?php\nclass FooController extends Controller {}";
        $this->assertTrue(FileTypeDetector::isController('app/Foo.php', $content));
    }

    public function test_isController_returns_false_for_service(): void
    {
        $this->assertFalse(FileTypeDetector::isController('app/Services/UserService.php'));
    }

    // ── isModel ─────────────────────────────────────────────────────────────

    public function test_isModel_detects_by_path(): void
    {
        $this->assertTrue(FileTypeDetector::isModel('app/Models/User.php'));
        $this->assertTrue(FileTypeDetector::isModel('Modules/Auth/Models/Token.php'));
    }

    public function test_isModel_detects_eloquent_by_content(): void
    {
        $content = "<?php\nclass Post extends Model {}";
        $this->assertTrue(FileTypeDetector::isModel('app/Post.php', $content));
    }

    public function test_isModel_returns_false_for_controller(): void
    {
        $this->assertFalse(FileTypeDetector::isModel('app/Http/Controllers/UserController.php'));
    }

    // ── isService ────────────────────────────────────────────────────────────

    public function test_isService_detects_by_path(): void
    {
        $this->assertTrue(FileTypeDetector::isService('app/Services/PaymentService.php'));
        $this->assertTrue(FileTypeDetector::isService('app/Actions/CreateOrderAction.php'));
    }

    // ── isMigration ──────────────────────────────────────────────────────────

    public function test_isMigration_detects_migration_paths(): void
    {
        $this->assertTrue(FileTypeDetector::isMigration('database/migrations/2024_01_01_create_users_table.php'));
        $this->assertFalse(FileTypeDetector::isMigration('app/Http/Controllers/UserController.php'));
    }

    // ── isTest ───────────────────────────────────────────────────────────────

    public function test_isTest_detects_test_files(): void
    {
        $this->assertTrue(FileTypeDetector::isTest('tests/Unit/UserTest.php'));
        $this->assertTrue(FileTypeDetector::isTest('app/Http/Controllers/UserControllerTest.php'));
        $this->assertFalse(FileTypeDetector::isTest('app/Http/Controllers/UserController.php'));
    }

    // ── classify ─────────────────────────────────────────────────────────────

    public function test_classify_returns_correct_type(): void
    {
        $this->assertSame('controller', FileTypeDetector::classify('app/Http/Controllers/UserController.php'));
        $this->assertSame('model',      FileTypeDetector::classify('app/Models/User.php'));
        $this->assertSame('service',    FileTypeDetector::classify('app/Services/UserService.php'));
        $this->assertSame('generic',    FileTypeDetector::classify('app/Helpers/StringHelper.php'));
    }
}
