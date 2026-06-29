<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\GitDiff;
use PHPUnit\Framework\TestCase;

class GitDiffTest extends TestCase
{
    private const SAMPLE = <<<'DIFF'
diff --git a/app/Foo.php b/app/Foo.php
index 1234567..89abcde 100644
--- a/app/Foo.php
+++ b/app/Foo.php
@@ -1,5 +1,6 @@
 <?php
 class Foo {
-    public function old() {}
+    public function newOne(): void {}
+    public function another(): void {}
 }
diff --git a/app/Bar.php b/app/Bar.php
index 000..111 100644
--- a/app/Bar.php
+++ b/app/Bar.php
@@ -10,3 +10,3 @@
-    return $x;
+    return $y;
DIFF;

    public function test_path_from_diff_header(): void
    {
        $this->assertSame(
            'app/Foo.php',
            GitDiff::pathFromDiffHeader('diff --git a/app/Foo.php b/app/Foo.php')
        );
        $this->assertNull(GitDiff::pathFromDiffHeader('not a header'));
    }

    public function test_parse_unified_diff_groups_added_and_removed(): void
    {
        $parsed = GitDiff::parseUnifiedDiff(self::SAMPLE);

        $this->assertArrayHasKey('app/Foo.php', $parsed);
        $this->assertArrayHasKey('app/Bar.php', $parsed);

        $foo = $parsed['app/Foo.php'];
        $this->assertSame(['    public function old() {}'], $foo['removed']);
        $this->assertCount(2, $foo['added']);
        $this->assertStringContainsString('newOne', $foo['added'][0]);

        $bar = $parsed['app/Bar.php'];
        $this->assertSame(['    return $x;'], $bar['removed']);
        $this->assertSame(['    return $y;'], $bar['added']);
    }

    public function test_to_review_context_is_compact_and_labelled(): void
    {
        $parsed  = GitDiff::parseUnifiedDiff(self::SAMPLE);
        $context = GitDiff::toReviewContext($parsed);

        $this->assertStringContainsString('### app/Foo.php', $context);
        $this->assertStringContainsString('+ ' . '    public function newOne(): void {}', $context);
        $this->assertStringContainsString('- ' . '    public function old() {}', $context);
    }

    public function test_header_metadata_not_counted_as_changes(): void
    {
        $parsed = GitDiff::parseUnifiedDiff(self::SAMPLE);
        // The +++/--- header lines must not leak into added/removed.
        foreach ($parsed as $info) {
            foreach (array_merge($info['added'], $info['removed']) as $line) {
                $this->assertStringNotContainsString('/app/', $line);
            }
        }
    }
}
