<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class MinSeverityFilter
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, list<array<string, mixed>>>  $analysisData
     * @param  array<string, mixed>  $metricsData
     * @param  array<string, mixed>  $fileDiffs
     * @return array{nodes: list<array<string, mixed>>, analysisData: array<string, list<array<string, mixed>>>, metricsData: array<string, mixed>, fileDiffs: array<string, mixed>}
     */
    public function apply(
        array $nodes,
        array $analysisData,
        array $metricsData,
        array $fileDiffs,
        Severity $minSeverity,
    ): array {
        $minScore = $minSeverity->score();

        $nodes = array_values(array_filter($nodes, function (array $node) use ($minScore): bool {
            if ($node['severity'] === null) {
                return false;
            }

            return Severity::from($node['severity'])->score() >= $minScore;
        }));

        $filteredPaths = array_column($nodes, 'path');
        $analysisData = array_intersect_key($analysisData, array_flip($filteredPaths));
        $metricsData = array_intersect_key($metricsData, array_flip($filteredPaths));
        $fileDiffs = array_intersect_key($fileDiffs, array_flip($filteredPaths));

        foreach ($analysisData as &$reports) {
            $reports = array_values(array_filter($reports, fn ($r) => Severity::from($r['severity'])->score() >= $minScore));
        }
        unset($reports);

        foreach ($nodes as &$node) {
            $reports = $analysisData[$node['path']] ?? [];
            $node['analysisCount'] = count($reports);
            $node['veryHighCount'] = count(array_filter($reports, fn ($r) => $r['severity'] === Severity::VERY_HIGH->value));
            $node['highCount'] = count(array_filter($reports, fn ($r) => $r['severity'] === Severity::HIGH->value));
            $node['mediumCount'] = count(array_filter($reports, fn ($r) => $r['severity'] === Severity::MEDIUM->value));
            $node['lowCount'] = count(array_filter($reports, fn ($r) => $r['severity'] === Severity::LOW->value));
            $node['infoCount'] = count(array_filter($reports, fn ($r) => $r['severity'] === Severity::INFO->value));
        }
        unset($node);

        return compact('nodes', 'analysisData', 'metricsData', 'fileDiffs');
    }
}
