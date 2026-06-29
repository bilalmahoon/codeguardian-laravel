<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\DependencyAudit;
use PHPUnit\Framework\TestCase;

class DependencyAuditTest extends TestCase
{
    public function test_parse_lock_reads_packages_and_dev(): void
    {
        $lock = json_encode([
            'packages'     => [['name' => 'vendor/a', 'version' => '1.2.3']],
            'packages-dev' => [['name' => 'vendor/b', 'version' => '4.5.6']],
        ]);

        $versions = DependencyAudit::parseLock($lock);

        $this->assertSame(['vendor/a' => '1.2.3', 'vendor/b' => '4.5.6'], $versions);
    }

    public function test_parse_lock_handles_garbage(): void
    {
        $this->assertSame([], DependencyAudit::parseLock('not json'));
    }

    public function test_from_composer_audit_builds_findings(): void
    {
        $audit = [
            'advisories' => [
                'vendor/pkg' => [
                    [
                        'title'    => 'SQL Injection',
                        'cve'      => 'CVE-2024-0001',
                        'link'     => 'https://example.com/adv',
                        'severity' => 'high',
                        'affectedVersions' => '<1.2.4',
                    ],
                ],
            ],
            'abandoned' => ['old/pkg' => 'new/pkg'],
        ];

        $findings = DependencyAudit::fromComposerAudit($audit, ['vendor/pkg' => '1.2.3']);

        $this->assertCount(2, $findings);

        $vuln = $findings[0];
        $this->assertSame('dependency_vulnerability', $vuln['category']);
        $this->assertSame('high', $vuln['severity']);
        $this->assertStringContainsString('vendor/pkg', $vuln['title']);
        $this->assertStringContainsString('installed 1.2.3', $vuln['title']);
        $this->assertContains('https://example.com/adv', $vuln['references']);

        $abandoned = $findings[1];
        $this->assertSame('abandoned_dependency', $abandoned['category']);
        $this->assertStringContainsString('new/pkg', $abandoned['recommendation']);
    }

    public function test_unknown_severity_defaults_to_high(): void
    {
        $findings = DependencyAudit::fromComposerAudit([
            'advisories' => ['x/y' => [['title' => 'T']]],
        ]);

        $this->assertSame('high', $findings[0]['severity']);
    }

    public function test_to_result_aggregates_counts(): void
    {
        $findings = [
            ['severity' => 'critical'],
            ['severity' => 'high'],
            ['severity' => 'high'],
        ];

        $result = DependencyAudit::toResult($findings, 42, 'demo');

        $this->assertSame(3, $result['summary']['total_issues']);
        $this->assertSame(1, $result['summary']['critical']);
        $this->assertSame(2, $result['summary']['high']);
        $this->assertSame(42, $result['total_lines']);
    }
}
