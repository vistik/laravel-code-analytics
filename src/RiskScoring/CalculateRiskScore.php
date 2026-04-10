<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring;

// TEMP: fake cycle for testing
use Vistik\LaravelCodeAnalytics\RiskScoring\Factors\ChangeSizeFactor;
use Vistik\LaravelCodeAnalytics\RiskScoring\Factors\DeletionRatioFactor;
use Vistik\LaravelCodeAnalytics\RiskScoring\Factors\FileSpreadFactor;
use Vistik\LaravelCodeAnalytics\RiskScoring\Factors\PhpMetricsFactor;
use Vistik\LaravelCodeAnalytics\RiskScoring\Factors\SeverityFindingsFactor;

class CalculateRiskScore implements RiskScoring
{
    /** @var list<RiskFactor> */
    private array $factors;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $config = array_replace_recursive(config('laravel-code-analytics.risk_scoring', []), $config);

        $this->factors = [
            new ChangeSizeFactor($config['change_size']),
            new FileSpreadFactor($config['file_spread']),
            new DeletionRatioFactor($config['deletion_ratio']),
            new SeverityFindingsFactor($config['severity_findings']),
            new PhpMetricsFactor($config['php_metrics']),
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
