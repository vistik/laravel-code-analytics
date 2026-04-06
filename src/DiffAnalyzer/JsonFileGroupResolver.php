<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer;

use InvalidArgumentException;
use RuntimeException;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts\FileGroupResolver;
use Vistik\LaravelCodeAnalytics\Enums\NodeGroup;

class JsonFileGroupResolver implements FileGroupResolver
{
    /** @var array<string, list<string>> */
    private array $groups;

    public function __construct(string $path)
    {
        if (! file_exists($path)) {
            throw new RuntimeException("Group resolver file not found: {$path}");
        }

        $decoded = json_decode(file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Invalid JSON in group resolver file: {$path}");
        }

        $this->groups = $decoded;
    }

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
