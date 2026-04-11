<?php

use Vistik\LaravelCodeAnalytics\Actions\GenerateMdReport;

function mdNode(string $path, ?int $cycleId = null, int $signal = 10): array
{
    return [
        'path' => $path,
        'status' => 'modified',
        'add' => 5,
        'del' => 2,
        'severity' => null,
        '_signal' => $signal,
        'cycleId' => $cycleId,
        'cycleColor' => $cycleId !== null ? '#f0883e' : null,
        '_cycleBoost' => $cycleId !== null ? 100 : null,
        'veryHighCount' => 0, 'highCount' => 0, 'mediumCount' => 0, 'lowCount' => 0, 'infoCount' => 0, 'analysisCount' => 0,
    ];
}

function generateMd(array $nodes = []): string
{
    return (new GenerateMdReport)->generate(
        nodes: $nodes,
        edges: [],
        fileDiffs: [],
        analysisData: [],
        title: 'Test PR',
        repo: 'test/repo',
        headCommit: 'abc1234',
        prAdditions: 0,
        prDeletions: 0,
        fileCount: count($nodes),
    );
}

// ── Files table ───────────────────────────────────────────────────────────────

test('files table has a Cycle column header', function () {
    expect(generateMd())->toContain('| Cycle |');
});

test('cycle column shows cycle number for files in a cycle', function () {
    $md = generateMd([mdNode('app/Foo.php', cycleId: 1)]);

    expect($md)->toContain('| ↻ 1 |');
});

test('cycle column shows dash for files not in a cycle', function () {
    $md = generateMd([mdNode('app/Bar.php')]);

    expect($md)->toContain('| — |');
});

// ── Circular Dependencies section ─────────────────────────────────────────────

test('circular dependencies section is absent when no cycles exist', function () {
    $md = generateMd([mdNode('app/Foo.php'), mdNode('app/Bar.php')]);

    expect($md)->not->toContain('## Circular Dependencies');
});

test('circular dependencies section is present when cycles exist', function () {
    $md = generateMd([mdNode('app/Foo.php', cycleId: 1)]);

    expect($md)->toContain('## Circular Dependencies');
});

test('circular dependencies section lists cycle group header', function () {
    $md = generateMd([
        mdNode('app/Foo.php', cycleId: 1),
        mdNode('app/Bar.php', cycleId: 1),
    ]);

    expect($md)->toContain('**Cycle 1** (2 files)');
});

test('circular dependencies section lists each file in the cycle', function () {
    $md = generateMd([
        mdNode('app/Foo.php', cycleId: 1),
        mdNode('app/Bar.php', cycleId: 1),
    ]);

    expect($md)
        ->toContain('- `app/Foo.php`')
        ->toContain('- `app/Bar.php`');
});

test('circular dependencies section shows multiple cycle groups', function () {
    $md = generateMd([
        mdNode('app/A.php', cycleId: 1),
        mdNode('app/B.php', cycleId: 2),
    ]);

    expect($md)
        ->toContain('**Cycle 1**')
        ->toContain('**Cycle 2**');
});

test('non-cycle files do not appear in circular dependencies section', function () {
    $md = generateMd([
        mdNode('app/Cycle.php', cycleId: 1),
        mdNode('app/Clean.php'),
    ]);

    // Section exists
    expect($md)->toContain('## Circular Dependencies');

    // Only the cycle file is listed under the section
    $section = substr($md, strpos($md, '## Circular Dependencies'));
    expect($section)->not->toContain('app/Clean.php');
});
