<?php

namespace Vistik\LaravelCodeAnalytics\FileSignal;

interface FileSignalScoring
{
    /**
     * @param  array<string, mixed>  $node       The file node (must include 'add', 'del', 'watchLevel')
     * @param  list<array<string, mixed>>  $findings  Analysis findings for this file
     * @param  array<string, mixed>|null  $metrics   PhpMetrics data for this file (cc, mi, ...)
     */
    public function calculate(array $node, array $findings, ?array $metrics): int;
}
