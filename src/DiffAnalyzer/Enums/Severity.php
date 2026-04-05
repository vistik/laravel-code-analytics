<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums;

enum Severity: string
{
    case INFO = 'info';
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case VERY_HIGH = 'very_high';

    public function score(): int
    {
        return match ($this) {
            self::INFO => 1,
            self::LOW => 3,
            self::MEDIUM => 5,
            self::HIGH => 7,
            self::VERY_HIGH => 10,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::INFO => 'Info',
            self::LOW => 'Low',
            self::MEDIUM => 'Medium',
            self::HIGH => 'High',
            self::VERY_HIGH => 'Very High',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::INFO => '#58a6ff',
            self::LOW => '#e3b341',
            self::MEDIUM => '#d29922',
            self::HIGH => '#ff7b72',
            self::VERY_HIGH => '#f85149',
        };
    }

    public function countKey(): string
    {
        return match ($this) {
            self::INFO => 'infoCount',
            self::LOW => 'lowCount',
            self::MEDIUM => 'mediumCount',
            self::HIGH => 'highCount',
            self::VERY_HIGH => 'veryHighCount',
        };
    }
}
