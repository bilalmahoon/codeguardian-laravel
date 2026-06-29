<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\RuleDocs;
use CodeGuardian\Laravel\Support\RuleRegistry;
use PHPUnit\Framework\TestCase;

class RuleDocsTest extends TestCase
{
    public function test_every_catalog_rule_has_docs(): void
    {
        foreach (array_keys(RuleRegistry::CATALOG) as $id) {
            $this->assertTrue(RuleDocs::has($id), "Missing docs for rule: {$id}");
        }
    }

    public function test_docs_have_required_fields(): void
    {
        $doc = RuleDocs::for('sql_injection');

        $this->assertSame('sql_injection', $doc['id']);
        $this->assertSame('security', $doc['group']);
        $this->assertNotEmpty($doc['title']);
        $this->assertNotEmpty($doc['why']);
        $this->assertNotEmpty($doc['fix']);
        $this->assertNotEmpty($doc['refs']);
    }

    public function test_unknown_rule_has_fallback(): void
    {
        $doc = RuleDocs::for('totally_made_up');

        $this->assertFalse(RuleDocs::has('totally_made_up'));
        $this->assertSame('general', $doc['group']);
        $this->assertSame('Totally Made Up', $doc['title']);
        $this->assertNotEmpty($doc['why']);
    }

    public function test_for_is_case_insensitive(): void
    {
        $this->assertSame('n_plus_one', RuleDocs::for('N_PLUS_ONE')['id']);
    }
}
