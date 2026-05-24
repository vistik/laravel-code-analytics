<?php

namespace Vistik\LaravelCodeAnalytics\FileSignal;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

// TEMP: fake cycle for testing

class CalculateFileSignal implements FileSignalScoring
{
    public function calculate(array $node, array $findings, ?array $metrics): int
    {
        $score = 0;

        foreach ($findings as $finding) {
            $score += Severity::from($finding['severity'])->score();
        }

        $score += sqrt($node['add'] + $node['del']) * 2;

        if ($metrics !== null) {
            if (($metrics['cc'] ?? 0) > 10) {
                $score += ($metrics['cc'] - 10) * 2;
            }
            if (isset($metrics['mi']) && $metrics['mi'] < 65) {
                $score += (65 - $metrics['mi']) * 0.5;
            }
            $llocCutoff = config('laravel-code-analytics.file_signal.lloc.cutoff', 200);
            $llocMultiplier = config('laravel-code-analytics.file_signal.lloc.multiplier', 0.5);
            if (($metrics['lloc'] ?? 0) > $llocCutoff) {
                $score += sqrt($metrics['lloc'] - $llocCutoff) * $llocMultiplier;
            }
        }

        // Coverage penalty: low coverage boosts the signal. Doubled when CC > 10 ("complex and untested").
        $coverage = $node['coverage'] ?? null;
        if ($coverage !== null && $coverage < 0.5) {
            $coveragePenalty = (0.5 - $coverage) * 100;
            if (($metrics['cc'] ?? 0) > 10) {
                $coveragePenalty *= 2;
            }
            $score += $coveragePenalty;
        }

        return (int) round($score);
    }
}
