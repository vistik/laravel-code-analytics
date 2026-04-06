<?php

use Vistik\LaravelCodeAnalytics\Actions\GenerateHtmlReport;

function makeClusterHtml(array $clusters = []): string
{
    return (new GenerateHtmlReport)->generate(
        nodes: [
            ['id' => 'User', 'path' => 'app/Models/User.php', 'domain' => 'Models', 'folder' => 'Models', 'ext' => '.php', 'domainColor' => '#3fb950', 'group' => 'model', 'status' => 'modified', 'isConnected' => false, 'add' => 5, 'del' => 2, 'severity' => null, 'analysisCount' => 0, 'veryHighCount' => 0, 'highCount' => 0, 'mediumCount' => 0, 'lowCount' => 0, 'infoCount' => 0, '_signal' => 10, 'color' => '#3fb950'],
            ['id' => 'UserController', 'path' => 'app/Http/Controllers/UserController.php', 'domain' => 'Http', 'folder' => 'Http/Controllers', 'ext' => '.php', 'domainColor' => '#58a6ff', 'group' => 'controller', 'status' => 'modified', 'isConnected' => false, 'add' => 3, 'del' => 1, 'severity' => null, 'analysisCount' => 0, 'veryHighCount' => 0, 'highCount' => 0, 'mediumCount' => 0, 'lowCount' => 0, 'infoCount' => 0, '_signal' => 5, 'color' => '#58a6ff'],
        ],
        edges: [],
        fileDiffs: [],
        analysisData: [],
        title: 'Test Clusters',
        repo: 'test/repo',
        headCommit: 'abc1234',
        prAdditions: 8,
        prDeletions: 3,
        fileCount: 2,
        clusters: $clusters,
    );
}

test('wrapper html contains clusters data when clusters are provided', function () {
    $clusters = [
        'dependency' => [
            ['files' => ['app/Models/User.php', 'app/Http/Controllers/UserController.php', 'database/migrations/create_users.php'], 'size' => 3],
        ],
    ];

    $html = makeClusterHtml($clusters);

    expect($html)
        ->toContain('allClustersData')
        ->toContain('Coupling Clusters')
        ->toContain('toggleClusterHighlight');
});

test('wrapper html shows clusters tab when clusters exist', function () {
    $clusters = [
        'dependency' => [
            ['files' => ['a.php', 'b.php', 'c.php'], 'size' => 3],
        ],
    ];

    $html = makeClusterHtml($clusters);

    expect($html)->toContain('id="clustersTab"');
});

test('wrapper html hides clusters tab when no clusters', function () {
    $html = makeClusterHtml([]);

    expect($html)->not->toContain('id="clustersTab"');
});

test('wrapper html contains highlightCluster postMessage for graph interaction', function () {
    $clusters = [
        'dependency' => [
            ['files' => ['a.php', 'b.php', 'c.php'], 'size' => 3],
        ],
    ];

    $html = makeClusterHtml($clusters);

    expect($html)->toContain('highlightCluster');
});
