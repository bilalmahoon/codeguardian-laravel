<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Analyzers;

use CodeGuardian\Laravel\Analyzers\RefactorResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CodeGuardian\Laravel\Analyzers\RefactorResult
 */
class RefactorResultTest extends TestCase
{
    public function test_hasChanges_true_when_content_differs(): void
    {
        $result = new RefactorResult(
            filePath:    'app/Foo.php',
            original:    'original content',
            refactored:  'modified content',
            changes:     ['Auto-fixed: something'],
            autoFixed:   1,
            manualTodos: 0,
        );

        $this->assertTrue($result->hasChanges());
    }

    public function test_hasChanges_false_when_content_identical(): void
    {
        $result = new RefactorResult(
            filePath:    'app/Foo.php',
            original:    'same content',
            refactored:  'same content',
            changes:     [],
            autoFixed:   0,
            manualTodos: 0,
        );

        $this->assertFalse($result->hasChanges());
    }

    public function test_diff_shows_changed_lines(): void
    {
        $result = new RefactorResult(
            filePath:    'app/Foo.php',
            original:    "line1\nold line\nline3",
            refactored:  "line1\nnew line\nline3",
            changes:     [],
            autoFixed:   0,
            manualTodos: 0,
        );

        $diff = $result->diff();

        $this->assertStringContainsString('- old line', $diff);
        $this->assertStringContainsString('+ new line', $diff);
        $this->assertStringNotContainsString('line1',   $diff);
        $this->assertStringNotContainsString('line3',   $diff);
    }

    public function test_diff_empty_when_no_changes(): void
    {
        $content = "line1\nline2";
        $result  = new RefactorResult(
            filePath: 'app/Foo.php', original: $content, refactored: $content,
            changes: [], autoFixed: 0, manualTodos: 0,
        );

        $this->assertSame('', $result->diff());
    }

    public function test_toArray_contains_expected_keys(): void
    {
        $result = new RefactorResult(
            filePath: 'app/Foo.php', original: 'a', refactored: 'b',
            changes: ['Auto-fixed: x'], autoFixed: 1, manualTodos: 0,
        );

        $arr = $result->toArray();

        $this->assertArrayHasKey('file',         $arr);
        $this->assertArrayHasKey('has_changes',  $arr);
        $this->assertArrayHasKey('auto_fixed',   $arr);
        $this->assertArrayHasKey('manual_todos', $arr);
        $this->assertArrayHasKey('changes',      $arr);
    }

    public function test_readonly_properties_are_accessible(): void
    {
        $result = new RefactorResult(
            filePath:    'app/Foo.php',
            original:    'original',
            refactored:  'refactored',
            changes:     ['change 1'],
            autoFixed:   1,
            manualTodos: 2,
        );

        $this->assertSame('app/Foo.php', $result->filePath);
        $this->assertSame(1,             $result->autoFixed);
        $this->assertSame(2,             $result->manualTodos);
        $this->assertCount(1,            $result->changes);
    }
}
