<?php

namespace Vistik\LaravelCodeAnalytics\Enums;

use Vistik\LaravelCodeAnalytics\Renderers\ForceGraphRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\GroupedRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\LayeredArchRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\LayeredCakeRenderer;
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

    /** @return class-string<LayoutRenderer> */
    public function renderer(): string
    {
        return match ($this) {
            self::Force => ForceGraphRenderer::class,
            self::Tree => TreeRenderer::class,
            self::Grouped => GroupedRenderer::class,
            self::Cake => LayeredCakeRenderer::class,
            self::Arch => LayeredArchRenderer::class,
        };
    }
}
