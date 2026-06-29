<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use Illuminate\Support\Facades\File;

class ReportFormatter
{
    /**
     * Save report in the requested format(s).
     *
     * @return array<string>  List of saved file paths
     */
    public function save(array $results, string $outputDir, string $format = 'both'): array
    {
        File::ensureDirectoryExists($outputDir);

        $timestamp = now()->format('Y-m-d_H-i-s');
        $project   = preg_replace('/[^a-z0-9\-]/i', '-', $results['project_name'] ?? 'project');
        $base      = "{$outputDir}/scan-{$project}-{$timestamp}";

        $saved = [];

        if (in_array($format, ['json', 'both'])) {
            $jsonPath = "{$base}.json";
            File::put($jsonPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $saved[] = $jsonPath;
        }

        if (in_array($format, ['html', 'both'])) {
            $htmlPath = "{$base}.html";
            File::put($htmlPath, $this->renderHtml($results));
            $saved[] = $htmlPath;
        }

        return $saved;
    }

    private function renderHtml(array $results): string
    {
        $project  = e($results['project_name'] ?? 'Project');
        $type     = e($results['project_type'] ?? 'laravel');
        $scanned  = e($results['scanned_at'] ?? now()->toISOString());
        $overall  = $results['overall_score'] ?? 'N/A';
        $scores   = $results['scores'] ?? [];

        // Normalise summary — provide defaults for every key the template uses
        // so a missing key never crashes report generation.
        $summary = array_merge([
            'total_files'   => $results['files_scanned'] ?? 0,
            'total_lines'   => $results['total_lines']   ?? 0,
            'total_issues'  => 0,
            'critical'      => 0,
            'high'          => 0,
            'medium'        => 0,
            'low'           => 0,
            'top_findings'  => [],
            'hotspot_files' => [],
        ], $results['summary'] ?? []);

        $scoreColor = is_int($overall)
            ? ($overall >= 80 ? '#22c55e' : ($overall >= 60 ? '#f59e0b' : '#ef4444'))
            : '#6b7280';

        $scoresHtml    = $this->renderScores($scores);
        $findingsHtml  = $this->renderFindings($results['agent_results'] ?? []);
        $testsHtml     = $this->renderTests($results['agent_results']['qa']['generated_tests'] ?? []);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CodeGuardian AI Report — {$project}</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #e2e8f0; line-height: 1.6; }
  .container { max-width: 1100px; margin: 0 auto; padding: 2rem; }
  header { text-align: center; padding: 3rem 0 2rem; border-bottom: 1px solid #1e293b; margin-bottom: 2rem; }
  header h1 { font-size: 2rem; color: #7c3aed; font-weight: 700; }
  header p { color: #94a3b8; margin-top: .5rem; }
  .badge { display: inline-flex; align-items: center; gap: .4rem; background: #1e293b; border-radius: 9999px; padding: .3rem .8rem; font-size: .75rem; font-weight: 600; margin: .25rem; }
  .overall { text-align: center; margin: 2rem 0; }
  .overall-score { font-size: 5rem; font-weight: 800; color: {$scoreColor}; line-height: 1; }
  .overall-label { color: #94a3b8; font-size: 1.1rem; margin-top: .5rem; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin: 2rem 0; }
  .card { background: #1e293b; border-radius: .75rem; padding: 1.25rem; text-align: center; }
  .card-value { font-size: 2rem; font-weight: 700; }
  .card-label { font-size: .8rem; color: #94a3b8; margin-top: .25rem; }
  .green { color: #22c55e; } .yellow { color: #f59e0b; } .red { color: #ef4444; } .blue { color: #3b82f6; }
  section { margin: 2.5rem 0; }
  section h2 { font-size: 1.25rem; font-weight: 700; color: #7c3aed; margin-bottom: 1rem; padding-bottom: .5rem; border-bottom: 1px solid #1e293b; }
  .finding { background: #1e293b; border-left: 4px solid #6b7280; border-radius: .5rem; padding: 1rem 1.25rem; margin-bottom: .75rem; }
  .finding.critical { border-color: #ef4444; }
  .finding.high     { border-color: #f97316; }
  .finding.medium   { border-color: #f59e0b; }
  .finding.low      { border-color: #22c55e; }
  .finding-header { display: flex; align-items: center; gap: .75rem; margin-bottom: .5rem; }
  .sev-badge { font-size: .7rem; font-weight: 700; padding: .2rem .6rem; border-radius: 9999px; text-transform: uppercase; }
  .sev-badge.critical { background: #7f1d1d; color: #fca5a5; }
  .sev-badge.high     { background: #7c2d12; color: #fdba74; }
  .sev-badge.medium   { background: #78350f; color: #fde68a; }
  .sev-badge.low      { background: #14532d; color: #86efac; }
  .finding-title { font-weight: 600; font-size: .95rem; }
  .finding-file  { font-size: .8rem; color: #94a3b8; margin-bottom: .5rem; font-family: monospace; }
  .finding-desc  { font-size: .9rem; color: #cbd5e1; }
  .finding-tags  { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .5rem; }
  .tag { font-size: .68rem; font-weight: 600; padding: .15rem .5rem; border-radius: 9999px; background: #0f172a; color: #cbd5e1; border: 1px solid #334155; }
  .tag-owasp { color: #fca5a5; border-color: #7f1d1d; }
  .tag-cwe { color: #fdba74; border-color: #7c2d12; }
  .tag-principle { color: #93c5fd; border-color: #1e3a8a; }
  .tag-conf { color: #86efac; border-color: #14532d; }
  .finding-meta  { margin-top: .5rem; font-size: .8rem; color: #94a3b8; display: grid; gap: .15rem; }
  .finding-meta strong { color: #cbd5e1; }
  .finding-fix   { margin-top: .5rem; font-size: .85rem; background: #0f172a; padding: .75rem; border-radius: .4rem; }
  pre { background: #0f172a; padding: 1rem; border-radius: .5rem; overflow-x: auto; font-size: .8rem; color: #93c5fd; margin-top: .5rem; }
  .test-card { background: #1e293b; border-radius: .5rem; padding: 1rem; margin-bottom: .75rem; }
  .test-card h3 { font-size: .9rem; font-weight: 700; color: #38bdf8; margin-bottom: .5rem; }
  .test-meta { display: flex; gap: 1rem; font-size: .8rem; color: #94a3b8; margin-bottom: .75rem; }
  footer { text-align: center; padding: 2rem 0; color: #475569; font-size: .8rem; border-top: 1px solid #1e293b; margin-top: 3rem; }
</style>
</head>
<body>
<div class="container">
  <header>
    <h1>CodeGuardian AI Report</h1>
    <p>{$project} &nbsp;·&nbsp; {$type} &nbsp;·&nbsp; {$scanned}</p>
    <div style="margin-top:1rem">
      <span class="badge">📁 {$summary['total_files']} files</span>
      <span class="badge">📝 {$summary['total_lines']} lines</span>
      <span class="badge">🔍 {$summary['total_issues']} issues</span>
    </div>
  </header>

  <div class="overall">
    <div class="overall-score">{$overall}</div>
    <div class="overall-label">Overall Quality Score</div>
  </div>

  {$scoresHtml}

  <section>
    <h2>Issues Summary</h2>
    <div class="grid">
      <div class="card"><div class="card-value red">{$summary['critical']}</div><div class="card-label">Critical</div></div>
      <div class="card"><div class="card-value yellow">{$summary['high']}</div><div class="card-label">High</div></div>
      <div class="card"><div class="card-value blue">{$summary['total_issues']}</div><div class="card-label">Total Issues</div></div>
    </div>
  </section>

  {$findingsHtml}
  {$testsHtml}

  <footer>Generated by <strong>CodeGuardian AI</strong> — codeguardian.ai</footer>
</div>
</body>
</html>
HTML;
    }

    private function renderScores(array $scores): string
    {
        if (empty($scores)) {
            return '';
        }

        $cards = '';
        foreach ($scores as $key => $value) {
            $label = ucwords(str_replace('_score', '', str_replace('_', ' ', $key)));
            $color = $value >= 80 ? 'green' : ($value >= 60 ? 'yellow' : 'red');
            $cards .= "<div class=\"card\"><div class=\"card-value {$color}\">{$value}</div><div class=\"card-label\">{$label}</div></div>";
        }

        return "<section><h2>Scores</h2><div class=\"grid\">{$cards}</div></section>";
    }

    private function renderFindings(array $agentResults): string
    {
        $html = '';
        foreach ($agentResults as $agentName => $result) {
            $findings = $result['findings'] ?? [];
            if (empty($findings) || $agentName === 'qa') {
                continue;
            }

            $label = ucwords(str_replace('_', ' ', $agentName));
            $html .= "<section><h2>{$label} Findings (" . count($findings) . ")</h2>";

            foreach ($findings as $f) {
                $sev   = e($f['severity'] ?? 'low');
                $title = e($f['title'] ?? 'Issue');
                $file  = e($f['file'] ?? '');
                $line  = isset($f['line_start']) ? ":{$f['line_start']}" : '';
                $desc  = e($f['description'] ?? '');
                $rec   = e($f['recommendation'] ?? $f['suggested_fix'] ?? '');

                $codeSnippet = '';
                if (! empty($f['code_snippet'])) {
                    $code        = e($f['code_snippet']);
                    $codeSnippet = "<pre>{$code}</pre>";
                }

                $fixHtml = $rec ? "<div class=\"finding-fix\">💡 {$rec}</div>" : '';

                $tagsHtml  = $this->renderFindingTags($f);
                $metaHtml  = $this->renderFindingMeta($f);

                $html .= <<<CARD
<div class="finding {$sev}">
  <div class="finding-header">
    <span class="sev-badge {$sev}">{$sev}</span>
    <span class="finding-title">{$title}</span>
  </div>
  <div class="finding-file">{$file}{$line}</div>
  {$tagsHtml}
  <div class="finding-desc">{$desc}</div>
  {$metaHtml}
  {$codeSnippet}
  {$fixHtml}
</div>
CARD;
            }

            $html .= '</section>';
        }

        return $html;
    }

    /** Small pill tags for security taxonomy / confidence (CWE, OWASP, confidence). */
    private function renderFindingTags(array $f): string
    {
        $tags = [];
        if (! empty($f['owasp'])) {
            $tags[] = '<span class="tag tag-owasp">OWASP ' . e($f['owasp']) . '</span>';
        } elseif (! empty($f['owasp_reference'])) {
            $tags[] = '<span class="tag tag-owasp">' . e($f['owasp_reference']) . '</span>';
        }
        if (! empty($f['cwe'])) {
            $tags[] = '<span class="tag tag-cwe">' . e($f['cwe']) . '</span>';
        }
        if (! empty($f['principle'])) {
            $tags[] = '<span class="tag tag-principle">' . e($f['principle']) . '</span>';
        }
        if (! empty($f['confidence'])) {
            $tags[] = '<span class="tag tag-conf">confidence: ' . e($f['confidence']) . '</span>';
        }

        return empty($tags) ? '' : '<div class="finding-tags">' . implode(' ', $tags) . '</div>';
    }

    /** Impact / effort / breaking-risk / root-cause line (Phase 7 actionable metadata). */
    private function renderFindingMeta(array $f): string
    {
        $rows = [];
        foreach ([
            'impact'        => 'Expected impact',
            'effort'        => 'Effort',
            'breaking_risk' => 'Breaking-change risk',
            'root_cause'    => 'Root cause',
        ] as $key => $label) {
            if (! empty($f[$key])) {
                $rows[] = '<div><strong>' . $label . ':</strong> ' . e((string) $f[$key]) . '</div>';
            }
        }

        return empty($rows) ? '' : '<div class="finding-meta">' . implode('', $rows) . '</div>';
    }

    private function renderTests(array $tests): string
    {
        if (empty($tests)) {
            return '';
        }

        $html = '<section><h2>Generated Tests (' . count($tests) . ')</h2>';

        foreach ($tests as $test) {
            $class     = e($test['class_name'] ?? 'Test');
            $framework = e($test['framework'] ?? '');
            $scenario  = e($test['scenario'] ?? '');
            $coverage  = e($test['coverage_area'] ?? '');
            $code      = e($test['test_code'] ?? '');

            $html .= <<<CARD
<div class="test-card">
  <h3>{$class}</h3>
  <div class="test-meta">
    <span>Framework: {$framework}</span>
    <span>Coverage: {$coverage}</span>
  </div>
  <div style="font-size:.85rem; color:#94a3b8; margin-bottom:.5rem">{$scenario}</div>
  <pre>{$code}</pre>
</div>
CARD;
        }

        return $html . '</section>';
    }
}
