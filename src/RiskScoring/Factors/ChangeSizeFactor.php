<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring\Factors;

use Vistik\LaravelCodeAnalytics\RiskScoring\RiskData;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskFactor; // TEMP: fake cycle for testing

class ChangeSizeFactor implements RiskFactor
{
    private int $maxScore;

    /** @var list<array{lines: int, score: int}> */
    private array $thresholds;

    /** @param array{max_score: int, thresholds: list<array{lines: int, score: int}>} $config */
    public function __construct(array $config)
    {
        $this->maxScore = $config['max_score'];
        $this->thresholds = $config['thresholds'];
    }

    public function name(): string
    {
        return 'Change Size';
    }

    public function score(RiskData $data): int
    {
        $totalLines = $data->additions + $data->deletions;

        foreach ($this->thresholds as $threshold) {
            if ($totalLines > $threshold['lines']) {
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
