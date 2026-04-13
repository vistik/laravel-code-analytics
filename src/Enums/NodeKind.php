<?php

namespace Vistik\LaravelCodeAnalytics\Enums;

enum NodeKind: string
{
    case CLASS_KIND = 'class';
    case ABSTRACT = 'abstract';
    case INTERFACE = 'interface';
    case TRAIT = 'trait';
    case ENUM = 'enum';
    case TYPE = 'type';

    public function label(): string
    {
        return match ($this) {
            self::CLASS_KIND => 'Class',
            self::ABSTRACT   => 'Abstract',
            self::INTERFACE  => 'Interface',
            self::TRAIT      => 'Trait',
            self::ENUM       => 'Enum',
            self::TYPE       => 'Type',
        };
    }

    public function letter(): string
    {
        return match ($this) {
            self::CLASS_KIND => 'C',
            self::ABSTRACT   => 'A',
            self::INTERFACE  => 'I',
            self::TRAIT      => 'T',
            self::ENUM       => 'E',
            self::TYPE       => 'T',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CLASS_KIND => '#388bfd',
            self::ABSTRACT   => '#79c0ff',
            self::INTERFACE  => '#bc8cff',
            self::TRAIT      => '#f0883e',
            self::ENUM       => '#3fb950',
            self::TYPE       => '#d2a8ff',
        };
    }
}
