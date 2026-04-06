<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

class GenerateMdReport implements ReportGenerator
{
    public function generate(
        array $nodes,
        array $edges,
        array $fileDiffs,
        array $analysisData,
        string $title,
        string $repo,
        string $headCommit,
        int $prAdditions,
        int $prDeletions,
        int $fileCount,
        ?RiskScore $riskScore = null,
        array $metricsData = [],
        array $clusters = [],
    ): string {
        $lines = [];

        $lines[] = "# {$title}";
        $lines[] = '';
        $lines[] = "**Repo:** {$repo} | **HEAD:** ".substr($headCommit, 0, 7)." | **Files:** {$fileCount} | **+{$prAdditions} -{$prDeletions}**";
        $lines[] = '';

        // ── Risk Score ──────────────────────────────────────────────────────
        if ($riskScore !== null) {
            $lines[] = '## Risk Score: '.$riskScore->score.'/100 — '.$this->riskLabel($riskScore->score);
            $lines[] = '';

            if (! empty($riskScore->factors)) {
                $lines[] = '| Factor | Score | Max |';
                $lines[] = '|--------|------:|----:|';
                foreach ($riskScore->factors as $factor) {
                    $lines[] = "| {$factor['name']} | {$factor['score']} | {$factor['maxScore']} |";
                }
                $lines[] = '';
            }
        }

        // ── Severity Summary ────────────────────────────────────────────────
        $severityCounts = $this->countSeverities($nodes);
        $nonZero = array_filter($severityCounts, fn ($count) => $count > 0);

        if (! empty($nonZero)) {
            $lines[] = '## Severity Summary';
            $lines[] = '';
            $lines[] = '| Severity | Count |';
            $lines[] = '|----------|------:|';
            foreach ($nonZero as $label => $count) {
                $lines[] = "| {$label} | {$count} |";
            }
            $lines[] = '';
        }

        // ── Files by Severity ───────────────────────────────────────────────
        $lines[] = '## Files';
        $lines[] = '';

        $sorted = $nodes;
        usort($sorted, fn ($a, $b) => ($b['_signal'] ?? 0) <=> ($a['_signal'] ?? 0));

        $lines[] = '| File | Status | +/- | Severity | Signal |';
        $lines[] = '|------|--------|----:|----------|-------:|';

        foreach ($sorted as $node) {
            $sev = $node['severity'] ? ucfirst($node['severity']) : '—';
            $signal = $node['_signal'] ?? 0;
            $status = ucfirst($node['status']);
            $lines[] = "| `{$node['path']}` | {$status} | +{$node['add']}/-{$node['del']} | {$sev} | {$signal} |";
        }
        $lines[] = '';

        // ── AST Findings ────────────────────────────────────────────────────
        $hasFindings = ! empty(array_filter($analysisData, fn ($f) => ! empty($f)));

        if ($hasFindings) {
            $lines[] = '## Findings';
            $lines[] = '';

            foreach ($analysisData as $filePath => $findings) {
                if (empty($findings)) {
                    continue;
                }

                $lines[] = "### `{$filePath}`";
                $lines[] = '';
                $lines[] = '| Severity | Category | Description | Location |';
                $lines[] = '|----------|----------|-------------|----------|';

                foreach ($findings as $finding) {
                    $sev = ucfirst($finding['severity']);
                    $cat = $finding['category'];
                    $desc = str_replace('|', '\\|', $finding['description']);
                    $loc = $finding['location'] ?? (isset($finding['line']) ? "L{$finding['line']}" : '—');

                    $lines[] = "| {$sev} | {$cat} | {$desc} | {$loc} |";
                }
                $lines[] = '';
            }
        }

        // ── PhpMetrics ──────────────────────────────────────────────────────
        if (! empty($metricsData)) {
            $lines[] = '## Metrics';
            $lines[] = '';
            $lines[] = '| File | CC | MI | Bugs | Coupling | LLOC |';
            $lines[] = '|------|---:|---:|-----:|---------:|-----:|';

            foreach ($metricsData as $path => $m) {
                $cc = $m['cc'] ?? '—';
                $mi = isset($m['mi']) ? number_format($m['mi'], 1) : '—';
                $bugs = isset($m['bugs']) ? number_format($m['bugs'], 3) : '—';
                $coupling = $m['coupling'] ?? '—';
                $lloc = $m['lloc'] ?? '—';

                $lines[] = "| `{$path}` | {$cc} | {$mi} | {$bugs} | {$coupling} | {$lloc} |";
            }
            $lines[] = '';
        }

        // ── Coupling Clusters ───────────────────────────────────────────────
        $hasAnyClusters = array_reduce(array_values($clusters), fn ($c, $l) => $c || ! empty($l), false);
        if ($hasAnyClusters) {
            foreach ($clusters as $algoValue => $algoClusters) {
                if (empty($algoClusters)) {
                    continue;
                }
                $algoLabel = \Vistik\LaravelCodeAnalytics\Enums\ClusteringAlgorithm::tryFrom($algoValue)?->label() ?? ucfirst($algoValue);
                $lines[] = '## Coupling Clusters — '.$algoLabel.' ('.count($algoClusters).')';
                $lines[] = '';
                foreach ($algoClusters as $i => $cluster) {
                    $lines[] = '### Cluster '.($i + 1).' ('.$cluster['size'].' files)';
                    $lines[] = '';
                    foreach ($cluster['files'] as $file) {
                        $lines[] = "- `{$file}`";
                    }
                    $lines[] = '';
                }
            }
        }

        // ── Dependencies ────────────────────────────────────────────────────
        if (! empty($edges)) {
            $lines[] = '## Dependencies ('.count($edges).')';
            $lines[] = '';
            $lines[] = '| Source | Target |';
            $lines[] = '|--------|--------|';
            foreach ($edges as [$source, $target]) {
                $lines[] = "| {$source} | {$target} |";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function writeFile(string $outputPath, string $content): void
    {
        file_put_contents($outputPath, $content);
    }

    private function riskLabel(int $score): string
    {
        return match (true) {
            $score >= 75 => 'Very High',
            $score >= 50 => 'High',
            $score >= 25 => 'Medium',
            default => 'Low',
        };
    }

    /**
     * @return array<string, int>
     */
    private function countSeverities(array $nodes): array
    {
        $counts = [];
        foreach (array_reverse(Severity::cases()) as $sev) {
            $counts[$sev->label()] = 0;
        }

        foreach ($nodes as $node) {
            foreach (Severity::cases() as $sev) {
                $counts[$sev->label()] += $node[$sev->countKey()] ?? 0;
            }
        }

        return $counts;
    }
}
