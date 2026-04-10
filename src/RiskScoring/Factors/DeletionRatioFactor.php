<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring\Factors;

use Vistik\LaravelCodeAnalytics\RiskScoring\RiskData;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskFactor;

class DeletionRatioFactor implements RiskFactor
{
    private int $maxScore;

    /** @var list<array{ratio: float, score: int}> */
    private array $thresholds;

    /** @param array{max_score: int, thresholds: list<array{ratio: float, score: int}>} $config */
    public function __construct(array $config)
    {
        $this->maxScore = $config['max_score'];
        $this->thresholds = $config['thresholds'];
    }

    public function name(): string
    {
        return 'Deletion Ratio';
    }

    public function score(RiskData $data): int
    {
        $totalLines = $data->additions + $data->deletions;
        $ratio = $totalLines > 0 ? $data->deletions / $totalLines : 0;

        foreach ($this->thresholds as $threshold) {
            if ($ratio > $threshold['ratio']) {
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
