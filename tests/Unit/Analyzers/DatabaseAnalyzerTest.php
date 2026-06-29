<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Analyzers;

use CodeGuardian\Laravel\Analyzers\DatabaseAnalyzer;
use PHPUnit\Framework\TestCase;

class DatabaseAnalyzerTest extends TestCase
{
    private DatabaseAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new DatabaseAnalyzer();
    }

    private function categories(array $files): array
    {
        $result = $this->analyzer->analyze($files);
        return array_column($result['findings'], 'category');
    }

    public function test_flags_migration_without_down(): void
    {
        $files = ['database/migrations/2024_01_01_create_orders.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up() { Schema::create('orders', function ($table) { $table->id(); }); }
};
PHP];

        $this->assertContains('irreversible_migration', $this->categories($files));
    }

    public function test_does_not_flag_migration_with_down(): void
    {
        $files = ['database/migrations/2024_01_01_create_orders.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up() { Schema::create('orders', function ($table) { $table->id(); }); }
    public function down() { Schema::dropIfExists('orders'); }
};
PHP];

        $this->assertNotContains('irreversible_migration', $this->categories($files));
    }

    public function test_flags_unindexed_foreign_key(): void
    {
        $files = ['database/migrations/2024_create.php' => <<<'PHP'
<?php
return new class extends Migration {
    public function up() {
        Schema::create('posts', function ($table) {
            $table->unsignedBigInteger('user_id');
        });
    }
    public function down() {}
};
PHP];

        $this->assertContains('unindexed_foreign_key', $this->categories($files));
    }

    public function test_does_not_flag_constrained_foreign_id(): void
    {
        $files = ['database/migrations/2024_create.php' => <<<'PHP'
<?php
return new class extends Migration {
    public function up() {
        Schema::create('posts', function ($table) {
            $table->foreignId('user_id')->constrained();
        });
    }
    public function down() {}
};
PHP];

        $this->assertNotContains('unindexed_foreign_key', $this->categories($files));
    }

    public function test_flags_money_as_float(): void
    {
        $files = ['database/migrations/2024_create.php' => <<<'PHP'
<?php
return new class extends Migration {
    public function up() {
        Schema::create('orders', function ($table) {
            $table->float('total_price');
        });
    }
    public function down() {}
};
PHP];

        $this->assertContains('money_as_float', $this->categories($files));
    }

    public function test_flags_enum_column(): void
    {
        $files = ['database/migrations/2024_create.php' => <<<'PHP'
<?php
return new class extends Migration {
    public function up() {
        Schema::create('t', function ($table) {
            $table->enum('status', ['a', 'b']);
        });
    }
    public function down() {}
};
PHP];

        $this->assertContains('enum_column', $this->categories($files));
    }

    public function test_flags_unguarded_model(): void
    {
        $files = ['app/Models/User.php' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class User extends Model {
    protected $guarded = [];
}
PHP];

        $this->assertContains('unguarded_model', $this->categories($files));
    }

    public function test_clean_model_with_fillable_is_quiet(): void
    {
        $files = ['app/Models/Post.php' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Post extends Model {
    protected $fillable = ['title', 'body'];
}
PHP];

        $cats = $this->categories($files);
        $this->assertNotContains('unguarded_model', $cats);
        $this->assertNotContains('no_mass_assignment_policy', $cats);
    }

    public function test_ignores_non_php_files(): void
    {
        $result = $this->analyzer->analyze(['readme.md' => 'enum float']);
        $this->assertSame([], $result['findings']);
        $this->assertSame(100, $result['database_score']);
    }
}
