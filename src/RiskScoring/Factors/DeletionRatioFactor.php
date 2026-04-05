<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring\Factors;

use Vistik\LaravelCodeAnalytics\RiskScoring\RiskData;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskFactor;

class DeletionRatioFactor implements RiskFactor
{
    public function name(): string
    {
        return 'Deletion Ratio';
    }

    public function score(RiskData $data): int
    {
        $totalLines = $data->additions + $data->deletions;
        $ratio = $totalLines > 0 ? $data->deletions / $totalLines : 0;

        return match (true) {
            $ratio > 0.8 => 10,
            $ratio > 0.6 => 6,
            $ratio > 0.4 => 3,
            default => 0,
        };
    }

    public function maxScore(): int
    {
        return 10;
    }
}
