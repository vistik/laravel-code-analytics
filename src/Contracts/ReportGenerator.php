<?php

namespace Vistik\LaravelCodeAnalytics\Contracts;

use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

interface ReportGenerator
{
    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array{0: string, 1: string}>  $edges
     * @param  array<string, string>  $fileDiffs
     * @param  array<string, list<array<string, mixed>>>  $analysisData
     * @param  array<string, array<string, mixed>>  $metricsData
     */
    public function generate(
        array $nodes,
        array $edges,
        array $fileDiffs,
        array $analysisData,
        string $title,
        string $repo,
        string $headCommit,
        int $prAdditions,
        int $prDeletions,
        int $fileCount,
        ?RiskScore $riskScore = null,
        array $metricsData = [],
    ): string;

    public function writeFile(string $outputPath, string $content): void;
}
