<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\TestImpact;
use PHPUnit\Framework\TestCase;

class TestImpactTest extends TestCase
{
    public function test_class_name_of(): void
    {
        $this->assertSame('UserService', TestImpact::classNameOf('app/Services/UserService.php'));
    }

    public function test_impacted_tests_match_by_reference(): void
    {
        $changed   = ['app/Services/UserService.php'];
        $testFiles = [
            'tests/Unit/UserServiceTest.php' => '<?php class UserServiceTest { /* uses UserService */ }',
            'tests/Unit/OrderTest.php'       => '<?php class OrderTest {}',
        ];

        $impacted = TestImpact::impactedTests($changed, $testFiles);

        $this->assertSame(['tests/Unit/UserServiceTest.php'], $impacted);
    }

    public function test_changed_test_file_is_always_impacted(): void
    {
        $changed   = ['tests/Unit/PaymentTest.php'];
        $impacted  = TestImpact::impactedTests($changed, []);
        $this->assertSame(['tests/Unit/PaymentTest.php'], $impacted);
    }

    public function test_matches_by_naming_convention(): void
    {
        $changed   = ['app/Models/Invoice.php'];
        $testFiles = ['tests/Unit/InvoiceTest.php' => '<?php class InvoiceTest {}'];

        $impacted = TestImpact::impactedTests($changed, $testFiles);
        $this->assertContains('tests/Unit/InvoiceTest.php', $impacted);
    }

    public function test_phpunit_filter_joins_class_names(): void
    {
        $filter = TestImpact::phpunitFilter([
            'tests/Unit/UserServiceTest.php',
            'tests/Unit/InvoiceTest.php',
        ]);

        $this->assertSame('UserServiceTest|InvoiceTest', $filter);
        $this->assertSame('', TestImpact::phpunitFilter([]));
    }

    public function test_unrelated_change_yields_no_tests(): void
    {
        $impacted = TestImpact::impactedTests(
            ['app/Services/Foo.php'],
            ['tests/Unit/BarTest.php' => '<?php class BarTest {}']
        );
        $this->assertSame([], $impacted);
    }
}
