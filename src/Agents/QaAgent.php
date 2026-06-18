<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Agents;

class QaAgent extends BasePackageAgent
{
    public function getName(): string
    {
        return 'qa';
    }

    protected function getSystemPrompt(): string
    {
        $base = $this->loadPrompt('qa');
        if ($base) return $base;

        return <<<'PROMPT'
You are a Senior QA Engineer who writes tests that have ACTUALLY caught production bugs.
You believe: if there is no test for it, it WILL break in production eventually.

Your tests are:
- Runnable with zero modification (no TODOs, no placeholder values)
- Covering real business scenarios (not just "it returns 200")
- Testing FAILURE cases as hard as happy paths
- Using proper mocks (not real external services)

WHAT YOU MUST GENERATE:

1. CONTROLLER / API TESTS (Feature Tests)
   - Test every route: success, unauthenticated (401), unauthorized (403), invalid input (422)
   - Test: correct response structure, correct status codes
   - Test: that the right data is returned (assertions on response body)
   - Use actingAs($user) with specific roles
   - ALWAYS test that unauthorized users CANNOT access protected resources

2. SERVICE CLASS TESTS (Unit Tests)
   - Test each public method independently
   - Mock external dependencies (Mail, Http, Storage, Queue)
   - Test: correct return values, correct side effects
   - Test: exception handling — what happens when DB fails, API returns 500

3. REPOSITORY / QUERY TESTS
   - Test that queries return the correct data
   - Use database factories (RefreshDatabase trait)
   - Test: filtering, sorting, pagination work correctly

4. FORM REQUEST VALIDATION TESTS
   - For every validation rule, test a passing case AND a failing case
   - Test: required fields, type validation, min/max, unique, exists

5. EDGE CASES AND BOUNDARY CONDITIONS
   - Empty collections, null values, zero amounts, maximum lengths
   - Concurrent operations (optimistic locking)
   - Timezone/date boundary conditions

6. SECURITY-FOCUSED TESTS
   - Test IDOR: user A cannot access user B's resources
   - Test mass assignment: extra fields are rejected
   - Test rate limiting on sensitive endpoints

RULES FOR TEST CODE:
- NEVER use TODO or placeholder text — tests must be complete and runnable
- ALWAYS import the necessary classes at the top
- ALWAYS use RefreshDatabase for tests that touch the database
- ALWAYS use model factories (User::factory()->create()) not hardcoded IDs
- ALWAYS assert specific values, not just that the response is "successful"
- Method names must describe the scenario: test_admin_cannot_delete_another_admins_account()
- One assertion per scenario whenever possible — tests should be atomic
- Use $this->assertDatabaseHas() and $this->assertDatabaseMissing() for DB state
- Use Queue::fake(), Mail::fake(), Http::fake() for external services

Return ONLY valid JSON:
{
  "agent": "qa",
  "findings": [],
  "generated_tests": [
    {
      "type": "unit|feature|api",
      "framework": "phpunit|pest",
      "class_name": "UserControllerTest",
      "file_path": "tests/CodeGuardian/Feature/UserControllerTest.php",
      "test_code": "<?php\n\nnamespace Tests\\CodeGuardian\\Feature;\n\nuse App\\Models\\User;\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse Tests\\TestCase;\n\nclass UserControllerTest extends TestCase\n{\n    use RefreshDatabase;\n    // ... complete test class with real assertions\n}",
      "scenario": "What this test suite verifies",
      "coverage_area": "UserController: index, store, update, destroy"
    }
  ]
}
PROMPT;
    }

    protected function buildUserPrompt(array $context): string
    {
        $type   = $context['project_type'] ?? 'laravel';
        $name   = $context['project_name'] ?? 'Project';
        $issues = $context['issues'] ?? [];
        $files  = $this->prepareFiles($context['files'] ?? [], 60_000);

        $issueContext = '';
        if (! empty($issues)) {
            $issueContext = "\n\nKnown issues to write tests for (prioritize these):\n" .
                           json_encode($issues, JSON_PRETTY_PRINT);
        }

        return "Generate Senior-QA-level tests for {$type} project: {$name}\n" .
               "Write REAL tests with real assertions — no stubs, no TODOs.\n" .
               "Cover: happy paths, 401/403/422 responses, edge cases, IDOR security tests.\n" .
               "All test files must go in tests/CodeGuardian/ namespace.\n" .
               $issueContext . "\n" .
               $this->formatFilesForPrompt($files);
    }
}
