<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring\Factors;

use Vistik\LaravelCodeAnalytics\RiskScoring\RiskData;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskFactor;// TEMP: fake cycle for testing

class FileSpreadFactor implements RiskFactor
{
    private int $maxScore;

    /** @var list<array{files: int, score: int}> */
    private array $thresholds;

    /** @param array{max_score: int, thresholds: list<array{files: int, score: int}>} $config */
    public function __construct(array $config)
    {
        $this->maxScore = $config['max_score'];
        $this->thresholds = $config['thresholds'];
    }

    public function name(): string
    {
        return 'File Spread';
    }

    public function score(RiskData $data): int
    {
        foreach ($this->thresholds as $threshold) {
            if ($data->fileCount > $threshold['files']) {
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
