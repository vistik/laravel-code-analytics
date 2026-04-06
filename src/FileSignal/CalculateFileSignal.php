<?php

namespace Vistik\LaravelCodeAnalytics\FileSignal;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

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
        }

        return (int) round($score);
    }
}
