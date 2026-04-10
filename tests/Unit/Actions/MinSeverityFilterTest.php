<?php

use Vistik\LaravelCodeAnalytics\Actions\MinSeverityFilter;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

function makeNode(string $path, ?string $severity, int $add = 1, int $del = 0): array
{
    return [
        'path' => $path,
        'severity' => $severity,
        'add' => $add,
        'del' => $del,
        'analysisCount' => 0,
        'veryHighCount' => 0,
        'highCount' => 0,
        'mediumCount' => 0,
        'lowCount' => 0,
        'infoCount' => 0,
    ];
}

function makeSeverityReport(string $severity, string $description = 'desc'): array
{
    return ['category' => 'test', 'severity' => $severity, 'description' => $description];
}

function applyFilter(array $nodes, array $analysisData, Severity $min): array
{
    return (new MinSeverityFilter)->apply($nodes, $analysisData, [], [], $min);
}

// ── File-level inclusion ──────────────────────────────────────────────────────

it('keeps all files regardless of their max severity', function () {
    $nodes = [makeNode('app/High.php', 'high'), makeNode('app/Low.php', 'low')];
    $result = applyFilter($nodes, [], Severity::HIGH);

    expect($result['nodes'])->toHaveCount(2);
});

it('keeps files with no findings when min-severity is set', function () {
    $nodes = [makeNode('app/Foo.jsx', null)];
    $result = applyFilter($nodes, [], Severity::LOW);

    expect($result['nodes'])->toHaveCount(1);
});

// ── Per-report filtering within surviving files ───────────────────────────────

it('removes low-severity reports from files that pass the file-level filter', function () {
    $nodes = [makeNode('app/Foo.php', 'high')];
    $analysisData = [
        'app/Foo.php' => [
            makeSeverityReport('high', 'risky change'),
            makeSeverityReport('low', 'cosmetic'),
            makeSeverityReport('info', 'note'),
        ],
    ];

    $result = applyFilter($nodes, $analysisData, Severity::HIGH);

    expect($result['analysisData']['app/Foo.php'])->toHaveCount(1)
        ->and($result['analysisData']['app/Foo.php'][0]['description'])->toBe('risky change');
});

it('keeps reports at exactly the minimum severity', function () {
    $nodes = [makeNode('app/Foo.php', 'medium')];
    $analysisData = [
        'app/Foo.php' => [
            makeSeverityReport('medium', 'borderline'),
        ],
    ];

    $result = applyFilter($nodes, $analysisData, Severity::MEDIUM);

    expect($result['analysisData']['app/Foo.php'])->toHaveCount(1);
});

// ── Node count recalculation ──────────────────────────────────────────────────

it('recalculates analysisCount after filtering reports', function () {
    $nodes = [makeNode('app/Foo.php', 'high')];
    $analysisData = [
        'app/Foo.php' => [
            makeSeverityReport('high'),
            makeSeverityReport('medium'),
            makeSeverityReport('low'),
        ],
    ];

    $result = applyFilter($nodes, $analysisData, Severity::HIGH);

    expect($result['nodes'][0]['analysisCount'])->toBe(1);
});

it('recalculates per-severity counts correctly after filtering', function () {
    $nodes = [makeNode('app/Foo.php', 'very_high')];
    $analysisData = [
        'app/Foo.php' => [
            makeSeverityReport('very_high'),
            makeSeverityReport('high'),
            makeSeverityReport('medium'),
            makeSeverityReport('low'),
            makeSeverityReport('info'),
        ],
    ];

    $result = applyFilter($nodes, $analysisData, Severity::HIGH);
    $node = $result['nodes'][0];

    expect($node['veryHighCount'])->toBe(1)
        ->and($node['highCount'])->toBe(1)
        ->and($node['mediumCount'])->toBe(0)
        ->and($node['lowCount'])->toBe(0)
        ->and($node['infoCount'])->toBe(0)
        ->and($node['analysisCount'])->toBe(2);
});

// ── analysisData / metricsData / fileDiffs scoping ───────────────────────────

it('filters analysisData findings but keeps all files in the result', function () {
    $nodes = [
        makeNode('app/High.php', 'high'),
        makeNode('app/Low.php', 'low'),
    ];
    $analysisData = [
        'app/High.php' => [makeSeverityReport('high')],
        'app/Low.php' => [makeSeverityReport('low')],
    ];

    $result = applyFilter($nodes, $analysisData, Severity::HIGH);

    expect($result['nodes'])->toHaveCount(2)
        ->and($result['analysisData'])->toHaveKey('app/High.php')
        ->and($result['analysisData']['app/High.php'])->toHaveCount(1)
        ->and($result['analysisData']['app/Low.php'])->toHaveCount(0);
});

it('preserves metricsData and fileDiffs for all files', function () {
    $nodes = [makeNode('app/Low.php', 'low')];
    $filter = new MinSeverityFilter;
    $result = $filter->apply(
        $nodes,
        [],
        ['app/Low.php' => ['cc' => 5]],
        ['app/Low.php' => 'diff content'],
        Severity::HIGH,
    );

    expect($result['metricsData'])->toHaveKey('app/Low.php')
        ->and($result['fileDiffs'])->toHaveKey('app/Low.php');
});
