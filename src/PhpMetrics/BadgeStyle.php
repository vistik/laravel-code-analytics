<?php

namespace Vistik\LaravelCodeAnalytics\PhpMetrics;

enum BadgeStyle: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Clean = 'clean';

    public function color(): string
    {
        return match ($this) {
            self::Critical => '#f85149',
            self::Warning => '#d29922',
            self::Clean => '#3fb950',
        };
    }

    public function bgColor(): string
    {
        return match ($this) {
            self::Critical => '#3d1214',
            self::Warning => '#2d1c00',
            self::Clean => '#0d3520',
        };
    }

    public function borderColor(): string
    {
        return match ($this) {
            self::Critical => '#da3633',
            self::Warning => '#9e6a03',
            self::Clean => '#238636',
        };
    }

    public function label(int $hotspotCount = 0): string
    {
        return match ($this) {
            self::Clean => 'Clean',
            default => $hotspotCount.' '.($hotspotCount === 1 ? 'hotspot' : 'hotspots'),
        };
    }
}
