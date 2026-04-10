<?php

namespace Vistik\LaravelCodeAnalytics\Renderers;

use Vistik\LaravelCodeAnalytics\Enums\FileGroup;

readonly class CakeLayer
{
    /**
     * @param  FileGroup[]  $groups
     */
    public function __construct(
        public string $label,
        public string $color,
        public array $groups,
    ) {}
}
