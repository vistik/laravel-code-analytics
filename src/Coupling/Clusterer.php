<?php

namespace Vistik\LaravelCodeAnalytics\Coupling;

interface Clusterer
{
    /**
     * Find clusters of related nodes from a directed dependency graph.
     *
     * @param  list<array{0: string, 1: string}>  $edges  Directed edges [source, target] using node IDs
     * @param  list<string>  $changedNodeIds  Node IDs of files in the diff (filters output)
     * @return array<int, array{files: list<string>, size: int}>
     */
    public function cluster(
        array $edges,
        array $changedNodeIds,
        int $minClusterSize = 3,
        int $limit = 10,
    ): array;
}
