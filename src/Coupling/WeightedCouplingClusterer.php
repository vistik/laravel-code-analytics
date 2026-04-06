<?php

namespace Vistik\LaravelCodeAnalytics\Coupling;

class WeightedCouplingClusterer implements Clusterer
{
    private const DIRECT_DEP_WEIGHT = 2;

    private const MUTUAL_DEP_WEIGHT = 5;

    private const SHARED_DEP_WEIGHT = 1;

    /**
     * Find clusters by scoring pairwise coupling strength between changed files.
     *
     * Scoring per pair:
     *  - Direct dependency (A→B or B→A): +2
     *  - Mutual dependency (A→B AND B→A): +5 (replaces the +2)
     *  - Each shared dependency (A→C and B→C): +1
     *
     * Pairs scoring above the threshold are merged using Union-Find.
     *
     * @param  list<array{0: string, 1: string}>  $edges
     * @param  list<string>  $changedNodeIds
     * @return array<int, array{files: list<string>, size: int}>
     */
    public function cluster(
        array $edges,
        array $changedNodeIds,
        int $minClusterSize = 3,
        int $limit = 10,
    ): array {
        if (empty($edges) || count($changedNodeIds) < $minClusterSize) {
            return [];
        }

        $changedSet = array_flip($changedNodeIds);

        // Build outgoing adjacency: node → [targets]
        $outgoing = [];
        foreach ($edges as [$source, $target]) {
            $outgoing[$source][$target] = true;
        }

        // Score every pair of changed files
        $pairScores = [];

        for ($i = 0, $count = count($changedNodeIds); $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $changedNodeIds[$i];
                $b = $changedNodeIds[$j];

                $score = $this->scorePair($a, $b, $outgoing);
                if ($score > 0) {
                    $pairScores[] = ['a' => $a, 'b' => $b, 'score' => $score];
                }
            }
        }

        if (empty($pairScores)) {
            return [];
        }

        // Use the median score as threshold — only keep stronger-than-average pairs
        $scores = array_column($pairScores, 'score');
        sort($scores);
        $median = $scores[(int) floor(count($scores) / 2)];
        $threshold = max(self::DIRECT_DEP_WEIGHT, $median);

        // Union-Find on pairs above threshold
        $parent = [];

        $find = function (string $x) use (&$parent, &$find): string {
            if (! isset($parent[$x])) {
                $parent[$x] = $x;
            }
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }

            return $parent[$x];
        };

        foreach ($pairScores as $pair) {
            if ($pair['score'] < $threshold) {
                continue;
            }

            $rootA = $find($pair['a']);
            $rootB = $find($pair['b']);
            if ($rootA !== $rootB) {
                $parent[$rootA] = $rootB;
            }
        }

        // Collect clusters
        $groups = [];
        foreach (array_keys($parent) as $node) {
            if (! isset($changedSet[$node])) {
                continue;
            }
            $groups[$find($node)][] = $node;
        }

        return collect($groups)
            ->map(fn (array $files) => ['files' => array_values($files), 'size' => count($files)])
            ->filter(fn (array $c) => $c['size'] >= $minClusterSize)
            ->sortByDesc('size')
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * @param  array<string, array<string, true>>  $outgoing
     */
    private function scorePair(string $a, string $b, array $outgoing): int
    {
        $aToB = isset($outgoing[$a][$b]);
        $bToA = isset($outgoing[$b][$a]);

        $score = 0;

        if ($aToB && $bToA) {
            $score += self::MUTUAL_DEP_WEIGHT;
        } elseif ($aToB || $bToA) {
            $score += self::DIRECT_DEP_WEIGHT;
        }

        // Shared dependencies: nodes that both A and B point to
        $aDeps = $outgoing[$a] ?? [];
        $bDeps = $outgoing[$b] ?? [];
        if (! empty($aDeps) && ! empty($bDeps)) {
            $shared = count(array_intersect_key($aDeps, $bDeps));
            $score += $shared * self::SHARED_DEP_WEIGHT;
        }

        return $score;
    }
}
