<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring\Factors;

use Vistik\LaravelCodeAnalytics\RiskScoring\RiskData;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskFactor;

class SeverityFindingsFactor implements RiskFactor
{
    private int $maxScore;

    private int $veryHighWeight;

    private int $highWeight;

    private int $mediumWeight;

    private int $lowWeight;

    /** @param array{max_score: int, weights: array{very_high: int, high: int, medium: int, low: int}} $config */
    public function __construct(array $config)
    {
        $this->maxScore = $config['max_score'];
        $this->veryHighWeight = $config['weights']['very_high'];
        $this->highWeight = $config['weights']['high'];
        $this->mediumWeight = $config['weights']['medium'];
        $this->lowWeight = $config['weights']['low'];
    }

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

        $score = ($veryHighs * $this->veryHighWeight)
            + ($highs * $this->highWeight)
            + ($mediums * $this->mediumWeight)
            + ($lows * $this->lowWeight);

        return min($score, $this->maxScore());
    }

    public function maxScore(): int
    {
        return $this->maxScore;
    }
}
