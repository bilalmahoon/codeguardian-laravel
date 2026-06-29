<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Human documentation for each detection rule: what it means, why it matters,
 * and how to fix it — surfaced by `codeguardian:rules <id>` and linked from the
 * HTML report. Keyed by the finding "category" (the rule id).
 *
 * Kept intentionally concise and self-contained (no external fetch). Unknown
 * rules fall back to the RuleRegistry group with a generic message.
 */
final class RuleDocs
{
    /** @var array<string,array{title:string,why:string,fix:string,refs?:array<int,string>}> */
    public const DOCS = [
        // ── Architecture ────────────────────────────────────────────────────
        'fat_model' => [
            'title' => 'Fat model (business logic in Eloquent model)',
            'why'   => 'Models that hold business/query logic violate SRP, are hard to test in isolation, and become change magnets.',
            'fix'   => 'Move business logic into dedicated Service/Action classes; keep models for relationships, casts, and scopes.',
            'refs'  => ['https://laravel.com/docs/eloquent'],
        ],
        'fat_controller' => [
            'title' => 'Fat controller (logic in controller)',
            'why'   => 'Controllers that orchestrate business rules and queries are untestable without HTTP and mix concerns.',
            'fix'   => 'Extract logic into Services/Actions injected via the constructor; controllers should only coordinate request → service → response.',
        ],
        'service_layer' => [
            'title' => 'Missing service layer',
            'why'   => 'Direct DB/business logic in controllers couples HTTP to persistence and blocks reuse and unit testing.',
            'fix'   => 'Introduce a Service class for the use-case and depend on it from the controller.',
        ],
        'dependency_injection' => [
            'title' => 'Manual instantiation instead of DI',
            'why'   => 'new-ing dependencies hides collaborators, prevents mocking, and bypasses the container.',
            'fix'   => 'Type-hint dependencies in the constructor and let Laravel resolve them.',
        ],
        'config_misuse' => [
            'title' => 'env() used outside config',
            'why'   => 'env() returns null once config is cached (php artisan config:cache), causing subtle production bugs.',
            'fix'   => 'Read env() only inside config/*.php and reference config(\'...\') everywhere else.',
            'refs'  => ['https://laravel.com/docs/configuration#configuration-caching'],
        ],
        'solid' => [
            'title' => 'SOLID principle violation',
            'why'   => 'Breaking SRP/OCP/LSP/ISP/DIP makes code rigid, fragile, and hard to extend safely.',
            'fix'   => 'Refactor toward single responsibilities and depend on abstractions, not concretions.',
        ],

        // ── Security ────────────────────────────────────────────────────────
        'sql_injection' => [
            'title' => 'SQL injection',
            'why'   => 'Unparameterised user input in queries lets attackers read/modify the database.',
            'fix'   => 'Use query bindings / Eloquent; never concatenate request input into raw SQL.',
            'refs'  => ['https://owasp.org/Top10/A03_2021-Injection/', 'https://cwe.mitre.org/data/definitions/89.html'],
        ],
        'secret_exposure' => [
            'title' => 'Hardcoded secret / credential',
            'why'   => 'Secrets in source leak through VCS history and bundles, enabling account/system compromise.',
            'fix'   => 'Move secrets to .env / a secrets manager and reference via config().',
            'refs'  => ['https://cwe.mitre.org/data/definitions/798.html'],
        ],
        'authorization' => [
            'title' => 'Missing authorization check',
            'why'   => 'Endpoints without authorization allow broken-access-control (IDOR/privilege escalation).',
            'fix'   => 'Enforce Policies/Gates or middleware; verify ownership before acting on a resource.',
            'refs'  => ['https://owasp.org/Top10/A01_2021-Broken_Access_Control/'],
        ],
        'mass_assignment' => [
            'title' => 'Mass assignment',
            'why'   => 'Unguarded create/update with request()->all() lets attackers set unexpected columns (e.g. is_admin).',
            'fix'   => 'Use $fillable / validated() and never pass raw request input to fill()/create().',
            'refs'  => ['https://cwe.mitre.org/data/definitions/915.html'],
        ],
        'xss' => [
            'title' => 'Cross-site scripting (XSS)',
            'why'   => 'Unescaped output lets attackers inject scripts that run in victims\' browsers.',
            'fix'   => 'Render with {{ }} (auto-escaped); avoid {!! !!} for user data; sanitise HTML you must allow.',
            'refs'  => ['https://owasp.org/Top10/A03_2021-Injection/', 'https://cwe.mitre.org/data/definitions/79.html'],
        ],
        'debug_code' => [
            'title' => 'Debug code left in source',
            'why'   => 'dd()/dump()/var_dump leak data and can break responses in production.',
            'fix'   => 'Remove debug calls; use proper logging (Log::debug) gated by environment.',
        ],
        'insecure_upload' => [
            'title' => 'Insecure file upload',
            'why'   => 'Unvalidated uploads enable web-shell upload, path traversal, and storage abuse.',
            'fix'   => 'Validate mime/size/extension, store outside web root, and generate random names.',
            'refs'  => ['https://cwe.mitre.org/data/definitions/434.html'],
        ],
        'command_injection' => [
            'title' => 'OS command injection',
            'why'   => 'User input passed to shell calls lets attackers run arbitrary commands.',
            'fix'   => 'Avoid shell calls with user input; use escapeshellarg() or native APIs.',
            'refs'  => ['https://cwe.mitre.org/data/definitions/78.html'],
        ],
        'code_injection' => [
            'title' => 'Code injection (eval/dynamic)',
            'why'   => 'eval()/dynamic execution of input allows full remote code execution.',
            'fix'   => 'Remove eval(); use explicit whitelisted dispatch instead of dynamic execution.',
            'refs'  => ['https://cwe.mitre.org/data/definitions/94.html'],
        ],
        'insecure_deserialization' => [
            'title' => 'Insecure deserialization',
            'why'   => 'unserialize() on untrusted data can trigger object-injection / RCE.',
            'fix'   => 'Use json_decode for untrusted data, or unserialize with allowed_classes => false.',
            'refs'  => ['https://cwe.mitre.org/data/definitions/502.html'],
        ],
        'weak_cryptography' => [
            'title' => 'Weak cryptography',
            'why'   => 'md5/sha1/DES are broken for security use and enable collision/brute-force attacks.',
            'fix'   => 'Use password_hash()/Hash::make for passwords and SHA-256+ / libsodium for hashing.',
            'refs'  => ['https://cwe.mitre.org/data/definitions/327.html'],
        ],
        'insecure_randomness' => [
            'title' => 'Insecure randomness',
            'why'   => 'rand()/mt_rand()/uniqid() are predictable — unsafe for tokens/secrets.',
            'fix'   => 'Use random_bytes()/random_int() or Str::random() for security-sensitive values.',
            'refs'  => ['https://cwe.mitre.org/data/definitions/338.html'],
        ],
        'path_traversal' => [
            'title' => 'Path traversal',
            'why'   => 'Unsanitised paths let attackers read/write files outside the intended directory.',
            'fix'   => 'Validate against a whitelist and use basename()/realpath() to constrain the path.',
            'refs'  => ['https://cwe.mitre.org/data/definitions/22.html'],
        ],
        'ssrf' => [
            'title' => 'Server-side request forgery (SSRF)',
            'why'   => 'Fetching attacker-controlled URLs can reach internal services and cloud metadata.',
            'fix'   => 'Whitelist hosts/schemes, resolve and block private IP ranges before requesting.',
            'refs'  => ['https://owasp.org/Top10/A10_2021-Server-Side_Request_Forgery_%28SSRF%29/'],
        ],
        'open_redirect' => [
            'title' => 'Open redirect',
            'why'   => 'Redirecting to attacker-controlled URLs aids phishing and token theft.',
            'fix'   => 'Redirect only to relative paths or a validated allow-list of hosts.',
            'refs'  => ['https://cwe.mitre.org/data/definitions/601.html'],
        ],
        'csrf' => [
            'title' => 'Missing CSRF protection',
            'why'   => 'State-changing routes without CSRF tokens allow forged requests from other sites.',
            'fix'   => 'Keep the VerifyCsrfToken middleware and @csrf in forms; only except true stateless APIs.',
            'refs'  => ['https://owasp.org/www-community/attacks/csrf'],
        ],
        'security_misconfiguration' => [
            'title' => 'Security misconfiguration',
            'why'   => 'Debug mode, permissive CORS, or exposed config widens the attack surface.',
            'fix'   => 'Disable APP_DEBUG in production, lock down CORS, and review exposed settings.',
            'refs'  => ['https://owasp.org/Top10/A05_2021-Security_Misconfiguration/'],
        ],
        'file_inclusion' => [
            'title' => 'File inclusion',
            'why'   => 'include/require with user input enables LFI/RFI and code execution.',
            'fix'   => 'Never build include paths from input; map to a fixed whitelist of files.',
            'refs'  => ['https://cwe.mitre.org/data/definitions/98.html'],
        ],
        'tls_verification' => [
            'title' => 'Disabled TLS verification',
            'why'   => 'withoutVerifying()/verify=false exposes traffic to man-in-the-middle attacks.',
            'fix'   => 'Enable certificate verification; fix the root CA issue instead of disabling checks.',
            'refs'  => ['https://cwe.mitre.org/data/definitions/295.html'],
        ],

        // ── Performance ─────────────────────────────────────────────────────
        'n_plus_one' => [
            'title' => 'N+1 query',
            'why'   => 'Accessing a relationship in a loop fires one query per row, crushing performance at scale.',
            'fix'   => 'Eager load with ->with(\'relation\') (or load()) before iterating.',
            'refs'  => ['https://laravel.com/docs/eloquent-relationships#eager-loading'],
        ],
        'eager_loading' => [
            'title' => 'Missing eager loading',
            'why'   => 'Lazy relationship access multiplies queries and latency.',
            'fix'   => 'Add ->with() for the relationships you will access.',
        ],
        'select_all' => [
            'title' => 'SELECT * over-fetch',
            'why'   => 'Selecting all columns wastes IO/memory and can break with schema changes.',
            'fix'   => 'Select only the columns you need: ->select([\'id\', \'name\']).',
        ],
        'missing_cache' => [
            'title' => 'Missing cache for expensive work',
            'why'   => 'Recomputing expensive queries/HTTP calls on every request wastes resources.',
            'fix'   => 'Cache results with Cache::remember() and a sensible TTL/invalidation.',
            'refs'  => ['https://laravel.com/docs/cache'],
        ],
        'inefficient_count' => [
            'title' => 'Inefficient count',
            'why'   => 'count() on a loaded collection pulls all rows just to count them.',
            'fix'   => 'Use ->count() on the query builder so the DB counts without hydrating models.',
        ],
        'missing_index' => [
            'title' => 'Likely missing index',
            'why'   => 'Filtering/sorting on unindexed columns forces full table scans.',
            'fix'   => 'Add a migration index on the frequently-queried column(s).',
        ],
        'memory_usage' => [
            'title' => 'High memory usage',
            'why'   => 'Loading large datasets into memory at once risks exhaustion and slow GC.',
            'fix'   => 'Use chunk()/cursor()/lazy() to stream rows instead of all().',
            'refs'  => ['https://laravel.com/docs/eloquent#chunking-results'],
        ],
        'query_in_loop' => [
            'title' => 'Query inside a loop',
            'why'   => 'Issuing queries per iteration multiplies round-trips (a form of N+1).',
            'fix'   => 'Hoist the query out of the loop or batch with whereIn()/eager loading.',
        ],
        'over_fetching' => [
            'title' => 'Over-fetching data',
            'why'   => 'Fetching more rows/columns than used wastes memory and bandwidth.',
            'fix'   => 'Constrain the query (where/limit/select) to exactly what is needed.',
        ],
        'nested_loops' => [
            'title' => 'Nested loops over data',
            'why'   => 'O(n²) processing over collections degrades quickly with data growth.',
            'fix'   => 'Use keyed lookups (keyBy/maps) or push the join into the database.',
        ],

        // ── Tech debt ───────────────────────────────────────────────────────
        'large_class' => [
            'title' => 'Large class',
            'why'   => 'Big classes usually have many responsibilities, raising change risk and test burden.',
            'fix'   => 'Split into focused classes; group related behaviour into Services/Traits.',
        ],
        'high_complexity' => [
            'title' => 'High cyclomatic complexity',
            'why'   => 'Many branches mean many test cases and a high chance of hidden bugs.',
            'fix'   => 'Extract methods, use early returns, and replace conditionals with polymorphism where apt.',
        ],
        'duplication' => [
            'title' => 'Duplicated logic',
            'why'   => 'Copy-pasted code drifts and multiplies the cost of every change/bug fix.',
            'fix'   => 'Extract the shared logic into a single reusable method/class.',
        ],
        'todo_debt' => [
            'title' => 'TODO/FIXME debt',
            'why'   => 'Accumulated TODO/FIXME markers signal unfinished work and latent risk.',
            'fix'   => 'Triage into tickets and resolve or remove stale markers.',
        ],
        'dead_code' => [
            'title' => 'Dead / commented-out code',
            'why'   => 'Unused code confuses readers; version control already preserves history.',
            'fix'   => 'Delete it; retrieve from git history if ever needed.',
        ],
        'missing_types' => [
            'title' => 'Missing type declarations',
            'why'   => 'Missing param/return types weaken IDE help and let type bugs slip through.',
            'fix'   => 'Add PHP 8.1 parameter and return types to all methods.',
        ],
        'magic_numbers' => [
            'title' => 'Magic numbers',
            'why'   => 'Unexplained literals hide intent and are easy to get wrong when changed.',
            'fix'   => 'Promote to named constants/config with a meaningful name.',
        ],
        'deep_nesting' => [
            'title' => 'Deep nesting',
            'why'   => 'Deeply nested conditionals are hard to read and reason about.',
            'fix'   => 'Use guard clauses / early returns and extract inner blocks into methods.',
        ],
        'long_parameter_list' => [
            'title' => 'Long parameter list',
            'why'   => 'Many parameters are hard to call correctly and often signal a missing object.',
            'fix'   => 'Introduce a DTO/parameter object or split the method.',
        ],
        'boolean_flag' => [
            'title' => 'Boolean flag parameter',
            'why'   => 'Boolean arguments hide two behaviours behind one method and read poorly at call sites.',
            'fix'   => 'Split into two well-named methods or pass an enum/strategy.',
        ],
        'swallowed_exception' => [
            'title' => 'Swallowed exception',
            'why'   => 'Empty catch blocks hide failures and make debugging production issues painful.',
            'fix'   => 'Log/handle/rethrow; never silently discard exceptions.',
        ],
        'god_class' => [
            'title' => 'God class',
            'why'   => 'A class that does everything is a maintenance and testing bottleneck.',
            'fix'   => 'Decompose by responsibility into cohesive collaborating classes.',
        ],
    ];

    /**
     * Full doc for a rule (or a sensible fallback for unknown ids).
     *
     * @return array{id:string,group:string,title:string,why:string,fix:string,refs:array<int,string>}
     */
    public static function for(string $ruleId): array
    {
        $id    = strtolower(trim($ruleId));
        $group = RuleRegistry::CATALOG[$id] ?? 'general';
        $doc   = self::DOCS[$id] ?? null;

        if ($doc === null) {
            return [
                'id'    => $id,
                'group' => $group,
                'title' => ucwords(str_replace(['_', '-'], ' ', $id)),
                'why'   => 'No extended documentation for this rule yet.',
                'fix'   => 'Review the finding\'s description and recommendation.',
                'refs'  => [],
            ];
        }

        return [
            'id'    => $id,
            'group' => $group,
            'title' => $doc['title'],
            'why'   => $doc['why'],
            'fix'   => $doc['fix'],
            'refs'  => $doc['refs'] ?? [],
        ];
    }

    public static function has(string $ruleId): bool
    {
        return isset(self::DOCS[strtolower(trim($ruleId))]);
    }
}
