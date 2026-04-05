<?php

namespace Vistik\LaravelCodeAnalytics\PhpMetrics;

interface PhpMetricsBadgeDeciderInterface
{
    /**
     * Decide the badge appearance based on hotspot count vs total files.
     */
    public function decide(int $hotspotCount, int $total): BadgeStyle;
}
