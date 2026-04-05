<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring;

use Vistik\LaravelCodeAnalytics\RiskScoring\Factors\ChangeSizeFactor;
use Vistik\LaravelCodeAnalytics\RiskScoring\Factors\DeletionRatioFactor;
use Vistik\LaravelCodeAnalytics\RiskScoring\Factors\FileSpreadFactor;
use Vistik\LaravelCodeAnalytics\RiskScoring\Factors\PhpMetricsFactor;
use Vistik\LaravelCodeAnalytics\RiskScoring\Factors\SeverityFindingsFactor;

class CalculateRiskScore implements RiskScoring
{
    /** @var list<RiskFactor> */
    private array $factors;

    public function __construct()
    {
        $this->factors = [
            new ChangeSizeFactor,
            new FileSpreadFactor,
            new DeletionRatioFactor,
            new SeverityFindingsFactor,
            new PhpMetricsFactor,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    public function calculate(array $nodes, int $additions, int $deletions, int $fileCount, int $phpHotSpots): RiskScore
    {
        $data = new RiskData($nodes, $additions, $deletions, $fileCount, $phpHotSpots);

        $totalScore = 0;
        $totalMax = 0;
        $breakdown = [];

        foreach ($this->factors as $factor) {
            $score = $factor->score($data);
            $maxScore = $factor->maxScore();

            $totalScore += $score;
            $totalMax += $maxScore;

            $breakdown[] = [
                'name' => $factor->name(),
                'score' => $score,
                'maxScore' => $maxScore,
            ];
        }

        $normalized = $totalMax > 0
            ? (int) round(($totalScore / $totalMax) * 100)
            : 0;

        return new RiskScore(
            score: min($normalized, 100),
            factors: $breakdown,
        );
    }
}
