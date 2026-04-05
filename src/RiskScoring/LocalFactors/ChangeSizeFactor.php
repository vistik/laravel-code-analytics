<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring\LocalFactors;

use Vistik\LaravelCodeAnalytics\RiskScoring\RiskData;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskFactor;

class ChangeSizeFactor implements RiskFactor
{
    public function name(): string
    {
        return 'Change Size';
    }

    public function score(RiskData $data): int
    {
        $totalLines = $data->additions + $data->deletions;

        return match (true) {
            $totalLines > 1000 => 25,
            $totalLines > 500 => 15,
            $totalLines > 200 => 10,
            $totalLines > 50 => 5,
            default => 0,
        };
    }

    public function maxScore(): int
    {
        return 25;
    }
}
