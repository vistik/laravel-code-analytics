<?php

use Vistik\LaravelCodeAnalytics\ScheduledJobs\AffectedScheduledJob;
use Vistik\LaravelCodeAnalytics\ScheduledJobs\AffectedScheduledJobResolver;
use Vistik\LaravelCodeAnalytics\ScheduledJobs\ScheduledJobDefinition;

function makeJobIndex(string $handlerPath, string $fqcn = 'App\\Jobs\\MyJob', string $expr = '->daily()'): array
{
    return [$handlerPath => [new ScheduledJobDefinition($fqcn, $expr)]];
}

function makeNodes(array $specs): array
{
    // specs: [ ['id' => ..., 'path' => ..., 'isConnected' => ?bool], ... ]
    return $specs;
}

function makeEdge(string $from, string $to): array
{
    return [$from, $to];
}

// ── Basic resolution ──────────────────────────────────────────────────────────

it('returns empty when job index is empty', function () {
    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: [],
        edges: [],
        nodeIdToPath: ['node-a' => 'app/Services/Foo.php'],
        diffNodes: [['id' => 'node-a', 'path' => 'app/Services/Foo.php']],
    );

    expect($result)->toBeEmpty();
});

it('returns empty when no diff nodes provided', function () {
    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: makeJobIndex('app/Jobs/MyJob.php'),
        edges: [],
        nodeIdToPath: ['node-job' => 'app/Jobs/MyJob.php'],
        diffNodes: [],
    );

    expect($result)->toBeEmpty();
});

it('detects directly changed handler file as affected', function () {
    $index = makeJobIndex('app/Jobs/MyJob.php');

    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: $index,
        edges: [],
        nodeIdToPath: ['node-job' => 'app/Jobs/MyJob.php'],
        diffNodes: [['id' => 'node-job', 'path' => 'app/Jobs/MyJob.php']],
    );

    expect($result)->toHaveCount(1);
    expect($result[0])->toBeInstanceOf(AffectedScheduledJob::class);
    expect($result[0]->job->handlerFqcn)->toBe('App\\Jobs\\MyJob');
    expect($result[0]->triggeredByPath)->toBe('app/Jobs/MyJob.php');
    expect($result[0]->dependencyChain)->toBe(['app/Jobs/MyJob.php']);
});

it('detects indirectly changed file via reverse edge', function () {
    // Service → Job (Job depends on Service)
    // Changing Service should flag the Job's schedule entry
    $index = makeJobIndex('app/Jobs/MyJob.php');

    $edges = [
        makeEdge('node-job', 'node-service'), // Job depends on Service
    ];

    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: $index,
        edges: $edges,
        nodeIdToPath: [
            'node-job' => 'app/Jobs/MyJob.php',
            'node-service' => 'app/Services/MyService.php',
        ],
        diffNodes: [['id' => 'node-service', 'path' => 'app/Services/MyService.php']],
    );

    expect($result)->toHaveCount(1);
    expect($result[0]->triggeredByPath)->toBe('app/Services/MyService.php');
    expect($result[0]->dependencyChain)->toBe(['app/Services/MyService.php', 'app/Jobs/MyJob.php']);
});

// ── Deduplication ─────────────────────────────────────────────────────────────

it('deduplicates when two diff nodes reach the same job', function () {
    $index = makeJobIndex('app/Jobs/MyJob.php');

    $edges = [
        makeEdge('node-job', 'node-service-a'),
        makeEdge('node-job', 'node-service-b'),
    ];

    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: $index,
        edges: $edges,
        nodeIdToPath: [
            'node-job' => 'app/Jobs/MyJob.php',
            'node-service-a' => 'app/Services/ServiceA.php',
            'node-service-b' => 'app/Services/ServiceB.php',
        ],
        diffNodes: [
            ['id' => 'node-service-a', 'path' => 'app/Services/ServiceA.php'],
            ['id' => 'node-service-b', 'path' => 'app/Services/ServiceB.php'],
        ],
    );

    expect($result)->toHaveCount(1);
});

it('deduplicates multiple schedule entries with same fqcn and expression', function () {
    // Same job registered twice in the index (shouldn't happen but resolver handles it)
    $index = [
        'app/Jobs/MyJob.php' => [
            new ScheduledJobDefinition('App\\Jobs\\MyJob', '->daily()'),
            new ScheduledJobDefinition('App\\Jobs\\MyJob', '->daily()'), // duplicate
        ],
    ];

    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: $index,
        edges: [],
        nodeIdToPath: ['node-job' => 'app/Jobs/MyJob.php'],
        diffNodes: [['id' => 'node-job', 'path' => 'app/Jobs/MyJob.php']],
    );

    // Both have same key so only one survives
    expect($result)->toHaveCount(1);
});

it('does NOT deduplicate same handler with different schedule expressions', function () {
    $index = [
        'app/Jobs/MyJob.php' => [
            new ScheduledJobDefinition('App\\Jobs\\MyJob', '->daily()'),
            new ScheduledJobDefinition('App\\Jobs\\MyJob', '->weekly()'),
        ],
    ];

    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: $index,
        edges: [],
        nodeIdToPath: ['node-job' => 'app/Jobs/MyJob.php'],
        diffNodes: [['id' => 'node-job', 'path' => 'app/Jobs/MyJob.php']],
    );

    expect($result)->toHaveCount(2);
});

// ── Depth limit ───────────────────────────────────────────────────────────────

it('does not traverse beyond maxDepth', function () {
    // Chain: diff → A → B → C → D → E → job (6 hops, exceeds default maxDepth=5)
    $index = makeJobIndex('app/Jobs/DeepJob.php');

    $edges = [
        makeEdge('node-job', 'node-e'),
        makeEdge('node-e', 'node-d'),
        makeEdge('node-d', 'node-c'),
        makeEdge('node-c', 'node-b'),
        makeEdge('node-b', 'node-a'),
        makeEdge('node-a', 'node-diff'),
    ];

    $nodeIdToPath = [
        'node-job' => 'app/Jobs/DeepJob.php',
        'node-e' => 'app/E.php',
        'node-d' => 'app/D.php',
        'node-c' => 'app/C.php',
        'node-b' => 'app/B.php',
        'node-a' => 'app/A.php',
        'node-diff' => 'app/Diff.php',
    ];

    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: $index,
        edges: $edges,
        nodeIdToPath: $nodeIdToPath,
        diffNodes: [['id' => 'node-diff', 'path' => 'app/Diff.php']],
    );

    // 6 hops exceeds maxDepth=5, so job should NOT be found
    expect($result)->toBeEmpty();
});

it('traverses exactly at maxDepth boundary (chain length 5)', function () {
    // Chain: diff → A → B → C → job (4 hops = chain length 5, exactly at the limit)
    // The BFS stops adding children when chain.length >= 5, so the job (visited with chain 5) IS found.
    $index = makeJobIndex('app/Jobs/DeepJob.php');

    $edges = [
        makeEdge('node-job', 'node-c'),
        makeEdge('node-c', 'node-b'),
        makeEdge('node-b', 'node-a'),
        makeEdge('node-a', 'node-diff'),
    ];

    $nodeIdToPath = [
        'node-job' => 'app/Jobs/DeepJob.php',
        'node-c' => 'app/C.php',
        'node-b' => 'app/B.php',
        'node-a' => 'app/A.php',
        'node-diff' => 'app/Diff.php',
    ];

    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: $index,
        edges: $edges,
        nodeIdToPath: $nodeIdToPath,
        diffNodes: [['id' => 'node-diff', 'path' => 'app/Diff.php']],
    );

    expect($result)->toHaveCount(1);
});

// ── toArray() serialization ───────────────────────────────────────────────────

it('toArray() includes all expected keys', function () {
    $index = makeJobIndex('app/Jobs/MyJob.php');

    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: $index,
        edges: [],
        nodeIdToPath: ['node-job' => 'app/Jobs/MyJob.php'],
        diffNodes: [['id' => 'node-job', 'path' => 'app/Jobs/MyJob.php']],
    );

    $arr = $result[0]->toArray();

    expect($arr)->toHaveKeys(['handlerFqcn', 'scheduleExpression', 'triggeredByPath', 'dependencyChain', 'jobNodeId', 'reachableNodeIds', 'reachableDepths']);
    expect($arr['handlerFqcn'])->toBe('App\\Jobs\\MyJob');
    expect($arr['scheduleExpression'])->toBe('->daily()');
    expect($arr['jobNodeId'])->toBe('node-job');
});

// ── Reachable nodes (forward BFS) ─────────────────────────────────────────────

it('computes reachableNodeIds from the job handler outward', function () {
    $index = makeJobIndex('app/Jobs/MyJob.php');

    // Job depends on ServiceA and ServiceB
    $edges = [
        makeEdge('node-job', 'node-service-a'),
        makeEdge('node-job', 'node-service-b'),
    ];

    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: $index,
        edges: $edges,
        nodeIdToPath: [
            'node-job' => 'app/Jobs/MyJob.php',
            'node-service-a' => 'app/Services/ServiceA.php',
            'node-service-b' => 'app/Services/ServiceB.php',
        ],
        diffNodes: [['id' => 'node-job', 'path' => 'app/Jobs/MyJob.php']],
    );

    expect($result[0]->reachableNodeIds)->toContain('node-job');
    expect($result[0]->reachableNodeIds)->toContain('node-service-a');
    expect($result[0]->reachableNodeIds)->toContain('node-service-b');
});

// ── Sorting ───────────────────────────────────────────────────────────────────

it('sorts results alphabetically by handlerFqcn', function () {
    $index = [
        'app/Jobs/ZJob.php' => [new ScheduledJobDefinition('App\\Jobs\\ZJob', '->daily()')],
        'app/Jobs/AJob.php' => [new ScheduledJobDefinition('App\\Jobs\\AJob', '->daily()')],
    ];

    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: $index,
        edges: [],
        nodeIdToPath: [
            'node-z' => 'app/Jobs/ZJob.php',
            'node-a' => 'app/Jobs/AJob.php',
        ],
        diffNodes: [
            ['id' => 'node-z', 'path' => 'app/Jobs/ZJob.php'],
            ['id' => 'node-a', 'path' => 'app/Jobs/AJob.php'],
        ],
    );

    expect($result[0]->job->handlerFqcn)->toBe('App\\Jobs\\AJob');
    expect($result[1]->job->handlerFqcn)->toBe('App\\Jobs\\ZJob');
});

// ── Skips nodes with empty path ───────────────────────────────────────────────

it('skips diff nodes with missing path', function () {
    $index = makeJobIndex('app/Jobs/MyJob.php');

    $result = (new AffectedScheduledJobResolver)->resolve(
        jobIndex: $index,
        edges: [],
        nodeIdToPath: ['node-job' => 'app/Jobs/MyJob.php'],
        diffNodes: [['id' => 'node-x', 'path' => '']], // empty path
    );

    expect($result)->toBeEmpty();
});
