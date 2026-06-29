# CodeGuardian AI for Laravel

> **Analyze. Improve. Validate. Report.**

A static code analysis package for Laravel projects. Runs directly in your existing project via artisan commands.

**No API key needed. No internet required. No cost. 100% embedded.**

> AI mode is also available (optional) if you want richer explanations powered by OpenAI / Claude / Gemini.

---

## What It Detects

| Analyzer | What It Finds |
|---|---|
| **Architecture** | Fat controllers (>150 lines), missing service layer, direct DB in controllers, inline validation, facade overuse, business/integration logic in models, `env()` used outside `config/` (null after `config:cache`) |
| **Security** | SQL injection, XSS `{!! !!}`, hardcoded secrets & API keys, mass assignment (`$request->all()` **and** `$guarded = []`), missing authorization, debug code, insecure file uploads, **command injection, code injection (`eval`), insecure deserialization, weak crypto (md5/sha1 on secrets), predictable randomness for tokens, path traversal, SSRF, open redirect, blanket CSRF exemption, hard-enabled debug, dynamic file inclusion, disabled TLS verification** |
| **Performance** | N+1 queries, query-in-loop (whereIn batching), over-fetching (`::all()->filter()`), nested loops (O(n²)), `Model::all()` without pagination, `count()` on loaded collections, missing eager loading, exports without chunking |
| **Tech Debt** | Large/God classes, high cyclomatic complexity, duplicated code blocks, TODO/FIXME debt, dead code, missing return types, magic numbers, deep nesting, long parameter lists, boolean flag parameters, empty/swallowed catch blocks |
| **Test Generator** | Generates PHPUnit test stubs from method signatures (controller, service, model, generic) |
| **Refactoring** | Auto-fixes: `$request->all()` → `$request->validated()`, removes `dd()`/`dump()` debug calls. Reports manual fixes for the rest |

> **Security taxonomy.** Every security finding is mapped to its **OWASP Top 10 (2021)** category and a **CWE** id, with an indicative **CVSS band**, a **confidence** level, expected **impact**, estimated **effort**, and **breaking-change risk** — so findings are triage-ready, not just flags.

---

## Web Dashboard

Prefer a UI over the terminal? CodeGuardian ships a built-in dashboard. After installing the package, just open:

```
https://your-app.test/codeguardian
```

From there you can:

- **Run everything from the browser** — analyze, security audit, performance review, generate tests, and refactor. Pick the target from **searchable dropdowns** (module list, API routes, web routes, artisan commands) instead of typing it by hand.
- **Watch live progress** — each run executes in the background and streams its console output to the page in real time.
- **Browse full history** — every past run is listed with its type, target, status, and a link to open its saved HTML/JSON report.

### Security

The dashboard is **local-only by default** (only reachable when `APP_ENV=local`). To control access:

- Define a Gate named `viewCodeGuardian` in your `AuthServiceProvider` — it takes precedence and lets you restrict by user/role, **or**
- Set `CODEGUARDIAN_DASHBOARD_LOCAL_ONLY=false` to open it (combine with the gate or your own middleware for production).

Config (`config/codeguardian.php` → `dashboard`):

```php
'dashboard' => [
    'enabled'           => env('CODEGUARDIAN_DASHBOARD', true),
    'path'              => env('CODEGUARDIAN_DASHBOARD_PATH', 'codeguardian'),
    'middleware'        => ['web'],
    'restrict_to_local' => env('CODEGUARDIAN_DASHBOARD_LOCAL_ONLY', true),
],
```

Customise the UI by publishing the views: `php artisan vendor:publish --tag=codeguardian-views`.

---

## Foolproof Refactoring (test-first safety net)

Refactors run a **test → refactor → verify** loop so a change that breaks a test never ships:

1. Generate test stubs for the files in scope.
2. Establish a baseline (pre-existing failures are ignored — only NEW failures count).
3. Refactor each file (static auto-fixes + AI deep refactor).
4. Re-run tests; if a file's refactor introduces a **new** failure, it is **automatically rolled back** to the original.

Enabled automatically from the dashboard, with `--safe`, in `--mode=auto`, or via config:

```php
'refactor' => [
    'safe_mode'             => env('CODEGUARDIAN_SAFE_MODE', true),
    'auto_rollback_on_fail' => env('CODEGUARDIAN_AUTO_ROLLBACK', true),
],
```

```bash
php artisan codeguardian:refactor --api=v1/auth/login --safe
```

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

### Anatomy of a finding

Every finding is **actionable** — it carries the context an engineer needs to triage and fix it without re-reading the whole file:

| Field | Meaning |
|---|---|
| `severity` | critical / high / medium / low |
| `confidence` | high / medium / low — how sure the rule is (use to tune signal/noise) |
| `category` | machine-readable type, e.g. `sql_injection`, `n_plus_one`, `god_class` |
| `description` | **why it matters**, with the offending line number |
| `root_cause` | the underlying reason it happens |
| `code_snippet` | the evidence (the offending line) |
| `recommendation` | the suggested fix |
| `code_before` / `code_after` | a concrete better implementation |
| `impact` | the expected benefit of fixing it |
| `effort` | trivial / small / medium / large |
| `breaking_risk` | none / low / medium / high — how risky the fix is |
| `cwe` / `owasp` | security taxonomy (security findings) |
| `principle` | the engineering principle involved (SOLID:SRP, Clean Code, …) |

All fields are included in the JSON report and rendered in the HTML report.

### Risk score (explainable)

In addition to the quality **scores** (how good the code is), `analyze` produces a **risk score** (0–100, how *urgently* it needs attention), weighted by **severity × confidence**, and always paired with plain-English reasoning:

```
  Risk Score: 62/100  (CRITICAL)
    • 2 critical finding(s) drive the risk to its ceiling — these should block release.
    • Concentrated in: sql injection (2), n plus one (3), missing types (4).
    • Weighted across 14 finding(s) by severity × confidence.
```

---

## Filtering findings

Every review command supports optional, **combinable** filters. With no filter flags the behaviour is unchanged (everything is reported). Filters are AND-combined; each CSV value is OR-combined.

| Flag | Example | Keeps |
|---|---|---|
| `--severity` | `--severity=critical,high` | only those severities |
| `--min-severity` | `--min-severity=high` | findings at or above that severity |
| `--category` | `--category=sql_injection,n_plus_one` | categories matching any substring |
| `--confidence` | `--confidence=high` | findings at that confidence |
| `--owasp` | `--owasp=A03,A01` | security findings in those OWASP categories |
| `--cwe` | `--cwe=CWE-89` | findings with that CWE id |

```bash
# Only high-confidence, high+ severity issues
php artisan codeguardian:analyze --min-severity=high --confidence=high

# Only injection-class security issues
php artisan codeguardian:security --owasp=A03

# Only N+1 / query-in-loop performance issues
php artisan codeguardian:performance --category=n_plus_one,query_in_loop
```

Available on `codeguardian:analyze`, `codeguardian:security`, and `codeguardian:performance`. (`security` and `performance` also support `--owasp`/`--cwe` where applicable.)

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

## Extending the engine (add your own rule)

The static engine is built from small, independent analyzers. Adding a check is a localized change:

1. Open the relevant analyzer in `src/Analyzers/` (`SecurityAnalyzer`, `PerformanceAnalyzer`, `ArchitectureAnalyzer`, or `TechDebtAnalyzer`).
2. Add a `private function checkYourRule(string $filePath, string $content): void` and call it from `analyze()`.
3. Emit findings via `AnalysisResult::make()` — populate the actionable metadata so your rule is triage-ready:

```php
$this->addResult(AnalysisResult::make(
    category:       'my_rule',
    severity:       'high',
    title:          'Short, specific title',
    description:    'Why it matters, with the line number.',
    file:           $filePath,
    lineStart:      $lineNum + 1,
    recommendation: 'The concrete fix.',
    codeBefore:     '...',
    codeAfter:      '...',
    confidence:     'high',          // tune signal/noise
    impact:         'What fixing it buys you.',
    effort:         'small',
    breakingRisk:   'low',
    rootCause:      'The underlying reason.',
    cwe:            'CWE-123',        // security rules
    owasp:          'A01:2021-...',   // security rules
    principle:      'SOLID:SRP',      // design rules
));
```

4. Add a unit test in `tests/Unit/Analyzers/` asserting your category is found on a positive fixture **and not** on a clean one (guarding against false positives).

Design principles for new rules: **high confidence, low false positives**. When a pattern is ambiguous, gate it on a taint/context signal and set `confidence` accordingly rather than emitting a noisy finding.

---

## Troubleshooting

| Symptom | Cause / Fix |
|---|---|
| `No issues found` on a large codebase in seconds | You're in `static` mode (expected — it's fast). For deeper, intent-aware review add an API key and use `--mode=hybrid`. |
| `--api=...` analyzes unrelated files | Route resolution falls back to regex if the Laravel router can't resolve the URI. Verify the route exists with `php artisan route:list`. |
| Reports not generated | Check write permissions on `storage/codeguardian/`, or pass `--output=` to a writable directory. |
| Too much noise | Use filters: `--min-severity=high`, `--confidence=high`, or `--category=`. |
| AI mode falls back to static | No/invalid API key, or the configured model returned 404. Check `CODEGUARDIAN_PROVIDER` and the matching key in `.env`. |
| Dashboard returns 403 | It's local-only by default. Set `APP_ENV=local`, define a `viewCodeGuardian` gate, or set `CODEGUARDIAN_DASHBOARD_LOCAL_ONLY=false`. |
| `env()` finding in app code | Intentional design rule — `env()` returns null after `config:cache`. Move the value into `config/*.php` and read it via `config()`. |

---

## License

MIT
