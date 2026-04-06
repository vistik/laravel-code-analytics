<?php

namespace Vistik\LaravelCodeAnalytics\Coupling;

class DependencyClusterer implements Clusterer
{
    /**
     * Find clusters of tightly connected nodes from a directed dependency graph
     * using weighted label propagation.
     *
     * Mutual dependencies (A→B and B→A) are weighted higher than one-way deps.
     * Only nodes that are changed files (not dependency-only nodes) are included
     * in the output clusters.
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
    ): array {
        if (empty($edges)) {
            return [];
        }

        // Build adjacency with weights: mutual deps = 3, one-way = 1
        $forward = [];
        foreach ($edges as [$source, $target]) {
            $forward[$source][$target] = true;
        }

        $neighbors = []; // nodeId => [neighborId => weight]
        foreach ($edges as [$source, $target]) {
            $isMutual = isset($forward[$target][$source]);
            $weight = $isMutual ? 3 : 1;

            $neighbors[$source][$target] = $weight;
            $neighbors[$target][$source] = $weight;
        }

        // Only keep nodes that are changed files
        $changedSet = array_flip($changedNodeIds);
        $nodeIds = array_keys(array_intersect_key($neighbors, $changedSet));

        if (count($nodeIds) < $minClusterSize) {
            return [];
        }

        // Label propagation: each node starts with its own label
        $labels = [];
        foreach ($nodeIds as $id) {
            $labels[$id] = $id;
        }

        // Iterate until stable (max 20 iterations to prevent infinite loops)
        for ($iter = 0; $iter < 20; $iter++) {
            $changed = false;
            // Shuffle order each iteration to avoid bias
            $shuffled = $nodeIds;
            shuffle($shuffled);

            foreach ($shuffled as $nodeId) {
                $nodeNeighbors = $neighbors[$nodeId] ?? [];

                // Sum weights per label among neighbors that are changed files
                $labelWeights = [];
                foreach ($nodeNeighbors as $neighborId => $weight) {
                    if (! isset($labels[$neighborId])) {
                        continue;
                    }
                    $neighborLabel = $labels[$neighborId];
                    $labelWeights[$neighborLabel] = ($labelWeights[$neighborLabel] ?? 0) + $weight;
                }

                if (empty($labelWeights)) {
                    continue;
                }

                // Pick the label with highest weight
                arsort($labelWeights);
                $bestLabel = array_key_first($labelWeights);

                if ($bestLabel !== $labels[$nodeId]) {
                    $labels[$nodeId] = $bestLabel;
                    $changed = true;
                }
            }

            if (! $changed) {
                break;
            }
        }

        // Group by label
        $groups = [];
        foreach ($labels as $nodeId => $label) {
            $groups[$label][] = $nodeId;
        }

        return collect($groups)
            ->map(fn (array $files) => ['files' => array_values($files), 'size' => count($files)])
            ->filter(fn (array $c) => $c['size'] >= $minClusterSize)
            ->sortByDesc('size')
            ->values()
            ->take($limit)
            ->all();
    }
}
