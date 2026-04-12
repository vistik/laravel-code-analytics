<?php

namespace Vistik\LaravelCodeAnalytics\Reports;

class FilterTogglesHtml
{
    public function __construct(
        public readonly string $ext,
        public readonly string $folder,
        public readonly string $severity = '',
    ) {}
}
