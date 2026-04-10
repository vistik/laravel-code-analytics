<?php

use Vistik\LaravelCodeAnalytics\Actions\GenerateJsonReport;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

function makeJsonNode(string $path, ?int $cycleId = null, int $signal = 10, ?int $cycleBoost = null, ?string $severity = null): array
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
        'veryHighCount' => 0, 'highCount' => 0, 'mediumCount' => 0, 'lowCount' => 0, 'infoCount' => 0, 'analysisCount' => 0,
    ];
}

function generateJson(array $nodes = [], array $edges = []): array
{
    $json = (new GenerateJsonReport)->generate(
        nodes: $nodes,
        edges: $edges,
        fileDiffs: [],
        analysisData: [],
        title: 'Test PR',
        repo: 'test/repo',
        headCommit: 'abc1234',
        prAdditions: 0,
        prDeletions: 0,
        fileCount: count($nodes),
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
