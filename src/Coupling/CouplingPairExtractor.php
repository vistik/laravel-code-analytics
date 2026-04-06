<?php

namespace Vistik\LaravelCodeAnalytics\Coupling;

class CouplingPairExtractor
{
    /**
     * Extract file pairs that co-change from commit data.
     *
     * @param  array<string, list<string>>  $commitFiles  commit_id => list of file paths
     * @return array<int, array{file_a: string, file_b: string, co_change_count: int}>
     */
    public function extract(array $commitFiles, int $minCoChanges = 2): array
    {
        $pairCounts = [];

        foreach ($commitFiles as $files) {
            $count = count($files);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $files[$i];
                    $b = $files[$j];
                    if ($a > $b) {
                        [$a, $b] = [$b, $a];
                    }
                    $key = $a."\0".$b;
                    $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1;
                }
            }
        }

        $pairs = [];
        foreach ($pairCounts as $key => $count) {
            if ($count < $minCoChanges) {
                continue;
            }
            [$fileA, $fileB] = explode("\0", $key, 2);
            $pairs[] = ['file_a' => $fileA, 'file_b' => $fileB, 'co_change_count' => $count];
        }

        usort($pairs, fn (array $a, array $b) => $b['co_change_count'] <=> $a['co_change_count']);

        return $pairs;
    }
}
