<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring\Factors;

use Vistik\LaravelCodeAnalytics\RiskScoring\RiskData;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskFactor;

class PhpMetricsFactor implements RiskFactor
{
    private int $maxScore;

    /** @var list<array{hotspots: int, score: int}> */
    private array $thresholds;

    /** @param array{max_score: int, thresholds: list<array{hotspots: int, score: int}>} $config */
    public function __construct(array $config)
    {
        $this->maxScore = $config['max_score'];
        $this->thresholds = $config['thresholds'];
    }

    public function name(): string
    {
        return 'PHP Code Quality';
    }

    public function score(RiskData $data): int
    {
        foreach ($this->thresholds as $threshold) {
            if ($data->phpHotSpots >= $threshold['hotspots']) {
                return $threshold['score'];
            }
        }

        return 0;
    }

    public function maxScore(): int
    {
        return $this->maxScore;
    }
}
