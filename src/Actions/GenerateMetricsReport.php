<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;
use Vistik\LaravelCodeAnalytics\Enums\GraphLayout;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

class GenerateMetricsReport implements ReportGenerator
{
    public function generate(
        GraphPayload $payload,
        PullRequestContext $pr,
        ?GraphLayout $defaultView = null,
        ?LayerStack $layerStack = null,
    ): string {
        $nodes = $payload->nodes;
        $metricsData = $payload->metricsData;
        $riskScore = $payload->riskScore;

        $severityCounts = ['very_high' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
        $totalFindings = 0;
        $maxSeverity = null;

        $severityOrder = ['very_high' => 5, 'high' => 4, 'medium' => 3, 'low' => 2, 'info' => 1];

        $cycleFileCount = 0;
        $cycleIds = [];

        foreach ($nodes as $node) {
            $severityCounts['very_high'] += $node['veryHighCount'] ?? 0;
            $severityCounts['high'] += $node['highCount'] ?? 0;
            $severityCounts['medium'] += $node['mediumCount'] ?? 0;
            $severityCounts['low'] += $node['lowCount'] ?? 0;
            $severityCounts['info'] += $node['infoCount'] ?? 0;
            $totalFindings += $node['analysisCount'] ?? 0;

            $nodeSeverity = $node['severity'] ?? null;
            if ($nodeSeverity !== null) {
                if ($maxSeverity === null || ($severityOrder[$nodeSeverity] ?? 0) > ($severityOrder[$maxSeverity] ?? 0)) {
                    $maxSeverity = $nodeSeverity;
                }
            }

            if (($node['cycleId'] ?? null) !== null) {
                $cycleFileCount++;
                $cycleIds[$node['cycleId']] = true;
            }
        }

        $lines = [];

        if ($pr->prTitle) {
            $lines[] = $pr->prTitle;
            $lines[] = str_repeat('─', min(strlen($pr->prTitle), 60));
        }

        $lines[] = sprintf('Risk Score:   %d / 100', $riskScore instanceof RiskScore ? $riskScore->score : 0);
        $lines[] = sprintf('Files:        %d  (+%d / -%d lines)', $pr->fileCount, $pr->prAdditions, $pr->prDeletions);
        $lines[] = sprintf('Findings:     %d  (max severity: %s)', $totalFindings, $maxSeverity ?? 'none');
        if ($cycleFileCount > 0) {
            $lines[] = sprintf('Circular deps: %d cycle(s)  %d file(s)', count($cycleIds), $cycleFileCount);
        }
        $lines[] = sprintf(
            'Severity:     very_high=%d  high=%d  medium=%d  low=%d  info=%d',
            $severityCounts['very_high'],
            $severityCounts['high'],
            $severityCounts['medium'],
            $severityCounts['low'],
            $severityCounts['info'],
        );

        if ($riskScore && ! empty($riskScore->factors)) {
            $lines[] = 'Risk Factors:';
            foreach ($riskScore->factors as $factor) {
                $lines[] = sprintf('  %-24s %d / %d', $factor['name'], $factor['score'], $factor['maxScore']);
            }
        }

        if (! empty($metricsData)) {
            $lines[] = '';
            $lines[] = 'PHP Metrics:';
            $lines[] = $this->metricsRow('', ['cc' => 'CC', 'mi' => 'MI', 'bugs' => 'Bugs', 'coupling' => 'Coup.', 'lloc' => 'LLOC']);
            $lines[] = '    '.str_repeat('─', 48);

            foreach ($metricsData as $path => $m) {
                $before = $m['before'] ?? null;

                $lines[] = sprintf('  %s', $path);
                $lines[] = $this->metricsRow('after', $m);

                if ($before !== null) {
                    $lines[] = $this->metricsRow('before', $before);
                    $lines[] = $this->deltaRow($m, $before);
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** @param array<string, mixed> $after
     *  @param array<string, mixed> $before */
    private function deltaRow(array $after, array $before): string
    {
        $d = function (string $key) use ($after, $before): string {
            if (! isset($after[$key]) || ! isset($before[$key])) {
                return '-';
            }
            $diff = round((float) $after[$key] - (float) $before[$key], 3);
            if ($diff == 0) {
                return '=';
            }

            return sprintf('%+g', $diff);
        };

        return sprintf(
            '    %-6s  %6s  %6s  %6s  %6s  %6s',
            'delta',
            $d('cc'), $d('mi'), $d('bugs'), $d('coupling'), $d('lloc'),
        );
    }

    /** @param array<string, mixed> $m */
    private function metricsRow(string $label, array $m): string
    {
        $val = fn (string $key) => isset($m[$key]) ? (string) $m[$key] : '-';

        return sprintf(
            '    %-6s  %6s  %6s  %6s  %6s  %6s',
            $label,
            $val('cc'),
            $val('mi'),
            $val('bugs'),
            $val('coupling'),
            $val('lloc'),
        );
    }

    public function writeFile(string $outputPath, string $content): void
    {
        file_put_contents($outputPath, $content);
    }
}
