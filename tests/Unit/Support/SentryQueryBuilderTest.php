<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\SentryClient;
use PHPUnit\Framework\TestCase;

class SentryQueryBuilderTest extends TestCase
{
    public function test_defaults_to_unresolved_14d(): void
    {
        $built = SentryClient::buildIssueQuery([]);
        $this->assertSame('is:unresolved', $built['query']);
        $this->assertSame('14d', $built['statsPeriod']);
    }

    public function test_composes_status_level_and_environment(): void
    {
        $built = SentryClient::buildIssueQuery([
            'status' => 'resolved', 'level' => 'error', 'environment' => 'production', 'period' => '7d',
        ]);
        $this->assertSame('is:resolved level:error environment:production', $built['query']);
        $this->assertSame('7d', $built['statsPeriod']);
    }

    public function test_drops_unknown_values(): void
    {
        $built = SentryClient::buildIssueQuery([
            'status' => 'bogus', 'level' => 'nope', 'period' => 'forever',
        ]);
        $this->assertSame('is:unresolved', $built['query']);
        $this->assertSame('14d', $built['statsPeriod']);
    }

    public function test_falls_back_to_default_environment(): void
    {
        $built = SentryClient::buildIssueQuery([], 'staging');
        $this->assertStringContainsString('environment:staging', $built['query']);
    }

    public function test_explicit_environment_overrides_default(): void
    {
        $built = SentryClient::buildIssueQuery(['environment' => 'prod'], 'staging');
        $this->assertStringContainsString('environment:prod', $built['query']);
        $this->assertStringNotContainsString('staging', $built['query']);
    }

    public function test_extra_query_is_appended(): void
    {
        $built = SentryClient::buildIssueQuery(['query' => 'user.email:me@x.com']);
        $this->assertStringContainsString('user.email:me@x.com', $built['query']);
    }
}
