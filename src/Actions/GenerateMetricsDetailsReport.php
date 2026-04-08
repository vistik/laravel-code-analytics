<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

class GenerateMetricsDetailsReport extends GenerateMetricsReport implements ReportGenerator
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
    ): string {
        $output = parent::generate(
            $nodes, $edges, $fileDiffs, $analysisData,
            $title, $repo, $headCommit,
            $prAdditions, $prDeletions, $fileCount,
            $prUrl, $riskScore, $metricsData,
        );

        if (empty($metricsData)) {
            return $output;
        }

        $methodLines = [];

        foreach ($metricsData as $path => $m) {
            $methods = $m['method_metrics'] ?? [];
            if (empty($methods)) {
                continue;
            }

            $beforeMethods = [];
            foreach ($m['before_method_metrics'] ?? [] as $bm) {
                $beforeMethods[$bm['name']] = $bm;
            }
            $hasBefore = ! empty($beforeMethods);

            $methodLines[] = sprintf('  %s — methods:', $path);
            $methodLines[] = sprintf('    %-30s  %6s  %6s  %6s', '', 'CC', 'LLOC', 'Params');
            $methodLines[] = '    '.str_repeat('─', 52);

            foreach ($methods as $method) {
                $name = $method['name'];
                $methodLines[] = sprintf(
                    '    %-30s  %6s  %6s  %6s',
                    $name,
                    $method['cc'],
                    $method['lloc'],
                    $method['params'],
                );

                if ($hasBefore) {
                    $bm = $beforeMethods[$name] ?? null;
                    $d = fn (string $key) => $this->methodDelta($method[$key], $bm[$key] ?? null);
                    $deltas = [$d('cc'), $d('lloc'), $d('params')];
                    if (array_filter($deltas, fn ($v) => $v !== '=')) {
                        $methodLines[] = sprintf(
                            '    %-30s  %6s  %6s  %6s',
                            '  delta',
                            ...$deltas,
                        );
                    }
                }
            }
        }

        if (empty($methodLines)) {
            return $output;
        }

        return rtrim($output)."\n\nMethod Metrics:\n".implode("\n", $methodLines)."\n";
    }

    private function methodDelta(int|float $after, int|float|null $before): string
    {
        if ($before === null) {
            return 'new';
        }
        $diff = $after - $before;
        if ($diff === 0) {
            return '=';
        }

        return sprintf('%+g', $diff);
    }
}
