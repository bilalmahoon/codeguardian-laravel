<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Analysis Engine Mode
    |--------------------------------------------------------------------------
    | "static" — built-in rule-based engine (default).
    |             No API key needed. Works offline. Free. Fast.
    |             Detects: N+1, SQL injection, fat controllers, mass assignment,
    |             hardcoded secrets, missing auth, cyclomatic complexity, etc.
    |
    | "ai"     — Uses an external AI provider (OpenAI / Claude / Gemini).
    |             Requires API key. Richer natural language explanations.
    |
    | "hybrid" — Runs static engine first, then AI enriches findings.
    */

    'mode' => env('CODEGUARDIAN_MODE', 'static'),

    /*
    |--------------------------------------------------------------------------
    | AI Provider (only used when mode = "ai" or "hybrid")
    |--------------------------------------------------------------------------
    | Which AI provider to use for analysis.
    | Supported: "openai", "claude", "gemini"
    */

    'provider' => env('CODEGUARDIAN_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | AI Provider API Keys
    |--------------------------------------------------------------------------
    */

    'openai' => [
        'key'         => env('CODEGUARDIAN_OPENAI_KEY', env('OPENAI_API_KEY')),
        'model'       => env('CODEGUARDIAN_OPENAI_MODEL', 'gpt-4o'),
        'max_tokens'  => env('CODEGUARDIAN_MAX_TOKENS', 8192),
        // Higher output budget for refactoring (full-file rewrites + generated
        // files are large; too low a limit truncates the JSON and the rewrite
        // is silently lost). Tune down only if your model rejects the value.
        'refactor_max_tokens' => env('CODEGUARDIAN_REFACTOR_MAX_TOKENS', 16000),
        'temperature' => 0.1,
    ],

    'claude' => [
        'key'         => env('CODEGUARDIAN_CLAUDE_KEY', env('ANTHROPIC_API_KEY')),
        // Model to use for Claude API calls.
        // If you get a 404 "model not found" error, set this in your .env to the
        // latest model available on your account. Check: https://docs.anthropic.com/en/docs/models-overview
        // Examples: claude-opus-4-5 | claude-3-7-sonnet-20250219 | claude-3-5-sonnet-20241022
        'model'       => env('CODEGUARDIAN_CLAUDE_MODEL', 'claude-opus-4-5'),
        'max_tokens'  => env('CODEGUARDIAN_MAX_TOKENS', 8192),
        // Refactoring produces large full-file rewrites. Claude 3.7 / opus-4
        // support 16k–64k output tokens; 16000 is a safe default that prevents
        // mid-JSON truncation. Raise via CODEGUARDIAN_REFACTOR_MAX_TOKENS.
        'refactor_max_tokens' => env('CODEGUARDIAN_REFACTOR_MAX_TOKENS', 16000),
        'temperature' => 0.1,
    ],

    'gemini' => [
        'key'         => env('CODEGUARDIAN_GEMINI_KEY', env('GEMINI_API_KEY')),
        'model'       => env('CODEGUARDIAN_GEMINI_MODEL', 'gemini-1.5-flash'),
        'max_tokens'  => env('CODEGUARDIAN_MAX_TOKENS', 8192),
        'refactor_max_tokens' => env('CODEGUARDIAN_REFACTOR_MAX_TOKENS', 16000),
        'temperature' => 0.1,
        // Available models:
        // gemini-1.5-flash         ← default, free tier, fast
        // gemini-2.0-flash         ← newer free tier
        // gemini-2.5-flash-preview ← latest preview
        // gemini-1.5-pro           ← paid, higher quality
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis Settings
    |--------------------------------------------------------------------------
    */

    'analysis' => [
        // Maximum file size to include in context (bytes)
        'max_file_size' => 100_000,

        // Run the built-in analyzers in parallel OS processes (requires the
        // pcntl extension). Speeds up large codebases; falls back to sequential
        // automatically when unavailable. Override per-run with --parallel /
        // --no-parallel. The live progress UI always runs sequentially.
        'parallel' => env('CODEGUARDIAN_PARALLEL', false),

        // Warn (and ask to confirm) if scan finds more files than this.
        // Set to 0 to disable the warning.
        'max_files_per_scan' => 2000,

        // Directories to skip during scanning
        'skip_dirs' => [
            'vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache',
            '.dart_tool', 'build', '.pub-cache',
        ],

        // Whether to run tests after generating them
        'auto_validate_tests' => false,

        // Max tokens to send per AI request (truncates code if exceeded)
        'max_context_tokens' => 60_000,

        // Timeout in seconds for running tests
        'test_timeout' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Module Configuration
    |--------------------------------------------------------------------------
    | If your project uses a non-standard module structure, add the module
    | root directories here (relative to project root).
    |
    | Default auto-detected paths:
    |   Modules/        (nwidart/laravel-modules)
    |   app/Modules/    (custom)
    |   app/Domain/     (DDD)
    */

    'modules' => [
        // Add extra module root paths if not auto-detected
        'paths' => [
            // 'app/Components',
            // 'src/Modules',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rule Configuration
    |--------------------------------------------------------------------------
    | Enable/disable individual detection rules and override their severity,
    | without touching engine code. Rules are keyed by their finding "category"
    | — run `php artisan codeguardian:rules` to see every available rule id.
    |
    | Accepted values per rule:
    |   false                                  → disable the rule entirely
    |   'critical'|'high'|'medium'|'low'       → override severity
    |   ['enabled' => bool, 'severity' => '..']→ both
    */

    'rules' => [
        // 'magic_numbers' => false,
        // 'missing_types' => 'low',
        // 'n_plus_one'    => 'critical',
        // 'todo_debt'     => ['enabled' => true, 'severity' => 'low'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tuning Preset
    |--------------------------------------------------------------------------
    | A quick way to set the overall strictness without configuring each rule:
    |   'strict'   → maintainability debt treated as first-class (louder)
    |   'balanced' → engine defaults (recommended)
    |   'lenient'  → mute the noisiest low-value rules
    |
    | Anything you set in 'rules' above always overrides the preset.
    | Override per-run with `--preset=`.
    */

    'preset' => env('CODEGUARDIAN_PRESET', 'balanced'),

    /*
    |--------------------------------------------------------------------------
    | Custom Rules
    |--------------------------------------------------------------------------
    | Define project-specific detection rules without writing PHP. Each rule
    | scans file contents with a regular expression and emits a finding.
    |
    |   'id'       (required) finding category / rule id
    |   'title'    (required) short headline
    |   'pattern'  (required) regex WITHOUT delimiters (case-insensitive by default)
    |   'severity' critical|high|medium|low        (default: medium)
    |   'message'  description shown on the finding  (default: title)
    |   'fix'      recommendation text               (optional)
    |   'paths'    only scan files whose path contains one of these (optional)
    |   'exclude'  skip files whose path contains one of these       (optional)
    */

    'custom_rules' => [
        // [
        //     'id'       => 'no_env_in_code',
        //     'title'    => 'env() used outside config',
        //     'pattern'  => '(?<!config\\()\\benv\\(',
        //     'severity' => 'medium',
        //     'message'  => 'Calling env() outside config returns null when config is cached.',
        //     'fix'      => 'Move to config/*.php and read via config().',
        //     'exclude'  => ['config/'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignore / Suppression
    |--------------------------------------------------------------------------
    | Control noise without editing rules. Suppressed findings are removed
    | before scoring, reporting, and CI exit codes.
    |
    | You can also suppress inline in source with comments:
    |   // codeguardian-ignore                 → any finding on this line
    |   // codeguardian-ignore sql_injection   → only that category on this line
    |   // codeguardian-ignore-file            → every finding in the file
    | (a marker on the line directly above a statement counts too.)
    */

    'ignore' => [
        // Finding categories to always suppress, e.g. ['magic_numbers', 'commented_code']
        'categories' => [],

        // Suppress findings from these path substrings or globs,
        // e.g. ['database/migrations/', 'tests/*', 'app/Legacy/']
        'paths' => [],

        // Inline suppression comment marker.
        'inline_marker' => 'codeguardian-ignore',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Response Cache
    |--------------------------------------------------------------------------
    | Re-running analysis/refactor over unchanged code produces byte-identical
    | prompts. Caching responses on disk serves those repeats for $0. The model
    | is part of the cache key, so switching models never returns a stale answer.
    */

    'cache' => [
        'ai_enabled' => env('CODEGUARDIAN_CACHE_AI', true),
        // Seconds an entry stays valid (0 = never expires; clear manually).
        'ttl'        => env('CODEGUARDIAN_CACHE_TTL', 0),
        'dir'        => storage_path('codeguardian/cache/ai'),

        // Static-analysis result cache (content-addressed). When on, an
        // unchanged file tree returns the previous result instantly. Safe by
        // design (the key is a hash of file contents). Enable per-run with
        // --cache, or globally here. Bypass with --no-cache.
        'static_enabled' => env('CODEGUARDIAN_CACHE_STATIC', false),
        'static_dir'     => storage_path('codeguardian/cache/static'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    | Webhook endpoint for `codeguardian:notify` (Slack / Microsoft Teams / a
    | generic JSON receiver). Override the URL per-run with --url and the shape
    | with --format=slack|teams|generic.
    */

    'notifications' => [
        'webhook' => env('CODEGUARDIAN_WEBHOOK_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality Gates / Budgets
    |--------------------------------------------------------------------------
    | When set, `codeguardian:analyze` fails (non-zero exit) if any budget is
    | breached — turning the tool into a real CI merge gate. Leave empty to
    | disable. max_* are upper bounds; min_* are lower bounds.
    |
    |   'max_critical' => 0,    // no critical findings allowed
    |   'max_high'     => 5,
    |   'max_total'    => 200,
    |   'max_risk'     => 60,   // risk score 0–100
    |   'min_score'    => 70,   // overall score 0–100
    |   'min_quality'  => 70,   // composite quality 0–100
    */

    'gates' => [
        // 'max_critical' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Settings
    |--------------------------------------------------------------------------
    */

    'output' => [
        // Directory where reports are saved (relative to storage_path())
        'report_dir' => 'codeguardian/reports',

        // Directory where generated tests are saved (relative to base_path())
        'tests_dir' => 'tests/CodeGuardian',

        // Default report format: json | html | md | sarif | junit | both | all
        'format' => 'both',

        // Append-only run history (powers `codeguardian:trend`). Absolute path.
        'history_file' => storage_path('codeguardian/history.jsonl'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Dashboard
    |--------------------------------------------------------------------------
    | A browser UI to run scans/refactors, watch live progress, and browse the
    | full history of past runs with their results — instead of the terminal.
    |
    | Visit: <your-app-url>/codeguardian
    */

    'dashboard' => [
        // Master switch. Disable to remove the routes entirely.
        'enabled' => env('CODEGUARDIAN_DASHBOARD', true),

        // URL prefix the dashboard is mounted at.
        'path' => env('CODEGUARDIAN_DASHBOARD_PATH', 'codeguardian'),

        // Middleware applied to every dashboard route. 'web' gives sessions/CSRF.
        // The package also applies its own authorization gate (see below).
        'middleware' => ['web'],

        // Authorization. By default the dashboard is only reachable in the
        // 'local' environment. To open it elsewhere, define a Gate named
        // 'viewCodeGuardian' in your AuthServiceProvider, OR set
        // 'restrict_to_local' => false (NOT recommended for production).
        'restrict_to_local' => env('CODEGUARDIAN_DASHBOARD_LOCAL_ONLY', true),

        // Where run history + live logs are stored (relative to storage_path()).
        'runs_dir' => 'codeguardian/runs',

        // Absolute path to the PHP binary used to launch background runs.
        // Auto-detected (PHP_BINARY) when null.
        'php_binary' => env('CODEGUARDIAN_PHP_BINARY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Foolproof Refactoring (test-first safety net)
    |--------------------------------------------------------------------------
    | When "safe" mode is on, each file is verified by generated + existing
    | tests AFTER refactoring; if the refactor introduces a NEW test failure,
    | that file is automatically rolled back to its original content. This is
    | what the dashboard uses so refactored code never ships a regression.
    */

    'refactor' => [
        'safe_mode'             => env('CODEGUARDIAN_SAFE_MODE', true),
        // Auto-rollback a file if refactoring introduces a new test failure.
        'auto_rollback_on_fail' => env('CODEGUARDIAN_AUTO_ROLLBACK', true),
    ],

];
