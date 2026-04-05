<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts;

use Vistik\LaravelCodeAnalytics\Enums\NodeGroup;

interface FileGroupResolver
{
    public function resolve(string $path): NodeGroup;
}
