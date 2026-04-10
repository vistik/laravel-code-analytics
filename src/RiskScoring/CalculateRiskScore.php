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

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $config = array_replace_recursive(self::defaults(), $config);

        $this->factors = [
            new ChangeSizeFactor($config['change_size']),
            new FileSpreadFactor($config['file_spread']),
            new DeletionRatioFactor($config['deletion_ratio']),
            new SeverityFindingsFactor($config['severity_findings']),
            new PhpMetricsFactor($config['php_metrics']),
        ];
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'change_size' => [
                'max_score' => 25,
                'thresholds' => [
                    ['lines' => 1000, 'score' => 25],
                    ['lines' => 500, 'score' => 15],
                    ['lines' => 200, 'score' => 10],
                    ['lines' => 50, 'score' => 5],
                ],
            ],
            'file_spread' => [
                'max_score' => 10,
                'thresholds' => [
                    ['files' => 30, 'score' => 10],
                    ['files' => 15, 'score' => 7],
                    ['files' => 8, 'score' => 4],
                    ['files' => 3, 'score' => 2],
                ],
            ],
            'deletion_ratio' => [
                'max_score' => 10,
                'thresholds' => [
                    ['ratio' => 0.8, 'score' => 10],
                    ['ratio' => 0.6, 'score' => 6],
                    ['ratio' => 0.4, 'score' => 3],
                ],
            ],
            'severity_findings' => [
                'max_score' => 40,
                'weights' => [
                    'very_high' => 10,
                    'high' => 6,
                    'medium' => 3,
                    'low' => 1,
                ],
            ],
            'php_metrics' => [
                'max_score' => 15,
                'thresholds' => [
                    ['hotspots' => 11, 'score' => 15],
                    ['hotspots' => 8, 'score' => 12],
                    ['hotspots' => 5, 'score' => 9],
                    ['hotspots' => 3, 'score' => 6],
                    ['hotspots' => 1, 'score' => 3],
                ],
            ],
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
