<?php

namespace Vistik\LaravelCodeAnalytics\PhpMetrics;

/**
 * Decides badge appearance based on the ratio of hotspot files to total files.
 *
 * - Critical (red)   : 50%+ of files are hotspots
 * - Warning (orange) : at least one hotspot, but under 50%
 * - Clean   (green)  : no hotspots
 */
class HotspotRatioBadgeDecider implements PhpMetricsBadgeDeciderInterface
{
    public function decide(int $hotspotCount, int $total): BadgeStyle
    {
        return match (true) {
            $hotspotCount >= (int) ceil($total * 0.5) => BadgeStyle::Critical,
            $hotspotCount > 0 => BadgeStyle::Warning,
            default => BadgeStyle::Clean,
        };
    }
}
