<?php

namespace Vistik\LaravelCodeAnalytics\Reports;

use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

class GraphPayload
{
    public function __construct(
        public readonly array $nodes,
        public readonly array $edges,
        public readonly array $fileDiffs,
        public readonly array $analysisData,
        public readonly array $metricsData = [],
        public readonly array $fileContents = [],
        public readonly array $filterDefaults = [],
        public readonly ?RiskScore $riskScore = null,
    ) {}
}
