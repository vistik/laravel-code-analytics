<?php

use Vistik\LaravelCodeAnalytics\Coupling\CouplingClusterer;
use Vistik\LaravelCodeAnalytics\Coupling\CouplingPairExtractor;

it('produces clusters from raw commit history end-to-end', function () {
    $commitFiles = [
        'c1' => ['app/Models/User.php', 'app/Http/Controllers/UserController.php', 'database/migrations/create_users_table.php'],
        'c2' => ['app/Models/User.php', 'app/Http/Controllers/UserController.php'],
        'c3' => ['app/Models/User.php', 'app/Http/Controllers/UserController.php', 'database/migrations/create_users_table.php'],
        'c4' => ['app/Models/Order.php', 'app/Services/OrderService.php', 'app/Events/OrderCreated.php'],
        'c5' => ['app/Models/Order.php', 'app/Services/OrderService.php', 'app/Events/OrderCreated.php'],
        'c6' => ['config/app.php'],
    ];

    $pairs = (new CouplingPairExtractor)->extract($commitFiles, minCoChanges: 2);
    $clusters = (new CouplingClusterer)->cluster($pairs, minClusterSize: 3);

    expect($clusters)->toHaveCount(2);

    $allClusterFiles = array_merge(...array_column($clusters, 'files'));

    expect($allClusterFiles)
        ->toContain('app/Models/User.php')
        ->toContain('app/Http/Controllers/UserController.php')
        ->toContain('database/migrations/create_users_table.php')
        ->toContain('app/Models/Order.php')
        ->toContain('app/Services/OrderService.php')
        ->toContain('app/Events/OrderCreated.php');
});

it('returns empty clusters when commit history has no repeated co-changes', function () {
    $commitFiles = [
        'c1' => ['a.php', 'b.php'],
        'c2' => ['c.php', 'd.php'],
        'c3' => ['e.php', 'f.php'],
    ];

    $pairs = (new CouplingPairExtractor)->extract($commitFiles, minCoChanges: 2);
    $clusters = (new CouplingClusterer)->cluster($pairs);

    expect($clusters)->toBe([]);
});

it('filters out small clusters from the result', function () {
    // Only 2 files co-change, which is below the default minClusterSize of 3
    $commitFiles = [
        'c1' => ['a.php', 'b.php'],
        'c2' => ['a.php', 'b.php'],
        'c3' => ['a.php', 'b.php'],
    ];

    $pairs = (new CouplingPairExtractor)->extract($commitFiles, minCoChanges: 2);
    $clusters = (new CouplingClusterer)->cluster($pairs, minClusterSize: 3);

    expect($clusters)->toBe([]);
});

it('merges transitive co-changes into a single cluster', function () {
    // a↔b and b↔c should form one cluster {a, b, c} even though a and c never co-change directly
    $commitFiles = [
        'c1' => ['a.php', 'b.php'],
        'c2' => ['a.php', 'b.php'],
        'c3' => ['b.php', 'c.php'],
        'c4' => ['b.php', 'c.php'],
    ];

    $pairs = (new CouplingPairExtractor)->extract($commitFiles, minCoChanges: 2);
    $clusters = (new CouplingClusterer)->cluster($pairs, minClusterSize: 3);

    expect($clusters)->toHaveCount(1)
        ->and($clusters[0]['files'])->toContain('a.php', 'b.php', 'c.php');
});
