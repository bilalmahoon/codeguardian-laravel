<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Support\CodeScanner;
use CodeGuardian\Laravel\Support\DependencyGraph;
use CodeGuardian\Laravel\Support\ModuleDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Renders the module dependency graph and reports circular dependencies — the
 * architectural smell most likely to make a modular codebase un-deployable
 * module-by-module.
 */
class GraphCommand extends Command
{
    protected $signature = 'codeguardian:graph
                            {--path=     : Project root (default: base_path())}
                            {--format=text : Output: text | mermaid | dot}
                            {--output=   : Write the graph to this file instead of stdout}
                            {--fail-on-cycles : Exit non-zero if any circular dependency is found}';

    protected $description = 'Map module dependencies and detect circular dependencies';

    public function handle(CodeScanner $scanner): int
    {
        $path = $this->option('path') ?: base_path();

        $detector = new ModuleDetector($path);
        if (! $detector->isModular()) {
            $this->warn('  No module structure detected (Modules/, app/Modules/, app/Domain/).');
            $this->line('  The dependency graph is only meaningful for modular projects.');
            return self::SUCCESS;
        }

        $modules = $detector->listModules();
        $this->info('  📦 Modules: ' . implode(', ', $modules));

        $files = $scanner->scan($path, 'laravel');
        $graph  = DependencyGraph::build($files, $modules);
        $cycles = DependencyGraph::cycles($graph);

        $format = strtolower((string) ($this->option('format') ?: 'text'));
        $render = match ($format) {
            'mermaid' => DependencyGraph::toMermaid($graph, $cycles),
            'dot'     => DependencyGraph::toDot($graph),
            default   => $this->renderText($graph, $cycles),
        };

        if ($outFile = $this->option('output')) {
            File::ensureDirectoryExists(dirname($outFile));
            File::put($outFile, $render . "\n");
            $this->info("  📄 Graph written: {$outFile}");
        } else {
            $this->newLine();
            $this->line($render);
        }

        $this->newLine();
        if ($cycles === []) {
            $this->info('  ✅ No circular dependencies between modules.');
        } else {
            $this->error('  ❌ ' . count($cycles) . ' circular dependency chain(s):');
            foreach ($cycles as $cycle) {
                $this->line('     ' . implode(' → ', $cycle) . ' → ' . $cycle[0]);
            }
            if ($this->option('fail-on-cycles')) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,list<string>> $graph
     * @param list<list<string>>         $cycles
     */
    private function renderText(array $graph, array $cycles): string
    {
        $lines = ['Module dependencies:'];
        foreach ($graph as $module => $deps) {
            $lines[] = $deps === []
                ? "  {$module}  (no outbound dependencies)"
                : "  {$module} → " . implode(', ', $deps);
        }
        return implode("\n", $lines);
    }
}
