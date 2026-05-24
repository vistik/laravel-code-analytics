<?php

namespace Vistik\LaravelCodeAnalytics\Actions\DependencyGraph;

use Vistik\LaravelCodeAnalytics\Support\PhpDependencyExtractor;

final class DependencyGraph
{
    /** @var array<string, string> path → nodeId */
    public array $pathToNode = [];

    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $edges = [];

    /** @var array<string, array> nodeId → node */
    public array $connectedNodes = [];

    /** @var array<string, true> */
    private array $edgeSet = [];

    public function addEdge(string $sourceId, string $targetId, string $type = PhpDependencyExtractor::USE): void
    {
        if ($sourceId === $targetId) {
            return;
        }

        $key = "{$sourceId}->{$targetId}";
        if (isset($this->edgeSet[$key])) {
            return;
        }

        $this->edges[] = [$sourceId, $targetId, $type];
        $this->edgeSet[$key] = true;
    }

    public function nodeIdForPath(string $path): ?string
    {
        return $this->pathToNode[$path] ?? null;
    }

    public function registerConnectedNode(array $node): string
    {
        $this->connectedNodes[$node['id']] = $node;
        $this->pathToNode[$node['path']] = $node['id'];

        return $node['id'];
    }
}
