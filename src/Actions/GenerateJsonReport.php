<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;
use Vistik\LaravelCodeAnalytics\Enums\GraphLayout;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;

class GenerateJsonReport implements ReportGenerator
{
    public function generate(
        GraphPayload $payload,
        PullRequestContext $pr,
        ?GraphLayout $defaultView = null,
    ): string {
        $nodes = $payload->nodes;
        $edges = $payload->edges;
        $analysisData = $payload->analysisData;
        $metricsData = $payload->metricsData;
        $riskScore = $payload->riskScore;

        $sorted = $nodes;
        usort($sorted, fn ($a, $b) => ($b['_signal'] ?? 0) <=> ($a['_signal'] ?? 0));

        $files = array_map(fn ($node) => [
            'path' => $node['path'],
            'status' => $node['status'],
            'additions' => $node['add'],
            'deletions' => $node['del'],
            'severity' => $node['severity'] ?? null,
            'signal' => $node['_signal'] ?? 0,
            'cycle_id' => $node['cycleId'] ?? null,
            'cycle_boost' => $node['_cycleBoost'] ?? null,
        ], $sorted);

        $cycleGroups = [];
        foreach ($nodes as $node) {
            if (($node['cycleId'] ?? null) !== null) {
                $cycleGroups[$node['cycleId']][] = $node['path'];
            }
        }
        ksort($cycleGroups);

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
            'title' => $pr->prTitle,
            'repo' => $pr->repo,
            'head_commit' => $pr->headCommit,
            'file_count' => $pr->fileCount,
            'additions' => $pr->prAdditions,
            'deletions' => $pr->prDeletions,
            'risk' => $riskScore?->toArray(),
            'files' => $files,
            'findings' => $findings,
            'metrics' => $metrics,
            'dependencies' => $dependencies,
            'circular_dependencies' => array_map(
                fn ($paths) => ['files' => $paths],
                array_values($cycleGroups),
            ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function writeFile(string $outputPath, string $content): void
    {
        file_put_contents($outputPath, $content);
    }
}
