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
    | Output Settings
    |--------------------------------------------------------------------------
    */

    'output' => [
        // Directory where reports are saved (relative to storage_path())
        'report_dir' => 'codeguardian/reports',

        // Directory where generated tests are saved (relative to base_path())
        'tests_dir' => 'tests/CodeGuardian',

        // Default report format: json | html | both
        'format' => 'both',
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
