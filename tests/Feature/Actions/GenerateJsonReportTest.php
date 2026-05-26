<?php

use Vistik\LaravelCodeAnalytics\Actions\GenerateJsonReport;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;

function makeJsonNode(string $path, ?int $cycleId = null, int $signal = 10, ?int $cycleBoost = null, ?string $severity = null, ?int $connectionBoost = null, ?int $connections = null, ?int $clusterId = null, ?int $clusterSize = null): array
{
    return [
        'path' => $path,
        'status' => 'modified',
        'add' => 5,
        'del' => 2,
        'severity' => $severity ?? ($cycleId !== null ? 'very_high' : null),
        '_signal' => $signal,
        'cycleId' => $cycleId,
        'cycleColor' => $cycleId !== null ? '#f0883e' : null,
        '_cycleBoost' => $cycleBoost,
        '_connectionBoost' => $connectionBoost,
        '_connections' => $connections,
        'clusterId' => $clusterId,
        'clusterSize' => $clusterSize,
        'veryHighCount' => 0, 'highCount' => 0, 'mediumCount' => 0, 'lowCount' => 0, 'infoCount' => 0, 'analysisCount' => 0,
    ];
}

function generateJson(array $nodes = [], array $edges = []): array
{
    $json = (new GenerateJsonReport)->generate(
        payload: new GraphPayload(nodes: $nodes, edges: $edges, fileDiffs: [], analysisData: []),
        pr: new PullRequestContext(prTitle: 'Test PR', repo: 'test/repo', headCommit: 'abc1234', prAdditions: 0, prDeletions: 0, fileCount: count($nodes)),
    );

    return json_decode($json, true);
}

// ── Files array ───────────────────────────────────────────────────────────────

test('file entry includes cycle_id when node is in a cycle', function () {
    $data = generateJson([makeJsonNode('app/Foo.php', cycleId: 1, signal: 120, cycleBoost: 110)]);

    expect($data['files'][0]['cycle_id'])->toBe(1);
});

test('file entry cycle_id is null when node is not in a cycle', function () {
    $data = generateJson([makeJsonNode('app/Bar.php')]);

    expect($data['files'][0]['cycle_id'])->toBeNull();
});

test('file entry includes cycle_boost when node is in a cycle', function () {
    $data = generateJson([makeJsonNode('app/Foo.php', cycleId: 1, signal: 120, cycleBoost: 110)]);

    expect($data['files'][0]['cycle_boost'])->toBe(110);
});

test('file entry cycle_boost is null when node is not in a cycle', function () {
    $data = generateJson([makeJsonNode('app/Bar.php')]);

    expect($data['files'][0]['cycle_boost'])->toBeNull();
});

// ── circular_dependencies section ─────────────────────────────────────────────

test('circular_dependencies is empty array when no cycles exist', function () {
    $data = generateJson([makeJsonNode('app/Foo.php'), makeJsonNode('app/Bar.php')]);

    expect($data['circular_dependencies'])->toBe([]);
});

test('circular_dependencies lists files grouped by cycle', function () {
    $data = generateJson([
        makeJsonNode('app/Foo.php', cycleId: 1),
        makeJsonNode('app/Bar.php', cycleId: 1),
        makeJsonNode('app/Baz.php', cycleId: 2),
        makeJsonNode('app/Clean.php'),
    ]);

    expect($data['circular_dependencies'])->toHaveCount(2);
    expect($data['circular_dependencies'][0]['files'])->toContain('app/Foo.php');
    expect($data['circular_dependencies'][0]['files'])->toContain('app/Bar.php');
    expect($data['circular_dependencies'][1]['files'])->toContain('app/Baz.php');
});

test('circular_dependencies does not include non-cycle files', function () {
    $data = generateJson([
        makeJsonNode('app/Foo.php', cycleId: 1),
        makeJsonNode('app/Clean.php'),
    ]);

    $allFiles = array_merge(...array_column($data['circular_dependencies'], 'files'));
    expect($allFiles)->not->toContain('app/Clean.php');
});

test('circular_dependencies groups are ordered by cycle id', function () {
    $data = generateJson([
        makeJsonNode('app/C.php', cycleId: 3),
        makeJsonNode('app/A.php', cycleId: 1),
        makeJsonNode('app/B.php', cycleId: 2),
    ]);

    expect($data['circular_dependencies'][0]['files'])->toContain('app/A.php');
    expect($data['circular_dependencies'][1]['files'])->toContain('app/B.php');
    expect($data['circular_dependencies'][2]['files'])->toContain('app/C.php');
});

// ── Severity ──────────────────────────────────────────────────────────────────

test('file entry severity is very_high for cycle files', function () {
    $data = generateJson([makeJsonNode('app/Foo.php', cycleId: 1)]);

    expect($data['files'][0]['severity'])->toBe('very_high');
});

test('file entry severity is null for non-cycle files with no findings', function () {
    $data = generateJson([makeJsonNode('app/Bar.php')]);

    expect($data['files'][0]['severity'])->toBeNull();
});

// ── connection_boost field ────────────────────────────────────────────────────

test('file entry connection_boost is null when node has no connection boost', function () {
    $data = generateJson([makeJsonNode('app/Bar.php')]);

    expect($data['files'][0]['connection_boost'])->toBeNull();
});

test('file entry connection_boost reflects the stored boost value', function () {
    $data = generateJson([makeJsonNode('app/Foo.php', connectionBoost: 15, connections: 3)]);

    expect($data['files'][0]['connection_boost'])->toBe(15);
});

test('files are sorted by signal descending when connection boosts differ', function () {
    $data = generateJson([
        makeJsonNode('app/Low.php', signal: 10),
        makeJsonNode('app/High.php', signal: 25, connectionBoost: 15, connections: 3),
        makeJsonNode('app/Mid.php', signal: 20, connectionBoost: 5, connections: 1),
    ]);

    expect($data['files'][0]['path'])->toBe('app/High.php')
        ->and($data['files'][1]['path'])->toBe('app/Mid.php')
        ->and($data['files'][2]['path'])->toBe('app/Low.php');
});

// ── cluster_id / cluster_size fields ─────────────────────────────────────────

test('file entry includes cluster_id when node is in a cluster', function () {
    $data = generateJson([makeJsonNode('app/Foo.php', clusterId: 2, clusterSize: 5)]);

    expect($data['files'][0]['cluster_id'])->toBe(2);
});

test('file entry cluster_id is null when node has no cluster', function () {
    $data = generateJson([makeJsonNode('app/Bar.php')]);

    expect($data['files'][0]['cluster_id'])->toBeNull();
});

test('file entry includes cluster_size when node is in a cluster', function () {
    $data = generateJson([makeJsonNode('app/Foo.php', clusterId: 2, clusterSize: 5)]);

    expect($data['files'][0]['cluster_size'])->toBe(5);
});

test('file entry cluster_size is null when node has no cluster', function () {
    $data = generateJson([makeJsonNode('app/Bar.php')]);

    expect($data['files'][0]['cluster_size'])->toBeNull();
});

// ── review_clusters section ───────────────────────────────────────────────────

test('review_clusters is empty array when no clusters exist', function () {
    $data = generateJson([makeJsonNode('app/Foo.php'), makeJsonNode('app/Bar.php')]);

    expect($data['review_clusters'])->toBe([]);
});

test('review_clusters lists files grouped by cluster', function () {
    $data = generateJson([
        makeJsonNode('app/Foo.php', clusterId: 1, clusterSize: 2),
        makeJsonNode('app/Bar.php', clusterId: 1, clusterSize: 2),
        makeJsonNode('app/Baz.php', clusterId: 2, clusterSize: 1),
        makeJsonNode('app/Clean.php'),
    ]);

    expect($data['review_clusters'])->toHaveCount(2);
    expect($data['review_clusters'][0]['files'])->toContain('app/Foo.php');
    expect($data['review_clusters'][0]['files'])->toContain('app/Bar.php');
    expect($data['review_clusters'][1]['files'])->toContain('app/Baz.php');
});

test('review_clusters entries include cluster_id and size', function () {
    $data = generateJson([
        makeJsonNode('app/Foo.php', clusterId: 3, clusterSize: 4),
        makeJsonNode('app/Bar.php', clusterId: 3, clusterSize: 4),
    ]);

    expect($data['review_clusters'][0]['cluster_id'])->toBe(3);
    expect($data['review_clusters'][0]['size'])->toBe(2);
});

test('review_clusters does not include non-cluster files', function () {
    $data = generateJson([
        makeJsonNode('app/Foo.php', clusterId: 1, clusterSize: 1),
        makeJsonNode('app/Clean.php'),
    ]);

    $allFiles = array_merge(...array_column($data['review_clusters'], 'files'));
    expect($allFiles)->not->toContain('app/Clean.php');
});

test('review_clusters groups are ordered by cluster id', function () {
    $data = generateJson([
        makeJsonNode('app/C.php', clusterId: 3, clusterSize: 1),
        makeJsonNode('app/A.php', clusterId: 1, clusterSize: 1),
        makeJsonNode('app/B.php', clusterId: 2, clusterSize: 1),
    ]);

    expect($data['review_clusters'][0]['cluster_id'])->toBe(1);
    expect($data['review_clusters'][1]['cluster_id'])->toBe(2);
    expect($data['review_clusters'][2]['cluster_id'])->toBe(3);
});
