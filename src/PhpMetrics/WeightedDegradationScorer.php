<?php

namespace Vistik\LaravelCodeAnalytics\PhpMetrics;

/**
 * Scores files by weighted metric degradation.
 *
 * Each metric delta is multiplied by a weight that reflects how harmful
 * an increase (or decrease, for MI) is in practice. A positive score
 * means the file got worse; negative means it improved.
 */
class WeightedDegradationScorer implements PhpMetricsScorerInterface
{
    public function degradationScore(FileMetrics $metrics, ?FileMetrics $before): float
    {
        if ($before === null) {
            return 0.0;
        }

        $cc = ($metrics->cc ?? 0) - ($before->cc ?? 0);
        $mi = ($metrics->mi ?? 0) - ($before->mi ?? 0);
        $bugs = ($metrics->bugs ?? 0.0) - ($before->bugs ?? 0.0);
        $coupling = ($metrics->coupling ?? 0) - ($before->coupling ?? 0);

        return $cc * 3
            + (-$mi) * 0.5
            + $bugs * 100
            + $coupling * 2;
    }

    public function isHotspot(FileMetrics $metrics): bool
    {
        return $this->countBadMetrics($metrics) > 0;
    }

    public function countBadMetrics(FileMetrics $metrics): int
    {
        return (int) (($metrics->cc ?? 0) > 10)
            + (int) (($metrics->mi ?? 100) < 85)
            + (int) (($metrics->bugs ?? 0) > 0.1)
            + (int) (($metrics->coupling ?? 0) > 15);
    }
}
