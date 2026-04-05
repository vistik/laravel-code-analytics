<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring\LocalFactors;

use Vistik\LaravelCodeAnalytics\RiskScoring\LocalRiskFactor;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskData;

class PhpMetricsFactor implements LocalRiskFactor
{
    public function name(): string
    {
        return 'PHP Code Quality';
    }

    public function score(RiskData $data): int
    {
        return match (true) {
            $data->phpHotSpots >= 11 => 15,
            $data->phpHotSpots >= 8 => 12,
            $data->phpHotSpots >= 5 => 9,
            $data->phpHotSpots >= 3 => 6,
            $data->phpHotSpots >= 1 => 3,
            default => 0,
        };
    }

    public function maxScore(): int
    {
        return 15;
    }
}
