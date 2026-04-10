<?php

use Vistik\LaravelCodeAnalytics\Actions\GenerateMetricsReport;
use Vistik\LaravelCodeAnalytics\Actions\GenerateMetricsDetailsReport;

function metricsNode(string $path, ?int $cycleId = null): array
{
    return [
        'path' => $path,
        'status' => 'modified',
        'add' => 5,
        'del' => 2,
        'severity' => null,
        '_signal' => 10,
        'cycleId' => $cycleId,
        'cycleColor' => $cycleId !== null ? '#f0883e' : null,
        '_cycleBoost' => $cycleId !== null ? 100 : null,
        'veryHighCount' => 0, 'highCount' => 0, 'mediumCount' => 0, 'lowCount' => 0, 'infoCount' => 0, 'analysisCount' => 0,
    ];
}

function generateMetrics(array $nodes = []): string
{
    return (new GenerateMetricsReport)->generate(
        nodes: $nodes,
        edges: [],
        fileDiffs: [],
        analysisData: [],
        title: '',
        repo: 'test/repo',
        headCommit: 'abc1234',
        prAdditions: 0,
        prDeletions: 0,
        fileCount: count($nodes),
    );
}

function generateMetricsDetails(array $nodes = []): string
{
    return (new GenerateMetricsDetailsReport)->generate(
        nodes: $nodes,
        edges: [],
        fileDiffs: [],
        analysisData: [],
        title: '',
        repo: 'test/repo',
        headCommit: 'abc1234',
        prAdditions: 0,
        prDeletions: 0,
        fileCount: count($nodes),
    );
}

// ── GenerateMetricsReport ─────────────────────────────────────────────────────

test('circular deps line is absent when no cycles exist', function () {
    $output = generateMetrics([metricsNode('app/Foo.php'), metricsNode('app/Bar.php')]);

    expect($output)->not->toContain('Circular deps');
});

test('circular deps line is present when cycles exist', function () {
    $output = generateMetrics([metricsNode('app/Foo.php', cycleId: 1)]);

    expect($output)->toContain('Circular deps');
});

test('circular deps line shows correct cycle and file counts', function () {
    $output = generateMetrics([
        metricsNode('app/Foo.php', cycleId: 1),
        metricsNode('app/Bar.php', cycleId: 1),
        metricsNode('app/Baz.php', cycleId: 2),
    ]);

    expect($output)->toContain('2 cycle(s)  3 file(s)');
});

test('circular deps line counts unique cycles not total files', function () {
    $output = generateMetrics([
        metricsNode('app/A.php', cycleId: 1),
        metricsNode('app/B.php', cycleId: 1),
        metricsNode('app/C.php', cycleId: 1),
    ]);

    expect($output)->toContain('1 cycle(s)  3 file(s)');
});

// ── GenerateMetricsDetailsReport (inherits summary) ──────────────────────────

test('metrics details report also shows circular deps line', function () {
    $output = generateMetricsDetails([
        metricsNode('app/Foo.php', cycleId: 1),
        metricsNode('app/Bar.php', cycleId: 1),
    ]);

    expect($output)->toContain('Circular deps');
});

test('metrics details report omits circular deps line when no cycles', function () {
    $output = generateMetricsDetails([metricsNode('app/Foo.php')]);

    expect($output)->not->toContain('Circular deps');
});
