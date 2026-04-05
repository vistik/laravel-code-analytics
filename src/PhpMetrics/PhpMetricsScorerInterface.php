<?php

namespace Vistik\LaravelCodeAnalytics\PhpMetrics;

interface PhpMetricsScorerInterface
{
    /**
     * Compute a degradation score for sorting purposes (higher = more degraded).
     */
    public function degradationScore(FileMetrics $metrics, ?FileMetrics $before): float;

    /**
     * Determine whether the file's current metrics constitute a hotspot.
     */
    public function isHotspot(FileMetrics $metrics): bool;

    /**
     * Count how many individual metrics exceed their threshold.
     */
    public function countBadMetrics(FileMetrics $metrics): int;
}
