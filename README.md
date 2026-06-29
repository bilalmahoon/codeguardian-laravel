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

## Premium CLI experience

`analyze`, `security`, and `performance` render a **live, multi-stage pipeline** in the terminal instead of static logs — spinners, per-stage timers, an overall progress bar, **ETA**, the **file currently being analyzed**, and live counters, followed by an **execution-stats** card with a per-stage time breakdown.

```
  CodeGuardian · Static analysis · Security Analysis  █████████████░░░░░░░░░ 62%   elapsed 193ms · ETA 64ms
  ├─ ✓ Architecture Analysis   45 findings · 56ms
  ├─ ✓ Security Analysis       26 findings · 88ms
  ├─ ⠹ Performance Analysis    12/17  PaymentService.php
  └─ ○ Tech-Debt Analysis
     files 17 · rule groups 4 · suggestions 71

  ┌─ Execution stats ───────────────────────────────┐
  │ ✓ Architecture Analysis  ██░░░░░░░░    56ms · 22%
  │ ✓ Security Analysis      ████░░░░░░    88ms · 35%
  │ ✓ Performance Analysis   ██░░░░░░░░    44ms · 17%
  │ ✓ Tech-Debt Analysis     ██░░░░░░░░    59ms · 23%
  ├─────────────────────────────────────────────────┤
  │ Total 254ms · 17 files · 67/s throughput · 4 rules
  └─────────────────────────────────────────────────┘
```

It's **TTY-aware**: when output isn't a terminal (CI, pipes, log files) it automatically degrades to clean one-line-per-stage logging. Force that anywhere with `--plain`:

```bash
php artisan codeguardian:analyze --plain      # CI-friendly plain logs
```

### Execution flow

```
Project Discovery → Scanning → ┌ Architecture ┐
                               │ Security      │  (live per-file ticks)
                               │ Performance   │
                               └ Tech-Debt     ┘ → Risk Scoring → Report Generation
```

Progress is driven by **real** per-file/per-stage events emitted by the analysis engine — not simulated — so the percentage, ETA, and "current file" reflect actual work.

### Refactoring pipeline

`refactor` runs as a **7-stage pipeline** — Project Discovery → Static Analysis → Test Generation → Baseline Tests → Refactoring → Final Verification → Report Generation — with a per-stage time breakdown at the end. Because refactoring is interactive (diffs, prompts, test output) it renders as a clean scrolling staged log rather than a single repainting block. Use `--plain` for plain step headers.

Every recommended change ships with a **justification card** — refactoring is never proposed without explaining itself:

```
  CRITICAL Possible OS command injection
    Why       Untrusted data reaches an OS command sink.
    Benefit   Remote code execution on the server.
    Breaking  medium
    Effort    medium
    Confidence high
    Standard  OWASP A03:2021-Injection · CWE-78
    Fix       Use escapeshellarg() on every argument, or Symfony Process.
```

---

## Enterprise reporting

Every analysis produces an enterprise-quality report (console + HTML) with an **executive summary** and a **six-dimension quality scorecard**, each with a 0–100 score, a letter grade, and plain-English reasoning:

| Dimension | What it measures |
|---|---|
| **Architecture** | Structure, layering, SOLID, coupling |
| **Security** | Resistance to the OWASP Top 10 / known weaknesses |
| **Performance** | Query efficiency, CPU cost, scalability |
| **Maintainability** | Complexity, duplication, readability |
| **Testability** | How easily the code can be unit-tested |
| **Reliability** | Error handling and operational fail-safes |

```
  QUALITY DIMENSIONS:
  Architecture     ██████████░░░░░░   64/100  (D)
  Security         █████░░░░░░░░░░░   34/100  (F)
  Performance      █████████████░░░   82/100  (B)
  Maintainability  ████████░░░░░░░░   55/100  (F)
  Testability      ███████░░░░░░░░░   48/100  (F)
  Reliability      ████████████░░░░   72/100  (C)

  Composite quality: 59/100  (Grade F)
```

The HTML report adds an **Executive Summary** card (headline verdict, grade, risk level, key counts) and a visual **Quality Dimensions** scorecard above the detailed findings — suitable for sharing with leadership.

Reports are available in multiple formats via `--format`:

```bash
php artisan codeguardian:analyze --format=json   # machine-readable
php artisan codeguardian:analyze --format=html   # rich dashboard
php artisan codeguardian:analyze --format=md     # Markdown — ideal for CI artifacts / PR comments
php artisan codeguardian:analyze --format=both   # json + html (default)
php artisan codeguardian:analyze --format=sarif  # SARIF 2.1.0 — code-scanning platforms
php artisan codeguardian:analyze --format=junit  # JUnit XML — CI "Tests" panels
php artisan codeguardian:analyze --format=all    # json + html + md + sarif + junit
```

---

## Continuous Integration

CodeGuardian is built for CI: standard SARIF output, a baseline/diff mode that fails only on **new** regressions, and a `doctor` preflight check.

### SARIF — GitHub / GitLab / Azure

`--format=sarif` emits strict, schema-valid **SARIF 2.1.0**, ingested natively by GitHub code scanning, Azure DevOps (SARIF SAST Scans Tab), and GitLab. Severities map to SARIF `level` and the GitHub `security-severity` property; each result carries a stable `partialFingerprint` and CWE/OWASP `helpUri`s. When a finding has a concrete replacement, a SARIF `fixes[]` entry is emitted so GitHub and IDEs can offer a one-click apply.

**GitHub Actions:**

```yaml
- run: php artisan codeguardian:analyze --format=sarif --plain
- uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: storage/codeguardian/reports   # picks up *.sarif
```

**GitLab CI:**

```yaml
codeguardian:
  script: php artisan codeguardian:analyze --format=sarif --plain
  artifacts:
    reports:
      sast: storage/codeguardian/reports/*.sarif
```

**Azure DevOps:** publish the `*.sarif` as a build artifact; the *SARIF SAST Scans Tab* extension renders it.

### GitHub inline PR annotations (zero setup)

Don't want to wire up SARIF upload? Pass `--annotate` (auto-enabled when running inside GitHub Actions) and findings show up as inline annotations on the PR's "Files changed" view — no extra action, no upload:

```yaml
- run: php artisan codeguardian:analyze --annotate --plain
```

Severities map to `::error` (critical/high), `::warning` (medium), and `::notice` (low); the most severe findings are emitted first (capped to avoid noise).

### PR / MR summary comment

`codeguardian:comment` posts a concise Markdown summary (quality + risk scores, a severity table, and the top findings) as a comment on the current **GitHub PR** or **GitLab MR**. The platform is auto-detected from CI env vars; force it with `--platform=`. A hidden marker is embedded so a poster can locate and update its previous comment instead of stacking new ones.

```bash
php artisan codeguardian:comment --dry-run     # preview the body, post nothing (safe anywhere)
php artisan codeguardian:comment               # auto-detect platform + post
php artisan codeguardian:comment --platform=gitlab --mr=42
```

- **GitHub** uses the `gh` CLI (install + authenticate via `GH_TOKEN`).
- **GitLab** uses the REST API; set `CODEGUARDIAN_GITLAB_TOKEN` (or rely on `CI_JOB_TOKEN`) — `CI_PROJECT_ID` and the MR IID are read from the pipeline.
- It reads the latest JSON report by default, or pass `--report=path/to/report.json`.

```yaml
# GitHub Actions
- run: php artisan codeguardian:analyze --format=json --plain
- run: php artisan codeguardian:comment
  env:
    GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

### JUnit — CI "Tests" panels

`--format=junit` writes JUnit XML where every finding is a failed `<testcase>`, grouped into a `<testsuite>` per analyzer. This lights up the native test report UI in GitHub Actions test reporters, GitLab, Jenkins, CircleCI, Azure DevOps, and Bitbucket.

```yaml
# GitLab CI
codeguardian:
  script: php artisan codeguardian:analyze --format=junit --plain
  artifacts:
    reports:
      junit: storage/codeguardian/reports/*.junit.xml
```

### Health trend over time

Every `analyze` run appends a compact metric record (score, risk, severity counts, quality dimensions) to a history file. View the trajectory anytime:

```bash
php artisan codeguardian:trend            # table + direction + score sparkline
php artisan codeguardian:trend --limit=50
php artisan codeguardian:trend --json     # for dashboards
```

Disable recording for a single run with `--no-history`; change the location via `codeguardian.output.history_file`. The **HTML report** also embeds a quality-score sparkline of recent runs in its executive summary.

### Baseline / diff — fail only on new findings

Adopt CodeGuardian on a legacy codebase without drowning in pre-existing debt. Record a baseline once, then fail CI only when a PR introduces **new** findings:

```bash
# once, on a clean main branch — commit the file
php artisan codeguardian:analyze --write-baseline

# in CI on every PR — non-zero exit only if NEW findings appear
php artisan codeguardian:analyze --against-baseline --new-only --plain
```

Fingerprints are line-number-independent (category + file + title + normalised snippet), so unrelated edits above a finding never churn the baseline. The summary reports **new / existing / fixed** counts. Custom path via `--baseline-file=`.

### Doctor — preflight diagnostics

`codeguardian:doctor` verifies PHP version, required extensions, AI configuration, writable paths, the test runner, and dashboard safety — with an actionable fix for anything that's wrong. It returns a non-zero exit code on hard failures, so it can gate a pipeline.

```bash
php artisan codeguardian:doctor          # human-readable report
php artisan codeguardian:doctor --json   # machine-readable for CI
```

```
  ✓ PHP version          PHP 8.2.0 (>= 8.1.0)
  ✓ Extension: tokenizer tokenizer is loaded
  ✗ AI provider          Mode 'hybrid' requires an API key for 'claude', but none is set
      ↳ Set CODEGUARDIAN_CLAUDE_KEY in your .env, or switch CODEGUARDIAN_MODE=static.
  ⚠ Test runner          No PHPUnit/Pest binary found
      ↳ Install PHPUnit so refactor can verify changes.
```

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

# Analyze, then automatically FIX the issues (safe mode: test-verified, auto-rollback)
php artisan codeguardian:analyze --fix
```

---

### Fixing issues after analysis

When `analyze` finishes and finds issues, it offers to fix them right there — no need to remember a second command:

```
✗ 12 issues found (2 critical, 5 high, …)
📄 Reports saved: …

  🔧 CodeGuardian can attempt to FIX these issues for you.
     Safe mode: every change is verified by tests and automatically
     rolled back if it would break anything. Backups are kept.

  Do you want to proceed with fixing now? (yes/no) [no]:
```

Answer **yes** and it runs the full refactor pipeline (write tests → refactor → verify → auto-rollback on regression) over the same scope you analyzed. You can also skip the prompt:

| Flag | Behaviour |
|---|---|
| `--fix` | Auto-fix in **safe mode** (no prompts, test-verified, auto-rollback) |
| `--refactor` | Start the **interactive** refactoring workflow |
| *(neither)* | Interactive runs get the "fix now?" prompt; CI / `--no-interaction` skips it |

The prompt only appears in an interactive terminal, so CI runs are never blocked.

**In the web dashboard**, a completed analyze run shows a **🔧 Fix these issues** panel. It lists every file that has findings (most issues first) with checkboxes, so you can:

- **Select specific files** to refactor only those (great for large reports), or
- **Leave everything unchecked** to fix the whole analyzed scope.

Either way it launches the same safe, test-verified refactor (auto-rollback on regression).

From the CLI you can target multiple files directly:

```bash
php artisan codeguardian:refactor --files="app/Services/AuthService.php,app/Http/Controllers/Auth/LoginController.php" --safe
```

`--files=` traces each file's dependency chain (via Reflection) and refactors them together, just like `--file=` does for a single file.

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

### Diagnose your setup

```bash
php artisan codeguardian:doctor          # health check with fix suggestions
php artisan codeguardian:doctor --json   # machine-readable
php artisan codeguardian:rules           # list every detection rule + its state
php artisan codeguardian:rules sql_injection  # full docs for one rule (why + fix + refs)
php artisan codeguardian:trend           # code-health trend across past runs
php artisan codeguardian:comment --dry-run    # preview the PR/MR summary comment
```

See [Continuous Integration](#continuous-integration) for SARIF, baseline/diff, and CI wiring.

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

## Suppressing findings (noise control)

Filters change what's *shown*; suppression removes findings entirely (from scores, reports, and CI exit codes). Two complementary mechanisms:

**Config** (`config/codeguardian.php → 'ignore'`):

```php
'ignore' => [
    'categories' => ['magic_numbers', 'commented_code'],   // never report these
    'paths'      => ['database/migrations/', 'tests/*'],    // substrings or globs
],
```

**Inline**, right in the source — for one-off, reviewed exceptions:

```php
$hash = md5($value);                 // codeguardian-ignore weak_cryptography
// codeguardian-ignore               (a marker on the line above counts too)
$legacy = $request->all();
```

```php
// codeguardian-ignore-file          (suppresses every finding in this file)
```

A bare `// codeguardian-ignore` suppresses any finding on that line; adding category names limits it. Use `--no-suppress` to temporarily see everything.

### Configuring rules (enable / disable / re-severity)

Take full control of the engine from config — no code changes. Rules are keyed by their finding category; list them all with `php artisan codeguardian:rules`.

```php
// config/codeguardian.php
'rules' => [
    'magic_numbers' => false,        // turn a rule off entirely
    'missing_types' => 'low',        // downgrade severity
    'n_plus_one'    => 'critical',   // upgrade severity
    'todo_debt'     => ['enabled' => true, 'severity' => 'low'],
],
```

```bash
php artisan codeguardian:rules                       # full catalog + effective state
php artisan codeguardian:rules --group=security      # one analyzer group
php artisan codeguardian:rules --enabled-only --json # machine-readable
```

Severity overrides apply *before* scoring, filters, and CI gates, so `--min-severity` / `--fail-on` see the effective severities.

#### Per-rule documentation

Every rule ships with a short, opinionated explainer — what it means, **why it matters**, **how to fix it**, and authoritative references (OWASP / CWE / Laravel docs). Look one up from the CLI:

```bash
php artisan codeguardian:rules sql_injection         # full docs for a single rule
php artisan codeguardian:rules n_plus_one --json     # machine-readable detail
```

HTML reports link each finding to its rule docs via a **📚 Learn more** link, so reviewers can self-serve the reasoning without leaving the report.

### Failing CI on a threshold

`analyze` supports `--fail-on` (like `security`) so a pipeline fails on a chosen severity floor:

```bash
php artisan codeguardian:analyze --fail-on=high --plain    # non-zero if any high+ finding
```

Combine with suppression and baseline mode for precise gating — e.g. fail only on **new** high-severity findings:

```bash
php artisan codeguardian:analyze --against-baseline --new-only --fail-on=high --plain
```

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

## FAQ

**Does it need an API key?**
No. The default `static` engine runs fully offline with zero cost. An API key only unlocks the optional `hybrid`/`ai` modes for natural-language, intent-aware review.

**Will it modify my code during analysis?**
No. `analyze`, `security`, and `performance` are strictly read-only. Only `refactor` writes — and it runs a test-first safety net with automatic rollback (`--safe`).

**The live UI looks garbled in my CI logs.**
CI isn't a TTY, so the tool auto-switches to plain logging. If a wrapper forces decoration, add `--plain`.

**How do I reduce noise?**
Use filters: `--min-severity=high`, `--confidence=high`, or `--category=`. Each finding also carries a `confidence` field you can triage by.

**How do I run only part of the analysis?**
`--agents=security,performance` (analyze), or call the dedicated `codeguardian:security` / `codeguardian:performance` commands.

**Can I add my own rules?**
Yes — see *Extending the engine* above. A rule is a small method on an analyzer plus a unit test.

**Which Laravel/PHP versions are supported?**
PHP ^8.1 and Laravel 9 / 10 / 11.

---

## License

MIT
