<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring;

interface RiskScoring
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    public function calculate(array $nodes, int $additions, int $deletions, int $fileCount, int $phpHotSpots): RiskScore;
}
