<?php

namespace Vistik\LaravelCodeAnalytics\Actions\DependencyGraph;

final class FqcnNodeIndex
{
    /** @var array<string, string> FQCN → nodeId for diff files */
    public array $diffNodes = [];

    /** @var array<string, string> FQCN → nodeId for resolved connected nodes */
    public array $resolvedNodes = [];
}
