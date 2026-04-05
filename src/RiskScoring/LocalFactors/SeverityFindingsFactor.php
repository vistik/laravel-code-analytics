<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring\LocalFactors;

use Vistik\LaravelCodeAnalytics\RiskScoring\RiskFactor;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskData;

class SeverityFindingsFactor implements RiskFactor
{
    public function name(): string
    {
        return 'Code Analysis Findings';
    }

    public function score(RiskData $data): int
    {
        $veryHighs = array_sum(array_column($data->nodes, 'veryHighCount'));
        $highs = array_sum(array_column($data->nodes, 'highCount'));
        $mediums = array_sum(array_column($data->nodes, 'mediumCount'));
        $lows = array_sum(array_column($data->nodes, 'lowCount'));

        $score = ($veryHighs * 10) + ($highs * 6) + ($mediums * 3) + ($lows * 1);

        return min($score, $this->maxScore());
    }

    public function maxScore(): int
    {
        return 40;
    }
}
