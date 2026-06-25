<?php

use Vistik\LaravelCodeAnalytics\Actions\GenerateJsonReport;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;

function makeJsonNode(string $path, ?int $cycleId = null, int $signal = 10, ?int $cycleBoost = null, ?string $severity = null, ?int $connectionBoost = null, ?int $connections = null, ?int $clusterId = null, ?int $clusterSize = null, ?int $baseSignal = null): array
{
    return [
        'id' => $path,
        'path' => $path,
        'status' => 'modified',
        'add' => 5,
        'del' => 2,
        'severity' => $severity ?? ($cycleId !== null ? 'very_high' : null),
        '_signal' => $signal,
        '_baseSignal' => $baseSignal,
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

// ── base_signal field ─────────────────────────────────────────────────────────

test('file entry base_signal reflects the stored base signal value', function () {
    $data = generateJson([makeJsonNode('app/Foo.php', signal: 120, cycleBoost: 110, baseSignal: 10)]);

    expect($data['files'][0]['base_signal'])->toBe(10);
});

test('file entry base_signal is null when not set', function () {
    $data = generateJson([makeJsonNode('app/Bar.php')]);

    expect($data['files'][0]['base_signal'])->toBeNull();
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

// ── graph_index ───────────────────────────────────────────────────────────────

function generateJsonFull(array $nodes = [], array $edges = [], array $metricsData = [], array $fileDiffs = [], array $fileContents = []): array
{
    $json = (new GenerateJsonReport)->generate(
        payload: new GraphPayload(
            nodes: $nodes,
            edges: $edges,
            fileDiffs: $fileDiffs,
            analysisData: [],
            metricsData: $metricsData,
            fileContents: $fileContents,
        ),
        pr: new PullRequestContext(prTitle: 'Test PR', repo: 'test/repo', headCommit: 'abc1234', prAdditions: 0, prDeletions: 0, fileCount: count($nodes)),
    );

    return json_decode($json, true);
}

test('graph_index key is present in json output', function () {
    $data = generateJsonFull();

    expect($data)->toHaveKey('graph_index');
});

test('graph_index contains expected sub-keys', function () {
    $data = generateJsonFull();

    expect($data['graph_index'])->toHaveKeys(['classNameIndex', 'methodNameIndex', 'callersIndex', 'implementorsIndex', 'implementeeIndex']);
});

test('classNameIndex maps uppercase php basename to node id', function () {
    $nodes = [
        makeJsonNode('app/Services/OrderService.php'),
    ];

    $data = generateJsonFull(nodes: $nodes);

    expect($data['graph_index']['classNameIndex'])->toHaveKey('OrderService')
        ->and($data['graph_index']['classNameIndex']['OrderService'])->toBe('app/Services/OrderService.php');
});

test('classNameIndex excludes lowercase-starting php files', function () {
    $nodes = [
        makeJsonNode('app/helpers.php'),
    ];

    $data = generateJsonFull(nodes: $nodes);

    expect($data['graph_index']['classNameIndex'])->not->toHaveKey('helpers');
});

test('classNameIndex excludes non-php files', function () {
    $nodes = [
        makeJsonNode('resources/views/index.blade.php'),
    ];

    $data = generateJsonFull(nodes: $nodes);

    // blade.php files have a basename of "index" after pathinfo — not UpperCamelCase, so excluded
    expect($data['graph_index']['classNameIndex'])->not->toHaveKey('index');
});

test('methodNameIndex maps method name from metrics data to file node id', function () {
    $nodes = [
        makeJsonNode('app/Services/PaymentService.php'),
    ];
    $metricsData = [
        'app/Services/PaymentService.php' => [
            'method_metrics' => [
                ['name' => 'processPayment', 'cc' => 3, 'lloc' => 10],
                ['name' => 'refund', 'cc' => 2, 'lloc' => 5],
            ],
        ],
    ];

    $data = generateJsonFull(nodes: $nodes, metricsData: $metricsData);

    expect($data['graph_index']['methodNameIndex'])->toHaveKey('processPayment')
        ->and($data['graph_index']['methodNameIndex']['processPayment'])->toBe('app/Services/PaymentService.php')
        ->and($data['graph_index']['methodNameIndex'])->toHaveKey('refund');
});

test('implementorsIndex maps interface node id to its implementors', function () {
    $nodes = [
        makeJsonNode('app/Contracts/PaymentGateway.php'),
        makeJsonNode('app/Services/StripeGateway.php'),
    ];
    $edges = [
        ['app/Services/StripeGateway.php', 'app/Contracts/PaymentGateway.php', 'implements'],
    ];

    $data = generateJsonFull(nodes: $nodes, edges: $edges);

    $implementors = $data['graph_index']['implementorsIndex'];
    expect($implementors)->toHaveKey('app/Contracts/PaymentGateway.php');
    expect(array_column($implementors['app/Contracts/PaymentGateway.php'], 'nodeId'))
        ->toContain('app/Services/StripeGateway.php');
});

test('implementeeIndex maps concrete class node id to its interfaces', function () {
    $nodes = [
        makeJsonNode('app/Contracts/PaymentGateway.php'),
        makeJsonNode('app/Services/StripeGateway.php'),
    ];
    $edges = [
        ['app/Services/StripeGateway.php', 'app/Contracts/PaymentGateway.php', 'implements'],
    ];

    $data = generateJsonFull(nodes: $nodes, edges: $edges);

    $implementee = $data['graph_index']['implementeeIndex'];
    expect($implementee)->toHaveKey('app/Services/StripeGateway.php');
    expect($implementee['app/Services/StripeGateway.php'])->toContain('app/Contracts/PaymentGateway.php');
});

test('callersIndex captures static method calls from file contents', function () {
    $nodes = [
        makeJsonNode('app/Services/OrderService.php'),
        makeJsonNode('app/Http/Controllers/OrderController.php'),
    ];
    $fileContents = [
        'app/Http/Controllers/OrderController.php' => '<?php class OrderController { public function store() { OrderService::create($data); } }',
    ];
    $fileDiffs = [
        'app/Http/Controllers/OrderController.php' => '+    OrderService::create($data);',
    ];

    $data = generateJsonFull(nodes: $nodes, fileDiffs: $fileDiffs, fileContents: $fileContents);

    $callersIndex = $data['graph_index']['callersIndex'];
    $key = 'app/Services/OrderService.php:create';
    expect($callersIndex)->toHaveKey($key);
    expect(array_column($callersIndex[$key], 'nodeId'))->toContain('app/Http/Controllers/OrderController.php');
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

test('metrics entry includes class_metrics with aggregate per-class stats', function () {
    $data = generateJson(metricsData: [
        'app/Foo.php' => [
            'cc' => 5,
            'class_metrics' => [
                ['name' => 'Foo', 'kind' => 'class', 'line' => 3, 'methods' => 2, 'wmc' => 7, 'cc_avg' => 3.5, 'max_cc' => 5, 'lloc' => 40],
            ],
        ],
    ]);

    $classes = $data['metrics'][0]['class_metrics'];
    expect($classes)->toHaveCount(1)
        ->and($classes[0]['name'])->toBe('Foo')
        ->and($classes[0]['kind'])->toBe('class')
        ->and($classes[0]['line'])->toBe(3)
        ->and($classes[0]['methods'])->toBe(2)
        ->and($classes[0]['wmc'])->toBe(7)
        ->and($classes[0]['cc_avg'])->toBe(3.5)
        ->and($classes[0]['max_cc'])->toBe(5)
        ->and($classes[0]['lloc'])->toBe(40);
});

test('metrics entry includes before_class_metrics when file was modified', function () {
    $data = generateJson(metricsData: [
        'app/Foo.php' => [
            'cc' => 5,
            'before_class_metrics' => [
                ['name' => 'Foo', 'kind' => 'class', 'line' => 3, 'methods' => 1, 'wmc' => 3, 'cc_avg' => 3.0, 'max_cc' => 3, 'lloc' => 25],
            ],
        ],
    ]);

    $before = $data['metrics'][0]['before_class_metrics'];
    expect($before)->toHaveCount(1)
        ->and($before[0]['name'])->toBe('Foo')
        ->and($before[0]['wmc'])->toBe(3);
});

test('metrics entry omits class_metrics when not present', function () {
    $data = generateJson(metricsData: [
        'app/Foo.php' => ['cc' => 5, 'lloc' => 50],
    ]);

    expect($data['metrics'][0])->not->toHaveKey('class_metrics')
        ->and($data['metrics'][0])->not->toHaveKey('before_class_metrics');
});
