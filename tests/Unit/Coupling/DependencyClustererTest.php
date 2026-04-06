<?php

use Vistik\LaravelCodeAnalytics\Coupling\DependencyClusterer;

it('returns empty array when no edges given', function () {
    $result = (new DependencyClusterer)->cluster([], []);

    expect($result)->toBe([]);
});

it('returns empty array when fewer changed nodes than minClusterSize', function () {
    $edges = [['A', 'B']];
    $changed = ['A', 'B'];

    $result = (new DependencyClusterer)->cluster($edges, $changed, minClusterSize: 3);

    expect($result)->toBe([]);
});

it('finds a cluster of 3 interconnected nodes', function () {
    $edges = [
        ['A', 'B'],
        ['B', 'C'],
        ['A', 'C'],
    ];
    $changed = ['A', 'B', 'C'];

    $result = (new DependencyClusterer)->cluster($edges, $changed, minClusterSize: 3);

    expect($result)->toHaveCount(1)
        ->and($result[0]['size'])->toBe(3)
        ->and($result[0]['files'])->toContain('A', 'B', 'C');
});

it('finds two separate clusters', function () {
    $edges = [
        ['A', 'B'],
        ['B', 'C'],
        ['A', 'C'],
        ['X', 'Y'],
        ['Y', 'Z'],
        ['X', 'Z'],
    ];
    $changed = ['A', 'B', 'C', 'X', 'Y', 'Z'];

    $result = (new DependencyClusterer)->cluster($edges, $changed, minClusterSize: 3);

    expect($result)->toHaveCount(2);

    $allFiles = array_merge($result[0]['files'], $result[1]['files']);
    expect($allFiles)->toContain('A', 'B', 'C', 'X', 'Y', 'Z');
});

it('excludes nodes not in changedNodeIds', function () {
    // D is a dependency target but not in changed files
    // A, B, C all depend on D — they connect through D as a shared neighbor
    $edges = [
        ['A', 'D'],
        ['B', 'D'],
        ['C', 'D'],
    ];
    $changed = ['A', 'B', 'C'];

    $result = (new DependencyClusterer)->cluster($edges, $changed, minClusterSize: 2);

    // D should never appear in the output clusters
    $allFiles = [];
    foreach ($result as $cluster) {
        $allFiles = array_merge($allFiles, $cluster['files']);
    }
    expect($allFiles)->not->toContain('D');
});

it('weights mutual dependencies higher', function () {
    // Group 1: A↔B mutual, A→C one-way — tight cluster
    // Group 2: X→Y, X→Z one-way — loose connections
    $edges = [
        ['A', 'B'],
        ['B', 'A'],
        ['A', 'C'],
        ['C', 'A'],
        ['B', 'C'],
        ['X', 'Y'],
        ['X', 'Z'],
    ];
    $changed = ['A', 'B', 'C', 'X', 'Y', 'Z'];

    $result = (new DependencyClusterer)->cluster($edges, $changed, minClusterSize: 3);

    // A, B, C should form a cluster due to mutual deps
    expect($result)->not->toBeEmpty();

    $firstCluster = $result[0];
    expect($firstCluster['files'])->toContain('A', 'B', 'C');
});

it('respects the limit parameter', function () {
    $edges = [];
    $changed = [];

    // Create 5 disconnected clusters of 3 nodes each
    for ($i = 0; $i < 5; $i++) {
        $a = "g{$i}_a";
        $b = "g{$i}_b";
        $c = "g{$i}_c";
        $edges[] = [$a, $b];
        $edges[] = [$b, $c];
        $edges[] = [$a, $c];
        $changed[] = $a;
        $changed[] = $b;
        $changed[] = $c;
    }

    $result = (new DependencyClusterer)->cluster($edges, $changed, minClusterSize: 3, limit: 3);

    expect(count($result))->toBeLessThanOrEqual(3);
});

it('does not cluster nodes with no shared edges', function () {
    // A→B and C→D are completely separate, each only 2 nodes
    $edges = [
        ['A', 'B'],
        ['C', 'D'],
    ];
    $changed = ['A', 'B', 'C', 'D'];

    $result = (new DependencyClusterer)->cluster($edges, $changed, minClusterSize: 3);

    expect($result)->toBe([]);
});

it('sorts clusters by size descending', function () {
    $edges = [
        ['A', 'B'],
        ['B', 'C'],
        ['A', 'C'],
        ['X', 'Y'],
        ['Y', 'Z'],
        ['X', 'Z'],
        ['X', 'W'],
        ['W', 'Y'],
    ];
    $changed = ['A', 'B', 'C', 'X', 'Y', 'Z', 'W'];

    $result = (new DependencyClusterer)->cluster($edges, $changed, minClusterSize: 3);

    if (count($result) >= 2) {
        expect($result[0]['size'])->toBeGreaterThanOrEqual($result[1]['size']);
    }
});
