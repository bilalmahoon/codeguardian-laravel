<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Agents;

use CodeGuardian\Laravel\Agents\QaAgent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class QaAgentTest extends TestCase
{
    private function agent(): QaAgent
    {
        return (new ReflectionClass(QaAgent::class))->newInstanceWithoutConstructor();
    }

    private function call(QaAgent $agent, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($agent, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($agent, $args);
    }

    public function test_name_is_qa(): void
    {
        $this->assertSame('qa', $this->agent()->getName());
    }

    /**
     * Regression guard: buildUserPrompt used an INTERPOLATING heredoc that
     * contained a bare `$user` (in "actingAs($user)"), so PHP tried to expand
     * an undefined variable — the qa agent crashed with "Undefined variable
     * $user" on every hybrid/ai run. The prompt must build cleanly and keep the
     * literal text.
     */
    public function test_user_prompt_builds_without_undefined_variable_warning(): void
    {
        // PHPUnit turns the "Undefined variable" warning into a failure, so this
        // call itself would fail if the heredoc regressed.
        $prompt = $this->call($this->agent(), 'buildUserPrompt', [[
            'project_type' => 'laravel',
            'project_name' => 'Shop',
            'files'        => ['app/Http/Controllers/OrderController.php' => "<?php\nclass OrderController {}"],
            'issues'       => [],
        ]]);

        $this->assertStringContainsString('actingAs($user)', $prompt);
        $this->assertStringContainsString('Shop', $prompt);
        $this->assertStringContainsString('RefreshDatabase', $prompt);
    }
}
