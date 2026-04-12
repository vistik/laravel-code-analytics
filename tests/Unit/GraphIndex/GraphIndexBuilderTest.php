<?php

use Vistik\LaravelCodeAnalytics\GraphIndex\GraphIndexBuilder;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeGraphNode(string $path, ?string $name = null, bool $hasCode = false): array
{
    $id = $name ?? basename($path, '.php');

    return array_filter([
        'id' => $id,
        'path' => $path,
        'ext' => pathinfo($path, PATHINFO_EXTENSION),
        'name' => $name,
        'code' => $hasCode ? '' : null,
    ], fn ($v) => $v !== null);
}

// ── buildClassNameIndex ───────────────────────────────────────────────────────

test('maps UpperCamelCase PHP basename to node id', function () {
    $nodes = [makeGraphNode('app/Services/OrderService.php', 'order-service-node')];
    $index = (new GraphIndexBuilder)->buildClassNameIndex($nodes);

    expect($index)->toBe(['OrderService' => 'order-service-node']);
});

test('ignores non-PHP files', function () {
    $nodes = [makeGraphNode('resources/js/app.js', 'app-js')];
    $index = (new GraphIndexBuilder)->buildClassNameIndex($nodes);

    expect($index)->toBeEmpty();
});

test('ignores lowercase PHP file basenames', function () {
    $nodes = [makeGraphNode('app/helpers.php', 'helpers')];
    $index = (new GraphIndexBuilder)->buildClassNameIndex($nodes);

    expect($index)->toBeEmpty();
});

test('first match wins on duplicate class basenames', function () {
    $nodes = [
        makeGraphNode('app/Foo.php', 'node-a'),
        makeGraphNode('other/Foo.php', 'node-b'),
    ];
    $index = (new GraphIndexBuilder)->buildClassNameIndex($nodes);

    expect($index['Foo'])->toBe('node-a');
});

// ── buildMethodNameIndex ──────────────────────────────────────────────────────

test('indexes method names from code nodes', function () {
    $nodes = [
        ['id' => 'OrderService::handle', 'path' => 'app/OrderService.php', 'name' => 'handle', 'code' => ''],
    ];
    $index = (new GraphIndexBuilder)->buildMethodNameIndex($nodes);

    expect($index)->toBe(['handle' => 'OrderService::handle']);
});

test('indexes method names from metrics method_metrics', function () {
    $nodes = [makeGraphNode('app/OrderService.php', 'OrderService')];
    $metricsData = [
        'app/OrderService.php' => [
            'method_metrics' => [
                ['name' => 'handle', 'line' => 10],
                ['name' => 'validate', 'line' => 25],
            ],
        ],
    ];
    $index = (new GraphIndexBuilder)->buildMethodNameIndex($nodes, $metricsData);

    expect($index)->toHaveKey('handle')
        ->and($index['handle'])->toBe('OrderService')
        ->and($index)->toHaveKey('validate');
});

test('code node wins over metrics when method name is the same', function () {
    $nodes = [
        ['id' => 'OrderService::handle', 'path' => 'app/OrderService.php', 'name' => 'handle', 'code' => ''],
        makeGraphNode('app/PaymentService.php', 'PaymentService'),
    ];
    $metricsData = [
        'app/PaymentService.php' => [
            'method_metrics' => [['name' => 'handle', 'line' => 5]],
        ],
    ];
    $index = (new GraphIndexBuilder)->buildMethodNameIndex($nodes, $metricsData);

    // code node registered first, so it wins
    expect($index['handle'])->toBe('OrderService::handle');
});

// ── buildCallersIndex ─────────────────────────────────────────────────────────

test('detects static call and records caller', function () {
    $nodes = [
        makeGraphNode('app/OrderService.php', 'OrderService'),
        makeGraphNode('app/PaymentGateway.php', 'PaymentGateway'),
    ];
    $classNameIndex = ['PaymentGateway' => 'PaymentGateway'];
    $fileDiffs = ['app/OrderService.php' => '+ PaymentGateway::charge($amount);'];

    $index = (new GraphIndexBuilder)->buildCallersIndex($nodes, $classNameIndex, $fileDiffs);

    expect($index)->toHaveKey('PaymentGateway:charge')
        ->and($index['PaymentGateway:charge'][0]['nodeId'])->toBe('OrderService');
});

test('detects instance call via type-hint resolution', function () {
    $nodes = [
        makeGraphNode('app/OrderService.php', 'OrderService'),
        makeGraphNode('app/PaymentGateway.php', 'PaymentGateway'),
    ];
    $classNameIndex = ['PaymentGateway' => 'PaymentGateway'];
    $diff = implode("\n", [
        '+ private PaymentGateway $gateway;',
        '+ $this->gateway->charge($amount);',
    ]);
    $fileDiffs = ['app/OrderService.php' => $diff];

    $index = (new GraphIndexBuilder)->buildCallersIndex($nodes, $classNameIndex, $fileDiffs);

    expect($index)->toHaveKey('PaymentGateway:charge')
        ->and($index['PaymentGateway:charge'][0]['nodeId'])->toBe('OrderService');
});

test('does not record self-calls', function () {
    $nodes = [makeGraphNode('app/OrderService.php', 'OrderService')];
    $classNameIndex = ['OrderService' => 'OrderService'];
    $fileDiffs = ['app/OrderService.php' => '+ OrderService::staticHelper();'];

    $index = (new GraphIndexBuilder)->buildCallersIndex($nodes, $classNameIndex, $fileDiffs);

    expect($index)->toBeEmpty();
});

test('deduplicates when same caller appears multiple times', function () {
    $nodes = [
        makeGraphNode('app/OrderService.php', 'OrderService'),
        makeGraphNode('app/PaymentGateway.php', 'PaymentGateway'),
    ];
    $classNameIndex = ['PaymentGateway' => 'PaymentGateway'];
    $diff = implode("\n", [
        '+ PaymentGateway::charge($a);',
        '+ PaymentGateway::charge($b);',
    ]);
    $fileDiffs = ['app/OrderService.php' => $diff];

    $index = (new GraphIndexBuilder)->buildCallersIndex($nodes, $classNameIndex, $fileDiffs);

    expect($index['PaymentGateway:charge'])->toHaveCount(1);
});

test('skips nodes without a diff entry', function () {
    $nodes = [makeGraphNode('app/OrderService.php', 'OrderService')];
    $classNameIndex = [];

    $index = (new GraphIndexBuilder)->buildCallersIndex($nodes, $classNameIndex, fileDiffs: []);

    expect($index)->toBeEmpty();
});

test('records line number from full file content', function () {
    $nodes = [
        makeGraphNode('app/OrderService.php', 'OrderService'),
        makeGraphNode('app/PaymentGateway.php', 'PaymentGateway'),
    ];
    $classNameIndex = ['PaymentGateway' => 'PaymentGateway'];
    $fileDiffs = ['app/OrderService.php' => ''];       // presence signals it's a changed file
    $fileContents = [
        'app/OrderService.php' => "<?php\nclass OrderService {\n    public function charge(): void { PaymentGateway::charge(); }\n}",
    ];

    $index = (new GraphIndexBuilder)->buildCallersIndex($nodes, $classNameIndex, $fileDiffs, $fileContents);

    $entry = $index['PaymentGateway:charge'][0] ?? null;
    expect($entry)->not->toBeNull()
        ->and($entry['line'])->toBe(3);
});

test('line number is null when reconstructed from diff', function () {
    $nodes = [
        makeGraphNode('app/OrderService.php', 'OrderService'),
        makeGraphNode('app/PaymentGateway.php', 'PaymentGateway'),
    ];
    $classNameIndex = ['PaymentGateway' => 'PaymentGateway'];
    $fileDiffs = ['app/OrderService.php' => '+ PaymentGateway::charge();'];

    $index = (new GraphIndexBuilder)->buildCallersIndex($nodes, $classNameIndex, $fileDiffs);

    expect($index['PaymentGateway:charge'][0]['line'])->toBeNull();
});

// ── buildImplementsIndices ────────────────────────────────────────────────────

test('builds implementors index from implements edges', function () {
    $nodes = [
        makeGraphNode('app/LoggerInterface.php', 'LoggerInterface'),
        makeGraphNode('app/FileLogger.php', 'FileLogger'),
        makeGraphNode('app/DbLogger.php', 'DbLogger'),
    ];
    $edges = [
        ['FileLogger', 'LoggerInterface', 'implements'],
        ['DbLogger', 'LoggerInterface', 'implements'],
    ];

    [$implementorsIndex] = (new GraphIndexBuilder)->buildImplementsIndices($nodes, $edges);

    expect($implementorsIndex)->toHaveKey('LoggerInterface')
        ->and(array_column($implementorsIndex['LoggerInterface'], 'nodeId'))
        ->toContain('FileLogger')
        ->toContain('DbLogger');
});

test('builds implementee index from implements edges', function () {
    $nodes = [
        makeGraphNode('app/LoggerInterface.php', 'LoggerInterface'),
        makeGraphNode('app/FileLogger.php', 'FileLogger'),
    ];
    $edges = [['FileLogger', 'LoggerInterface', 'implements']];

    [, $implementeeIndex] = (new GraphIndexBuilder)->buildImplementsIndices($nodes, $edges);

    expect($implementeeIndex)->toHaveKey('FileLogger')
        ->and($implementeeIndex['FileLogger'])->toContain('LoggerInterface');
});

test('ignores non-implements edges', function () {
    $nodes = [
        makeGraphNode('app/OrderService.php', 'OrderService'),
        makeGraphNode('app/PaymentGateway.php', 'PaymentGateway'),
    ];
    $edges = [['OrderService', 'PaymentGateway', 'use']];

    [$implementorsIndex, $implementeeIndex] = (new GraphIndexBuilder)->buildImplementsIndices($nodes, $edges);

    expect($implementorsIndex)->toBeEmpty()
        ->and($implementeeIndex)->toBeEmpty();
});

test('ignores edges referencing unknown node ids', function () {
    $nodes = [makeGraphNode('app/Foo.php', 'Foo')];
    $edges = [['Foo', 'UnknownInterface', 'implements']];

    [$implementorsIndex] = (new GraphIndexBuilder)->buildImplementsIndices($nodes, $edges);

    expect($implementorsIndex)->toBeEmpty();
});

test('deduplicates implementors', function () {
    $nodes = [
        makeGraphNode('app/LoggerInterface.php', 'LoggerInterface'),
        makeGraphNode('app/FileLogger.php', 'FileLogger'),
    ];
    $edges = [
        ['FileLogger', 'LoggerInterface', 'implements'],
        ['FileLogger', 'LoggerInterface', 'implements'],
    ];

    [$implementorsIndex] = (new GraphIndexBuilder)->buildImplementsIndices($nodes, $edges);

    expect($implementorsIndex['LoggerInterface'])->toHaveCount(1);
});

// ── build (integration) ───────────────────────────────────────────────────────

test('build returns all five index keys', function () {
    $result = (new GraphIndexBuilder)->build(nodes: [], edges: []);

    expect($result)->toHaveKeys(['methodNameIndex', 'classNameIndex', 'callersIndex', 'implementorsIndex', 'implementeeIndex']);
});
