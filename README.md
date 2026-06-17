# CodeGuardian AI for Laravel

> **Analyze. Improve. Validate. Report.**

A static code analysis package for Laravel projects. Runs directly in your existing project via artisan commands.

**No API key needed. No internet required. No cost. 100% embedded.**

> AI mode is also available (optional) if you want richer explanations powered by OpenAI / Claude / Gemini.

---

## What It Detects

| Analyzer | What It Finds |
|---|---|
| **Architecture** | Fat controllers (>150 lines), missing service layer, direct DB in controllers, inline validation, facade overuse |
| **Security** | SQL injection, XSS `{!! !!}`, hardcoded secrets & API keys, mass assignment `$request->all()`, missing authorization, debug code left in production, insecure file uploads |
| **Performance** | N+1 queries, `Model::all()` without pagination, `count()` on loaded collections, missing eager loading, exports without chunking |
| **Tech Debt** | Large classes, high cyclomatic complexity, duplicated code blocks, TODO/FIXME debt, commented-out dead code, missing return types, magic numbers, deep nesting |
| **Test Generator** | Generates PHPUnit test stubs from method signatures (controller, service, model, generic) |
| **Refactoring** | Auto-fixes: `$request->all()` → `$request->validated()`, removes `dd()`/`dump()` debug calls. Reports manual fixes for the rest |

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | ^8.1 |
| Laravel | ^9.0 / ^10.0 / ^11.0 |
| API Key | **Not required** (static mode) |

---

## Installation

### On any machine (from GitHub)

**Step 1 — Add repository to `composer.json`**

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/bilalmahoon/codeguardian-laravel"
        }
    ]
}
```

**Step 2 — Install the package**

```bash
composer require codeguardian/laravel:dev-main
```

**Step 3 — Publish config (optional)**

```bash
php artisan vendor:publish --tag=codeguardian-config
```

This creates `config/codeguardian.php` where you can customize skip directories, output paths, etc.

---

### Local development (path repository)

If you have the package source locally:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/absolute/path/to/packages/codeguardian-laravel",
            "options": { "symlink": true }
        }
    ]
}
```

```bash
composer require codeguardian/laravel:@dev
```

---

## Configuration

The only required `.env` setting is — nothing. By default it runs in **static mode**, no API key needed.

```env
# Engine mode (default: static — no API key needed)
CODEGUARDIAN_MODE=static

# Optional: use AI for richer analysis
# CODEGUARDIAN_MODE=ai
# CODEGUARDIAN_PROVIDER=openai
# CODEGUARDIAN_OPENAI_KEY=sk-...
```

---

## Usage

### Full analysis (most common)

```bash
# Analyze entire project
php artisan codeguardian:analyze

# Analyze a specific directory
php artisan codeguardian:analyze --path=app/Http/Controllers

# Analyze a specific module (nwidart/laravel-modules)
php artisan codeguardian:analyze --module=User

# Analyze only APIs matching a route filter
php artisan codeguardian:analyze --api=orders

# Run specific analyzers only
php artisan codeguardian:analyze --agents=security,performance

# Print to console only (no report files)
php artisan codeguardian:analyze --no-report

# Analyze then immediately start interactive refactoring
php artisan codeguardian:analyze --refactor
```

---

### Security scan only

```bash
php artisan codeguardian:security

# Scan specific path
php artisan codeguardian:security --path=app/Http

# Fail CI if any high+ issues found
php artisan codeguardian:security --fail-on=high
```

---

### Performance scan only

```bash
php artisan codeguardian:performance

php artisan codeguardian:performance --path=app/Services
```

---

### Generate test stubs

```bash
# Generate PHPUnit test stubs for all classes
php artisan codeguardian:test

# Preview without writing files
php artisan codeguardian:test --dry-run

# Generate test for one specific file
php artisan codeguardian:test --file=UserController.php
```

Generated tests are saved to `tests/CodeGuardian/` by default. They are **stubs** — review and fill in proper test data before running.

---

### Interactive refactoring workflow

```bash
php artisan codeguardian:refactor
```

This runs the full workflow:

```
1. Analyze code     → find all issues
2. Show report      → ask confirmation to proceed
3. Generate tests   → write test stubs BEFORE changing any code
4. Run tests        → establish baseline (passes/fails)
5. Refactor         → fix one file at a time (ask per file in interactive mode)
   - Auto-fixes:    $request->all() → $request->validated(), remove dd()/dump()
   - Manual todos:  listed per file for issues requiring human judgment
6. Run tests again  → verify nothing broke (per file or at end)
   - If tests fail: rollback this file / continue / stop
7. Final report     → before/after summary saved to storage/codeguardian/reports/
```

Options:

```bash
# Refactor a specific module
php artisan codeguardian:refactor --module=Order

# Refactor APIs matching a filter
php artisan codeguardian:refactor --api=invoices

# Auto mode (no prompts — useful in CI)
php artisan codeguardian:refactor --mode=auto

# Skip creating backups (not recommended)
php artisan codeguardian:refactor --no-backup

# Skip test execution
php artisan codeguardian:refactor --skip-tests
```

---

### Generate report from last analysis

```bash
php artisan codeguardian:report

php artisan codeguardian:report --format=html
php artisan codeguardian:report --format=json
```

Reports are saved to `storage/codeguardian/reports/`.

---

## Understanding the Results

### Severity levels

| Level | Meaning |
|---|---|
| 🔴 **Critical** | Must fix before production (SQL injection, hardcoded secrets, etc.) |
| 🟠 **High** | Significant architectural or security problem |
| 🟡 **Medium** | Code quality issue that should be addressed |
| 🟢 **Low** | Minor improvement (style, naming, etc.) |

### Score & Grade

| Score | Grade | Meaning |
|---|---|---|
| 90–100 | A | Excellent |
| 80–89 | B | Good |
| 70–79 | C | Acceptable |
| 60–69 | D | Needs work |
| < 60 | F | Needs significant refactoring |

---

## Example Output

```
CodeGuardian AI — Analyze. Improve. Validate. Report.

📁 Scanning: /var/www/my-project/app/Http/Controllers
🔧 Project type: laravel  |  Scope: Full project
🔍 Engine: ⚡ Static engine (no API key needed)

  Scanning files...
  ✔  Found 17 files (1259 lines)

  Running architect analyzer...
  Running security analyzer...
  Running performance analyzer...
  Running tech_debt analyzer...

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ANALYSIS SUMMARY
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Overall Score: 54/100  (Grade: F)
  Architecture Score: 60/100
  Security Score: 50/100
  Performance Score: 70/100
  Tech Debt Score: 65/100

  Total Issues: 23
  🔴 Critical: 2
  🟠 High:     7
  🟡 Medium:   9
  🟢 Low:      5

  TOP FINDINGS:
  [CRITICAL] Hardcoded secret/credential detected
             → PaymentController.php:45
  [CRITICAL] Potential SQL Injection vulnerability
             → ReportController.php:112
  [HIGH] Fat Controller: OrderController (312 lines, 14 methods)
         → OrderController.php
  [HIGH] Missing authorization in InvoiceController
         → InvoiceController.php
  [HIGH] Potential N+1 query inside loop
         → DashboardController.php:67

  MOST ISSUES IN:
  8 issues  → OrderController.php
  5 issues  → ReportController.php
  3 issues  → DashboardController.php
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📄 Reports saved:
   → /var/www/my-project/storage/codeguardian/reports/scan-Controllers-2026-06-17_10-00-00.json
   → /var/www/my-project/storage/codeguardian/reports/scan-Controllers-2026-06-17_10-00-00.html
```

---

## How It Works (no AI)

The package uses a **rule-based static analysis engine** built on top of PHP token scanning and pattern matching:

| Component | What it does |
|---|---|
| `ArchitectureAnalyzer` | Counts lines/methods per class, detects DB calls in controllers, checks for FormRequest usage |
| `SecurityAnalyzer` | Regex patterns for SQL injection, secret patterns (OpenAI/AWS/Google key formats), `$request->all()`, `{!! !!}` |
| `PerformanceAnalyzer` | Detects relationship access inside foreach loops, `::all()` without pagination, `count()` on loaded collections |
| `TechDebtAnalyzer` | Measures cyclomatic complexity, finds duplicate 5-line blocks across files, counts TODOs, measures nesting depth |
| `StaticTestGenerator` | Parses class/method signatures, generates PHPUnit test stubs with correct namespaces and method names |
| `StaticOrchestrator` | Runs all analyzers, merges results, calculates score, handles deterministic auto-fixes |

---

## Optional: AI Mode

If you want more detailed natural language recommendations, add an API key:

```env
CODEGUARDIAN_MODE=ai
CODEGUARDIAN_PROVIDER=openai       # or: claude, gemini
CODEGUARDIAN_OPENAI_KEY=sk-...
```

Then run with `--mode=ai`:

```bash
php artisan codeguardian:analyze --mode=ai
```

Or set `CODEGUARDIAN_MODE=ai` in `.env` to make AI the default.

> **Note**: Static mode finds the same structural issues as AI mode for most common patterns. AI mode adds natural language summaries and catches more subtle logic issues that require understanding program intent.

---

## CI/CD Integration

```yaml
# .github/workflows/quality.yml
- name: Run CodeGuardian
  run: |
    php artisan codeguardian:security --fail-on=high
    php artisan codeguardian:analyze --no-report --agents=security,performance
```

Exit codes:
- `0` = Success (no issues at or above `--fail-on` level)
- `1` = Issues found at the specified severity level

---

## License

MIT
