<?php

namespace Vistik\LaravelCodeAnalytics\FileSignal;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class CalculateFileSignal implements FileSignalScoring
{
    public function __construct(private array $config = []) {}

    public function calculate(array $node, array $findings, ?array $metrics): array
    {
        $findingsScore = 0;
        $severityWeights = $this->config['findings'] ?? [];

        foreach ($findings as $finding) {
            $severity = Severity::from($finding['severity']);
            $weight = $severityWeights[$severity->value] ?? $severity->score();
            $findingsScore += $weight;
        }

        $changeSizeMultiplier = (float) ($this->config['change_size']['multiplier'] ?? 2.0);
        $changeSizeScore = sqrt($node['add'] + $node['del']) * $changeSizeMultiplier;

        $ccScore = 0.0;
        $miScore = 0.0;
        $llocScore = 0.0;

        if ($metrics !== null) {
            $ccThreshold = (int) ($this->config['cc']['threshold'] ?? 10);
            $ccMultiplier = (float) ($this->config['cc']['multiplier'] ?? 2.0);
            if (($metrics['cc'] ?? 0) > $ccThreshold) {
                $ccScore = ($metrics['cc'] - $ccThreshold) * $ccMultiplier;
            }

            $miThreshold = (float) ($this->config['mi']['threshold'] ?? 65);
            $miMultiplier = (float) ($this->config['mi']['multiplier'] ?? 0.5);
            if (isset($metrics['mi']) && $metrics['mi'] < $miThreshold) {
                $miScore = ($miThreshold - $metrics['mi']) * $miMultiplier;
            }

            $llocCutoff = (int) ($this->config['lloc']['cutoff'] ?? config('laravel-code-analytics.file_signal.lloc.cutoff', 200));
            $llocMultiplier = (float) ($this->config['lloc']['multiplier'] ?? config('laravel-code-analytics.file_signal.lloc.multiplier', 0.5));
            if (($metrics['lloc'] ?? 0) > $llocCutoff) {
                $llocScore = sqrt($metrics['lloc'] - $llocCutoff) * $llocMultiplier;
            }
        }

        $total = $findingsScore + $changeSizeScore + $ccScore + $miScore + $llocScore;

        return [
            'score' => (int) round($total),
            'breakdown' => [
                'findings' => (int) round($findingsScore),
                'change_size' => (int) round($changeSizeScore),
                'cc' => (int) round($ccScore),
                'mi' => (int) round($miScore),
                'lloc' => (int) round($llocScore),
            ],
        ];
    }
}
