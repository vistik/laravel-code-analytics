<?php

use Vistik\LaravelCodeAnalytics\Actions\GenerateJsonReport;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;

function makeJsonNode(string $path, ?int $cycleId = null, int $signal = 10, ?int $cycleBoost = null, ?string $severity = null, ?int $connectionBoost = null, ?int $connections = null): array
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
        'veryHighCount' => 0, 'highCount' => 0, 'mediumCount' => 0, 'lowCount' => 0, 'infoCount' => 0, 'analysisCount' => 0,
    ];
}

function generateJson(array $nodes = [], array $edges = [], array $metricsData = []): array
{
    $json = (new GenerateJsonReport)->generate(
        payload: new GraphPayload(nodes: $nodes, edges: $edges, fileDiffs: [], analysisData: [], metricsData: $metricsData),
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

// ── Metrics section ───────────────────────────────────────────────────────────

test('metrics entry includes cc, mi, bugs, coupling, lloc, methods, and flog', function () {
    $data = generateJson(metricsData: [
        'app/Foo.php' => [
            'cc' => 8,
            'mi' => 72.5,
            'bugs' => 0.042,
            'coupling' => 3,
            'lloc' => 120,
            'methods' => 5,
            'flog' => 14.3,
        ],
    ]);

    $m = $data['metrics'][0];
    expect($m['cc'])->toBe(8)
        ->and($m['mi'])->toBe(72.5)
        ->and($m['bugs'])->toBe(0.042)
        ->and($m['coupling'])->toBe(3)
        ->and($m['lloc'])->toBe(120)
        ->and($m['methods'])->toBe(5)
        ->and($m['flog'])->toBe(14.3);
});

test('metrics entry includes method_metrics with cc, lloc, params, and flog per method', function () {
    $data = generateJson(metricsData: [
        'app/Foo.php' => [
            'cc' => 5,
            'method_metrics' => [
                ['name' => 'handle', 'line' => 10, 'cc' => 3, 'lloc' => 20, 'params' => 2, 'flog' => 4.5],
                ['name' => 'boot', 'line' => 35, 'cc' => 1, 'lloc' => 5, 'params' => 0, 'flog' => 1.0],
            ],
        ],
    ]);

    $methods = $data['metrics'][0]['method_metrics'];
    expect($methods)->toHaveCount(2)
        ->and($methods[0]['name'])->toBe('handle')
        ->and($methods[0]['cc'])->toBe(3)
        ->and($methods[0]['lloc'])->toBe(20)
        ->and($methods[0]['params'])->toBe(2)
        ->and($methods[0]['flog'])->toBe(4.5);
});

test('metrics entry includes before with full metrics when file was modified', function () {
    $data = generateJson(metricsData: [
        'app/Foo.php' => [
            'cc' => 8,
            'mi' => 72.5,
            'bugs' => 0.042,
            'coupling' => 3,
            'lloc' => 120,
            'methods' => 5,
            'flog' => 14.3,
            'before' => [
                'cc' => 6,
                'mi' => 80.0,
                'bugs' => 0.020,
                'coupling' => 2,
                'lloc' => 100,
                'methods' => 4,
                'flog' => 10.1,
            ],
        ],
    ]);

    $before = $data['metrics'][0]['before'];
    expect($before['cc'])->toBe(6)
        ->and((float) $before['mi'])->toBe(80.0)
        ->and($before['bugs'])->toBe(0.020)
        ->and($before['coupling'])->toBe(2)
        ->and($before['lloc'])->toBe(100)
        ->and($before['methods'])->toBe(4)
        ->and($before['flog'])->toBe(10.1);
});

test('metrics entry includes before_method_metrics when file was modified', function () {
    $data = generateJson(metricsData: [
        'app/Foo.php' => [
            'cc' => 5,
            'before_method_metrics' => [
                ['name' => 'handle', 'line' => 10, 'cc' => 2, 'lloc' => 15, 'params' => 2, 'flog' => 3.0],
            ],
        ],
    ]);

    $before = $data['metrics'][0]['before_method_metrics'];
    expect($before)->toHaveCount(1)
        ->and($before[0]['name'])->toBe('handle')
        ->and($before[0]['cc'])->toBe(2)
        ->and((float) $before[0]['flog'])->toBe(3.0);
});

test('metrics entry omits before and before_method_metrics when not present', function () {
    $data = generateJson(metricsData: [
        'app/Foo.php' => ['cc' => 5, 'lloc' => 50],
    ]);

    expect($data['metrics'][0])->not->toHaveKey('before')
        ->and($data['metrics'][0])->not->toHaveKey('before_method_metrics');
});
