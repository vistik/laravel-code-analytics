<?php

use Vistik\LaravelCodeAnalytics\Actions\GenerateJsonReport;
use Vistik\LaravelCodeAnalytics\Actions\GenerateMdReport;

it('includes clusters in JSON report output', function () {
    $clusters = [
        'dependency' => [
            ['files' => ['app/Models/User.php', 'app/Http/Controllers/UserController.php', 'database/migrations/create_users.php'], 'size' => 3],
        ],
    ];

    $json = (new GenerateJsonReport)->generate(
        nodes: [],
        edges: [],
        fileDiffs: [],
        analysisData: [],
        title: 'Test',
        repo: 'test/repo',
        headCommit: 'abc1234',
        prAdditions: 0,
        prDeletions: 0,
        fileCount: 0,
        clusters: $clusters,
    );

    $data = json_decode($json, true);

    expect($data['clusters'])->toHaveCount(1)
        ->and($data['clusters']['dependency'][0]['size'])->toBe(3)
        ->and($data['clusters']['dependency'][0]['files'])->toContain('app/Models/User.php');
});

it('includes empty clusters array in JSON when no clusters', function () {
    $json = (new GenerateJsonReport)->generate(
        nodes: [],
        edges: [],
        fileDiffs: [],
        analysisData: [],
        title: 'Test',
        repo: 'test/repo',
        headCommit: 'abc1234',
        prAdditions: 0,
        prDeletions: 0,
        fileCount: 0,
    );

    $data = json_decode($json, true);

    expect($data['clusters'])->toBe([]);
});

it('includes clusters section in Markdown report', function () {
    $clusters = [
        'dependency' => [
            ['files' => ['app/Models/User.php', 'app/Controllers/UserController.php', 'database/migrations/create_users.php'], 'size' => 3],
            ['files' => ['app/Models/Order.php', 'app/Services/OrderService.php', 'app/Events/OrderCreated.php'], 'size' => 3],
        ],
    ];

    $md = (new GenerateMdReport)->generate(
        nodes: [],
        edges: [],
        fileDiffs: [],
        analysisData: [],
        title: 'Test',
        repo: 'test/repo',
        headCommit: 'abc1234',
        prAdditions: 0,
        prDeletions: 0,
        fileCount: 0,
        clusters: $clusters,
    );

    expect($md)
        ->toContain('## Coupling Clusters — Dependency Graph (2)')
        ->toContain('### Cluster 1 (3 files)')
        ->toContain('### Cluster 2 (3 files)')
        ->toContain('`app/Models/User.php`')
        ->toContain('`app/Models/Order.php`');
});

it('omits clusters section in Markdown when no clusters', function () {
    $md = (new GenerateMdReport)->generate(
        nodes: [],
        edges: [],
        fileDiffs: [],
        analysisData: [],
        title: 'Test',
        repo: 'test/repo',
        headCommit: 'abc1234',
        prAdditions: 0,
        prDeletions: 0,
        fileCount: 0,
    );

    expect($md)->not->toContain('Coupling Clusters');
});
