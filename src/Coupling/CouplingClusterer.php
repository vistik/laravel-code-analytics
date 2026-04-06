<?php

namespace Vistik\LaravelCodeAnalytics\Coupling;

class CouplingClusterer
{
    /**
     * Group files that change together into clusters using Union-Find.
     *
     * @param  array<array{file_a: string, file_b: string}>  $pairs
     * @return array<int, array{files: list<string>, size: int}>
     */
    public function cluster(array $pairs, int $minClusterSize = 3, int $limit = 10): array
    {
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

        foreach ($pairs as $pair) {
            $rootA = $find($pair['file_a']);
            $rootB = $find($pair['file_b']);
            if ($rootA !== $rootB) {
                $parent[$rootA] = $rootB;
            }
        }

        $clusters = [];
        foreach (array_keys($parent) as $file) {
            $clusters[$find($file)][] = $file;
        }

        return collect($clusters)
            ->map(fn (array $files) => ['files' => array_values($files), 'size' => count($files)])
            ->filter(fn (array $c) => $c['size'] >= $minClusterSize)
            ->sortByDesc('size')
            ->values()
            ->take($limit)
            ->all();
    }
}
