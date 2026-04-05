<?php

namespace Vistik\LaravelCodeAnalytics\PhpMetrics;

enum MetricTrend: string
{
    case Improved = 'improved';
    case Worsened = 'worsened';
    case Unchanged = 'unchanged';

    public static function fromDegradation(float $score): self
    {
        return match (true) {
            $score < -0.1 => self::Improved,
            $score > 0.1 => self::Worsened,
            default => self::Unchanged,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Improved => '#3fb950',
            self::Worsened => '#f85149',
            self::Unchanged => '#6e7681',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Improved => '&#8593;',
            self::Worsened => '&#8595;',
            self::Unchanged => '&#8594;',
        };
    }
}
