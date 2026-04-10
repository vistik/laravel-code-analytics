<?php

namespace Vistik\LaravelCodeAnalytics\Enums;

use Vistik\LaravelCodeAnalytics\Renderers\ForceGraphRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\GroupedRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\LayeredArchRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\LayeredCakeRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;
use Vistik\LaravelCodeAnalytics\Renderers\LayoutRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\TreeRenderer;

enum GraphLayout: string
{
    case Force = 'force';
    case Tree = 'tree';
    case Grouped = 'grouped';
    case Cake = 'cake';
    case Arch = 'arch';

    public function label(): string
    {
        return match ($this) {
            self::Force => 'Force',
            self::Tree => 'Tree',
            self::Grouped => 'Grouped',
            self::Cake => 'Cake',
            self::Arch => 'Architecture',
        };
    }

    public function renderer(?LayerStack $stack = null): LayoutRenderer
    {
        return match ($this) {
            self::Force => new ForceGraphRenderer,
            self::Tree => new TreeRenderer,
            self::Grouped => new GroupedRenderer,
            self::Cake => new LayeredCakeRenderer($stack),
            self::Arch => new LayeredArchRenderer($stack),
        };
    }
}
