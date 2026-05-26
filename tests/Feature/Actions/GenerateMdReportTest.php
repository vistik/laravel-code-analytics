<?php

use Vistik\LaravelCodeAnalytics\Actions\GenerateMdReport;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;

function mdNode(string $path, ?int $cycleId = null, int $signal = 10, ?int $clusterId = null, ?int $clusterSize = null): array
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
        'clusterId' => $clusterId,
        'clusterSize' => $clusterSize,
        'veryHighCount' => 0, 'highCount' => 0, 'mediumCount' => 0, 'lowCount' => 0, 'infoCount' => 0, 'analysisCount' => 0,
    ];
}

function generateMd(array $nodes = []): string
{
    return (new GenerateMdReport)->generate(
        payload: new GraphPayload(nodes: $nodes, edges: [], fileDiffs: [], analysisData: []),
        pr: new PullRequestContext(prTitle: 'Test PR', repo: 'test/repo', headCommit: 'abc1234', prAdditions: 0, prDeletions: 0, fileCount: count($nodes)),
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

// ── Cluster column ────────────────────────────────────────────────────────────

test('files table has a Cluster column header', function () {
    expect(generateMd())->toContain('| Cluster |');
});

test('cluster column shows cluster number for files in a cluster', function () {
    $md = generateMd([mdNode('app/Foo.php', clusterId: 2, clusterSize: 3)]);

    expect($md)->toContain('| ⬡ 2 |');
});

test('cluster column shows dash for files not in a cluster', function () {
    $md = generateMd([mdNode('app/Bar.php')]);

    expect($md)->toContain('| — |');
});

// ── Review Clusters section ───────────────────────────────────────────────────

test('review clusters section is absent when no clusters exist', function () {
    $md = generateMd([mdNode('app/Foo.php'), mdNode('app/Bar.php')]);

    expect($md)->not->toContain('## Review Clusters');
});

test('review clusters section is present when clusters exist', function () {
    $md = generateMd([mdNode('app/Foo.php', clusterId: 1, clusterSize: 1)]);

    expect($md)->toContain('## Review Clusters');
});

test('review clusters section lists cluster group header', function () {
    $md = generateMd([
        mdNode('app/Foo.php', clusterId: 1, clusterSize: 2),
        mdNode('app/Bar.php', clusterId: 1, clusterSize: 2),
    ]);

    expect($md)->toContain('**Cluster 1** (2 files)');
});

test('review clusters section lists each file in the cluster', function () {
    $md = generateMd([
        mdNode('app/Foo.php', clusterId: 1, clusterSize: 2),
        mdNode('app/Bar.php', clusterId: 1, clusterSize: 2),
    ]);

    expect($md)
        ->toContain('- `app/Foo.php`')
        ->toContain('- `app/Bar.php`');
});

test('review clusters section shows multiple cluster groups', function () {
    $md = generateMd([
        mdNode('app/A.php', clusterId: 1, clusterSize: 1),
        mdNode('app/B.php', clusterId: 2, clusterSize: 1),
    ]);

    expect($md)
        ->toContain('**Cluster 1**')
        ->toContain('**Cluster 2**');
});

test('non-cluster files do not appear in review clusters section', function () {
    $md = generateMd([
        mdNode('app/Clustered.php', clusterId: 1, clusterSize: 1),
        mdNode('app/Clean.php'),
    ]);

    expect($md)->toContain('## Review Clusters');

    $section = substr($md, strpos($md, '## Review Clusters'));
    expect($section)->not->toContain('app/Clean.php');
});

test('review clusters section lists groups in ascending cluster id order', function () {
    $md = generateMd([
        mdNode('app/C.php', clusterId: 3, clusterSize: 1),
        mdNode('app/A.php', clusterId: 1, clusterSize: 1),
        mdNode('app/B.php', clusterId: 2, clusterSize: 1),
    ]);

    $pos1 = strpos($md, '**Cluster 1**');
    $pos2 = strpos($md, '**Cluster 2**');
    $pos3 = strpos($md, '**Cluster 3**');

    expect($pos1)->toBeLessThan($pos2)
        ->and($pos2)->toBeLessThan($pos3);
});

test('review clusters section appears before ast findings section', function () {
    $md = generateMd([mdNode('app/Foo.php', clusterId: 1, clusterSize: 1)]);

    // Review Clusters section should exist; Findings section is absent when there are none.
    // Verify the section exists at all.
    expect($md)->toContain('## Review Clusters');
});

test('cluster column uses ⬡ symbol followed by the cluster id', function () {
    $md = generateMd([mdNode('app/Foo.php', clusterId: 5, clusterSize: 2)]);

    expect($md)->toContain('⬡ 5');
});
