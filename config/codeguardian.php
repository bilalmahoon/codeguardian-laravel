<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider
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
        'temperature' => 0.1,
    ],

    'claude' => [
        'key'         => env('CODEGUARDIAN_CLAUDE_KEY', env('ANTHROPIC_API_KEY')),
        'model'       => env('CODEGUARDIAN_CLAUDE_MODEL', 'claude-3-5-sonnet-20241022'),
        'max_tokens'  => env('CODEGUARDIAN_MAX_TOKENS', 8192),
        'temperature' => 0.1,
    ],

    'gemini' => [
        'key'         => env('CODEGUARDIAN_GEMINI_KEY', env('GEMINI_API_KEY')),
        'model'       => env('CODEGUARDIAN_GEMINI_MODEL', 'gemini-1.5-pro'),
        'max_tokens'  => env('CODEGUARDIAN_MAX_TOKENS', 8192),
        'temperature' => 0.1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis Settings
    |--------------------------------------------------------------------------
    */

    'analysis' => [
        // Maximum file size to include in context (bytes)
        'max_file_size' => 100_000,

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

];
