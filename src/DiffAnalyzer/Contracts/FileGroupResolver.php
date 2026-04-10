<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts;

use Vistik\LaravelCodeAnalytics\Enums\FileGroup;

interface FileGroupResolver
{
    public function resolve(string $path): FileGroup;
}
