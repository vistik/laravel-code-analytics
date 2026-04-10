<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts\FileGroupResolver;
use Vistik\LaravelCodeAnalytics\Enums\FileGroup;

class ArrayFileGroupResolver implements FileGroupResolver
{
    /** @param array<string, list<string>> $groups */
    public function __construct(private readonly array $groups) {}

    public function resolve(string $path): FileGroup
    {
        foreach ($this->groups as $group => $patterns) {
            foreach ($patterns as $pattern) {
                if ((bool) preg_match('#'.$pattern.'#', $path)) {
                    return FileGroup::tryFrom($group) ?? FileGroup::OTHER;
                }
            }
        }

        return FileGroup::OTHER;
    }
}
