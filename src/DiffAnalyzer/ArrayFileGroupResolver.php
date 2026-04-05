<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts\FileGroupResolver;
use Vistik\LaravelCodeAnalytics\Enums\NodeGroup;

class ArrayFileGroupResolver implements FileGroupResolver
{
    /** @param array<string, list<string>> $groups */
    public function __construct(private readonly array $groups) {}

    public function resolve(string $path): NodeGroup
    {
        foreach ($this->groups as $group => $patterns) {
            foreach ($patterns as $pattern) {
                if ((bool) preg_match('#'.$pattern.'#', $path)) {
                    return NodeGroup::tryFrom($group) ?? NodeGroup::OTHER;
                }
            }
        }

        return NodeGroup::OTHER;
    }
}
