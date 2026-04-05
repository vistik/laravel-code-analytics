<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring;

class RiskData
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    public function __construct(
        public readonly array $nodes,
        public readonly int $additions,
        public readonly int $deletions,
        public readonly int $fileCount,
        public readonly int $phpHotSpots,
    ) {}
}
