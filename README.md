# CodeGuardian AI for Laravel

**Analyze. Improve. Validate. Report.**

A drop-in Composer package that brings AI-powered code analysis, security scanning, performance auditing, and test generation to any existing Laravel or Flutter project — all via artisan commands, no SaaS account required.

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | ^8.1 |
| Laravel | ^9.0 / ^10.0 / ^11.0 |
| AI API Key | OpenAI / Claude / Gemini |

---

## Installation

```bash
composer require codeguardian/laravel
```

Laravel's package auto-discovery will register the service provider automatically.

Publish the config file:

```bash
php artisan vendor:publish --tag=codeguardian-config
```

---

## Configuration

Add your AI API key to `.env`:

```env
# Choose your provider: openai | claude | gemini
CODEGUARDIAN_PROVIDER=openai

# OpenAI (default)
CODEGUARDIAN_OPENAI_KEY=sk-...

# Or Claude
CODEGUARDIAN_CLAUDE_KEY=sk-ant-...

# Or Gemini
CODEGUARDIAN_GEMINI_KEY=...
```

---

## Module-Based Laravel Support

CodeGuardian auto-detects your module structure. No extra config needed for:

| Structure | Directory | Framework |
|---|---|---|
| `nwidart/laravel-modules` | `Modules/UserModule/` | `composer require nwidart/laravel-modules` |
| Custom modules | `app/Modules/User/` | Any custom structure |
| DDD / Domain | `app/Domain/User/` | Domain-Driven Design |

For non-standard paths, add to `config/codeguardian.php`:
```php
'modules' => [
    'paths' => ['app/Components', 'src/Features'],
],
```

---

## Commands

### 1. Full Analysis

Run all agents: architecture, security, performance, tech debt, and test generation.

```bash
# Analyze your current project (auto-detects Laravel or Flutter)
php artisan codeguardian:analyze

# Specify a path and type explicitly
php artisan codeguardian:analyze --path=app/ --type=laravel

# Run specific agents only
php artisan codeguardian:analyze --agents=security,performance

# Output to a custom directory
php artisan codeguardian:analyze --output=storage/reports

# Only print to console, no files saved
php artisan codeguardian:analyze --no-report
```

**What it checks:**
- SOLID violations, fat controllers, missing service layers
- SQL injection, XSS, CSRF, missing authorization, secret exposure
- N+1 queries, missing indexes, cache opportunities
- Dead code, duplicate logic, complex methods
- Generates test cases for all discovered issues

**Output:** saves `scan-{project}-{timestamp}.json` and `.html` to `storage/codeguardian/reports/`

---

### 2. Interactive Refactoring (new)

The most powerful command — full workflow: analyze → write tests → refactor → verify.

```bash
# Full interactive refactoring of the whole project
php artisan codeguardian:refactor

# Refactor a specific module only
php artisan codeguardian:refactor --module=User
php artisan codeguardian:refactor --module=Order

# Refactor APIs matching a filter
php artisan codeguardian:refactor --api=GET:/api/users
php artisan codeguardian:refactor --api=UserController
php artisan codeguardian:refactor --api=POST:/api

# Auto mode (no interactive prompts — useful in CI)
php artisan codeguardian:refactor --mode=auto --module=Payment

# Skip file backups (not recommended)
php artisan codeguardian:refactor --no-backup

# Skip test execution
php artisan codeguardian:refactor --skip-tests
```

**What the workflow does, step by step:**

```
STEP 1/5 — ANALYZING CODE
  ✔  architect
  ✔  security
  ✔  performance
  ✔  tech_debt
  ✔  qa

  Overall Score : 62/100
  Total Issues  : 14  (2 critical, 5 high)

  ? Proceed with refactoring 14 issue(s)?  YES

STEP 2/5 — WRITING TESTS (before refactoring)
  ✔  Test written: tests/CodeGuardian/UserControllerTest.php
  ✔  Test written: tests/CodeGuardian/OrderServiceTest.php
  3 test files written to tests/CodeGuardian/

STEP 3/5 — RUNNING BASELINE TESTS
  ✅ Baseline: 12/12 passed (834ms)

STEP 4/5 — REFACTORING
  [1/4] app/Http/Controllers/UserController.php
    [CRITICAL] Fat controller — 320 lines, no service layer
    [HIGH]     Missing authorization policy
    ? Refactor this file?  YES
    Refactoring via AI...
  ✔  File updated
     → extract: Moved business logic to UserService
     → add_authorization: Added $this->authorize('update', $user)
  Running tests to verify...
  ✅ After UserController.php: 12/12 passed

  [2/4] app/Services/OrderService.php
    [HIGH] N+1 query in getOrders()
    ? Refactor this file?  YES
    ...

STEP 5/5 — VERIFYING TESTS AFTER REFACTORING
  ✅ Post-Refactor: 12/12 passed (891ms)

GENERATING FINAL REPORT
  📄 Reports saved:
     → storage/codeguardian/reports/scan-myapp-2025.json
     → storage/codeguardian/reports/scan-myapp-2025.html
```

---

### 3. Security Scan

OWASP Top 10 + mobile security review.

```bash
php artisan codeguardian:security

# Exit with error if critical issues found (useful in CI/CD)
php artisan codeguardian:security --fail-on=critical

# Fail on high+ issues
php artisan codeguardian:security --fail-on=high

# Save report
php artisan codeguardian:security --output=storage/reports
```

---

### 3. Performance Scan

```bash
php artisan codeguardian:performance

# Scan a specific directory
php artisan codeguardian:performance --path=app/Http/Controllers
```

**Finds:** N+1 queries, missing eager loading, missing DB indexes, cache opportunities, Flutter widget rebuild issues.

---

### 4. Generate Tests

```bash
# Generate tests for your entire project
php artisan codeguardian:test

# Preview in console without saving files
php artisan codeguardian:test --dry-run

# Save to custom directory
php artisan codeguardian:test --output=tests/AI/

# Scan a specific path
php artisan codeguardian:test --path=app/Http/Controllers
```

Generated tests are saved to `tests/CodeGuardian/` by default.

Run them with:
```bash
php artisan test tests/CodeGuardian/
# or
vendor/bin/phpunit tests/CodeGuardian/
```

---

### 5. View / Regenerate Report

```bash
# Regenerate HTML from the latest scan JSON
php artisan codeguardian:report --last

# From a specific file
php artisan codeguardian:report --file=storage/codeguardian/reports/scan-myapp-2025.json

# Open in browser automatically
php artisan codeguardian:report --last --open
```

---

## API-Specific Analysis

You can target specific APIs without scanning the entire project:

```bash
# Analyze all routes containing "users"
php artisan codeguardian:analyze --api=users

# Analyze a specific HTTP method + path
php artisan codeguardian:analyze --api=GET:/api/orders

# Analyze all routes handled by a specific controller
php artisan codeguardian:analyze --api=PaymentController

# Refactor just those APIs
php artisan codeguardian:refactor --api=GET:/api/users
```

The scanner will:
1. Parse your `routes/api.php` (and all module route files)
2. Find routes matching your filter
3. Load only the relevant controller + service files
4. Run the AI analysis on just those files

---

## CI/CD Integration

Add to your GitHub Actions workflow:

```yaml
- name: Security Scan
  run: php artisan codeguardian:security --fail-on=critical
  env:
    CODEGUARDIAN_PROVIDER: openai
    CODEGUARDIAN_OPENAI_KEY: ${{ secrets.OPENAI_API_KEY }}
```

---

## Analysis Workflow

This is the recommended workflow when reviewing a codebase:

```
1. php artisan codeguardian:analyze        ← Full scan, saves report
2. Open the HTML report in storage/codeguardian/reports/
3. php artisan codeguardian:test           ← Generate tests for found issues
4. Fix the issues (refactor based on recommendations)
5. php artisan codeguardian:analyze        ← Re-scan to confirm score improved
6. php artisan codeguardian:report --last  ← Final report for the team
```

---

## What Each Agent Does

| Agent | What It Analyzes |
|---|---|
| **Architect** | SOLID principles, service layer, repository pattern, DI, fat controllers/models |
| **Security** | SQL injection, XSS, CSRF, missing auth, secret exposure, IDOR, OWASP Top 10 |
| **Performance** | N+1 queries, missing indexes, cache opportunities, Flutter rebuild issues |
| **Tech Debt** | Dead code, duplication, large classes, complex methods, poor naming |
| **QA** | Generates unit, feature, API, widget, and integration tests |

---

## Supported Projects

| Project Type | Extensions Scanned |
|---|---|
| Laravel (any version) | `.php` |
| Flutter / Dart | `.dart` |

---

## Report Example

The HTML report includes:
- Overall quality score (0–100)
- Per-agent scores (architecture, security, performance, tech debt)
- All findings with severity badges (critical / high / medium / low)
- Code snippets showing the problem
- Recommended fixes with before/after examples
- All generated test cases

---

## License

MIT License — see [LICENSE](LICENSE)
