<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

class GenerateJsonReport implements ReportGenerator
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
        string $prUrl = '',
        ?RiskScore $riskScore = null,
        array $metricsData = [],
        array $fileContents = [],
        array $filterDefaults = [],
    ): string {
        $sorted = $nodes;
        usort($sorted, fn ($a, $b) => ($b['_signal'] ?? 0) <=> ($a['_signal'] ?? 0));

        $files = array_map(fn ($node) => [
            'path' => $node['path'],
            'status' => $node['status'],
            'additions' => $node['add'],
            'deletions' => $node['del'],
            'severity' => $node['severity'] ?? null,
            'signal' => $node['_signal'] ?? 0,
        ], $sorted);

        $findings = [];
        foreach ($analysisData as $filePath => $fileFindings) {
            if (empty($fileFindings)) {
                continue;
            }
            foreach ($fileFindings as $finding) {
                $findings[] = array_merge(['file' => $filePath], $finding);
            }
        }

        $metrics = [];
        foreach ($metricsData as $path => $m) {
            $entry = [
                'file' => $path,
                'cc' => $m['cc'] ?? null,
                'mi' => $m['mi'] ?? null,
                'bugs' => $m['bugs'] ?? null,
                'coupling' => $m['coupling'] ?? null,
                'lloc' => $m['lloc'] ?? null,
            ];

            if (! empty($m['method_metrics'])) {
                $entry['method_metrics'] = $m['method_metrics'];
            }

            $metrics[] = $entry;
        }

        $dependencies = array_map(fn ($edge) => [
            'source' => $edge[0],
            'target' => $edge[1],
        ], $edges);

        return json_encode([
            'title' => $title,
            'repo' => $repo,
            'head_commit' => $headCommit,
            'file_count' => $fileCount,
            'additions' => $prAdditions,
            'deletions' => $prDeletions,
            'risk' => $riskScore?->toArray(),
            'files' => $files,
            'findings' => $findings,
            'metrics' => $metrics,
            'dependencies' => $dependencies,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function writeFile(string $outputPath, string $content): void
    {
        file_put_contents($outputPath, $content);
    }
}
