<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;
use Vistik\LaravelCodeAnalytics\Enums\GraphLayout;
use Vistik\LaravelCodeAnalytics\GraphIndex\GraphIndexBuilder;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;

class GenerateJsonReport implements ReportGenerator
{
    public function generate(
        GraphPayload $payload,
        PullRequestContext $pr,
        ?GraphLayout $defaultView = null,
        ?LayerStack $layerStack = null,
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
            'base_signal' => $node['_baseSignal'] ?? null,
            'domain' => $node['domain'] ?? null,
            'group' => $node['group'] ?? null,
            'cycle_id' => $node['cycleId'] ?? null,
            'cycle_boost' => $node['_cycleBoost'] ?? null,
            'connection_boost' => $node['_connectionBoost'] ?? null,
            'cluster_id' => $node['clusterId'] ?? null,
            'cluster_name' => $node['clusterName'] ?? null,
            'cluster_size' => $node['clusterSize'] ?? null,
        ], $sorted);

        $cycleGroups = [];
        foreach ($nodes as $node) {
            if (($node['cycleId'] ?? null) !== null) {
                $cycleGroups[$node['cycleId']][] = $node['path'];
            }
        }
        ksort($cycleGroups);

        $clusterGroups = [];
        foreach ($nodes as $node) {
            if (($node['clusterId'] ?? null) !== null) {
                $clusterGroups[$node['clusterId']]['name'] = $node['clusterName'] ?? (string) $node['clusterId'];
                $clusterGroups[$node['clusterId']]['paths'][] = $node['path'];
            }
        }
        ksort($clusterGroups);

        $domainGroups = [];
        foreach ($nodes as $node) {
            $domain = $node['domain'] ?? '(root)';
            $domainGroups[$domain][] = $node['path'];
        }
        ksort($domainGroups);

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
                'methods' => $m['methods'] ?? null,
                'flog' => $m['flog'] ?? null,
            ];

            if (! empty($m['method_metrics'])) {
                $entry['method_metrics'] = $m['method_metrics'];
            }

            if (! empty($m['class_metrics'])) {
                $entry['class_metrics'] = $m['class_metrics'];
            }

            if (! empty($m['before'])) {
                $entry['before'] = $m['before'];
            }

            if (! empty($m['before_method_metrics'])) {
                $entry['before_method_metrics'] = $m['before_method_metrics'];
            }

            if (! empty($m['before_class_metrics'])) {
                $entry['before_class_metrics'] = $m['before_class_metrics'];
            }

            $metrics[] = $entry;
        }

        $dependencies = array_map(fn ($edge) => [
            'source' => $edge[0],
            'target' => $edge[1],
            'type' => $edge[2] ?? null,
            'line' => $edge[3] ?? null,
        ], $edges);

        $graphIndex = (new GraphIndexBuilder)->build(
            nodes: $payload->nodes,
            edges: $payload->edges,
            metricsData: $payload->metricsData,
            fileDiffs: $payload->fileDiffs,
            fileContents: $payload->fileContents,
        );

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
            'domain_clusters' => array_map(
                fn ($domain, $paths) => ['domain' => $domain, 'files' => $paths],
                array_keys($domainGroups),
                array_values($domainGroups),
            ),
            'circular_dependencies' => array_map(
                fn ($paths) => ['files' => $paths],
                array_values($cycleGroups),
            ),
            'review_clusters' => array_map(
                fn ($id, $group) => ['cluster_id' => $id, 'name' => $group['name'], 'size' => count($group['paths']), 'files' => $group['paths']],
                array_keys($clusterGroups),
                array_values($clusterGroups),
            ),
            'graph_index' => $graphIndex,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function writeFile(string $outputPath, string $content): void
    {
        file_put_contents($outputPath, $content);
    }
}
