<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Analyzers;

use CodeGuardian\Laravel\Analyzers\SecurityAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Covers the extended OWASP/CWE-mapped security checks added in the
 * Principal-Engineer enhancement pass.
 */
class SecurityAnalyzerExtendedTest extends TestCase
{
    private SecurityAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new SecurityAnalyzer();
    }

    /** @return array<string,string> */
    private function scan(string $name, string $code): array
    {
        $result = $this->analyzer->analyze([$name => $code]);
        return $result['findings'];
    }

    private function categories(array $findings): array
    {
        return array_column($findings, 'category');
    }

    private function findingFor(array $findings, string $category): ?array
    {
        foreach ($findings as $f) {
            if (($f['category'] ?? null) === $category) {
                return $f;
            }
        }
        return null;
    }

    public function test_existing_sql_injection_carries_cwe_and_owasp(): void
    {
        $findings = $this->scan('C.php', <<<'PHP'
        <?php
        class C { public function r($id){ return DB::select("SELECT * FROM t WHERE id=$id"); } }
        PHP);

        $f = $this->findingFor($findings, 'sql_injection');
        $this->assertNotNull($f);
        $this->assertSame('CWE-89', $f['cwe']);
        $this->assertStringContainsString('A03', $f['owasp']);
        $this->assertSame('high', $f['confidence']);
    }

    public function test_detects_command_injection(): void
    {
        $findings = $this->scan('C.php', <<<'PHP'
        <?php
        class C { public function r($request){ exec("ping " . $request->input('host')); } }
        PHP);
        $this->assertContains('command_injection', $this->categories($findings));
    }

    public function test_detects_code_injection_eval(): void
    {
        $findings = $this->scan('C.php', "<?php class C { public function r(\$x){ eval(\$x); } }");
        $this->assertContains('code_injection', $this->categories($findings));
    }

    public function test_detects_insecure_deserialization(): void
    {
        $findings = $this->scan('C.php', <<<'PHP'
        <?php
        class C { public function r($request){ $d = unserialize($request->input('p')); } }
        PHP);
        $this->assertContains('insecure_deserialization', $this->categories($findings));
    }

    public function test_detects_weak_crypto_for_password(): void
    {
        $findings = $this->scan('C.php', "<?php class C { public function r(\$password){ return md5(\$password); } }");
        $f = $this->findingFor($findings, 'weak_cryptography');
        $this->assertNotNull($f);
        $this->assertSame('CWE-327', $f['cwe']);
    }

    public function test_plain_md5_without_secret_context_is_not_flagged(): void
    {
        // md5 of a cache key is fine — should NOT be a weak-crypto finding.
        $findings = $this->scan('C.php', "<?php class C { public function key(\$url){ return md5(\$url); } }");
        $this->assertNotContains('weak_cryptography', $this->categories($findings));
    }

    public function test_detects_insecure_randomness_for_token(): void
    {
        $findings = $this->scan('C.php', "<?php class C { public function makeToken(){ \$token = mt_rand(); return \$token; } }");
        $this->assertContains('insecure_randomness', $this->categories($findings));
    }

    public function test_detects_path_traversal(): void
    {
        $findings = $this->scan('C.php', <<<'PHP'
        <?php
        class C { public function r($request){ return file_get_contents($request->input('path')); } }
        PHP);
        $this->assertContains('path_traversal', $this->categories($findings));
    }

    public function test_detects_ssrf(): void
    {
        $findings = $this->scan('C.php', <<<'PHP'
        <?php
        class C { public function r($request){ return Http::get($request->input('url')); } }
        PHP);
        $this->assertContains('ssrf', $this->categories($findings));
    }

    public function test_detects_open_redirect(): void
    {
        $findings = $this->scan('C.php', <<<'PHP'
        <?php
        class C { public function r($request){ return redirect($request->input('next')); } }
        PHP);
        $this->assertContains('open_redirect', $this->categories($findings));
    }

    public function test_detects_unguarded_mass_assignment_on_model(): void
    {
        $findings = $this->scan('app/Models/Post.php', <<<'PHP'
        <?php
        namespace App\Models;
        use Illuminate\Database\Eloquent\Model;
        class Post extends Model {
            protected $guarded = [];
        }
        PHP);
        $this->assertContains('mass_assignment', $this->categories($findings));
    }

    public function test_detects_disabled_csrf_wildcard(): void
    {
        $findings = $this->scan('app/Http/Middleware/VerifyCsrfToken.php', <<<'PHP'
        <?php
        class VerifyCsrfToken {
            protected $except = ['*'];
        }
        PHP);
        $this->assertContains('csrf', $this->categories($findings));
    }

    public function test_detects_debug_mode_enabled(): void
    {
        $findings = $this->scan('config/app.php', "<?php return ['debug' => true];");
        $this->assertContains('security_misconfiguration', $this->categories($findings));
    }

    public function test_detects_disabled_tls_verification(): void
    {
        $findings = $this->scan('C.php', <<<'PHP'
        <?php
        class C { public function r($url){ return Http::withoutVerifying()->get($url); } }
        PHP);
        $this->assertContains('tls_verification', $this->categories($findings));
    }

    public function test_clean_service_triggers_no_extended_findings(): void
    {
        $findings = $this->scan('app/Services/CleanService.php', <<<'PHP'
        <?php
        namespace App\Services;
        class CleanService {
            public function __construct(private readonly UserRepository $users) {}
            public function getUser(int $id): ?User { return $this->users->find($id); }
        }
        PHP);
        $this->assertSame([], $findings);
    }
}
