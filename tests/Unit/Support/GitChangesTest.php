<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\GitChanges;
use PHPUnit\Framework\TestCase;

class GitChangesTest extends TestCase
{
    public function test_filter_keeps_only_changed_files(): void
    {
        $files = [
            'app/A.php' => '<?php // a',
            'app/B.php' => '<?php // b',
            'app/C.php' => '<?php // c',
        ];
        $changed = ['app/A.php', 'app/C.php'];

        $kept = GitChanges::filter($files, $changed);

        $this->assertSame(['app/A.php', 'app/C.php'], array_keys($kept));
    }

    public function test_filter_handles_subdir_scan_path(): void
    {
        // Scan path is a subdir; file keys are relative to it, changed paths are repo-relative.
        $files   = ['Controllers/Foo.php' => '<?php'];
        $changed = ['app/Http/Controllers/Foo.php'];

        $kept = GitChanges::filter($files, $changed, '/repo', '/repo/app/Http');

        $this->assertArrayHasKey('Controllers/Foo.php', $kept);
    }

    public function test_filter_empty_changed_returns_empty(): void
    {
        $this->assertSame([], GitChanges::filter(['a.php' => 'x'], []));
    }
}
