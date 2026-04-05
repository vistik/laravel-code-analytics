<?php

namespace Vistik\LaravelCodeAnalytics\Renderers;

use Vistik\LaravelCodeAnalytics\Enums\NodeGroup;

readonly class CakeLayer
{
    /**
     * @param  NodeGroup[]  $groups
     */
    public function __construct(
        public string $label,
        public string $color,
        public array $groups,
    ) {}
}
