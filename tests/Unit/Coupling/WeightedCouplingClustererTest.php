<?php

use Vistik\LaravelCodeAnalytics\Coupling\WeightedCouplingClusterer;

it('returns empty array when no edges given', function () {
    $result = (new WeightedCouplingClusterer)->cluster([], []);

    expect($result)->toBe([]);
});

it('returns empty array when fewer changed nodes than minClusterSize', function () {
    $edges = [['A', 'B']];
    $changed = ['A', 'B'];

    $result = (new WeightedCouplingClusterer)->cluster($edges, $changed, minClusterSize: 3);

    expect($result)->toBe([]);
});

it('clusters mutually dependent files', function () {
    $edges = [
        ['A', 'B'],
        ['B', 'A'],
        ['B', 'C'],
        ['C', 'B'],
        ['A', 'C'],
        ['C', 'A'],
    ];
    $changed = ['A', 'B', 'C'];

    $result = (new WeightedCouplingClusterer)->cluster($edges, $changed, minClusterSize: 3);

    expect($result)->toHaveCount(1)
        ->and($result[0]['size'])->toBe(3)
        ->and($result[0]['files'])->toContain('A', 'B', 'C');
});

it('clusters files with shared dependencies', function () {
    // A, B, C all depend on the same external targets X and Y
    $edges = [
        ['A', 'X'],
        ['A', 'Y'],
        ['B', 'X'],
        ['B', 'Y'],
        ['C', 'X'],
        ['C', 'Y'],
    ];
    $changed = ['A', 'B', 'C'];

    $result = (new WeightedCouplingClusterer)->cluster($edges, $changed, minClusterSize: 3);

    expect($result)->toHaveCount(1)
        ->and($result[0]['files'])->toContain('A', 'B', 'C');
});

it('separates unconnected groups', function () {
    $edges = [
        ['A', 'B'],
        ['B', 'A'],
        ['A', 'C'],
        ['C', 'A'],
        ['B', 'C'],
        ['C', 'B'],
        ['X', 'Y'],
        ['Y', 'X'],
        ['X', 'Z'],
        ['Z', 'X'],
        ['Y', 'Z'],
        ['Z', 'Y'],
    ];
    $changed = ['A', 'B', 'C', 'X', 'Y', 'Z'];

    $result = (new WeightedCouplingClusterer)->cluster($edges, $changed, minClusterSize: 3);

    expect($result)->toHaveCount(2);

    $allFiles = array_merge($result[0]['files'], $result[1]['files']);
    expect($allFiles)->toContain('A', 'B', 'C', 'X', 'Y', 'Z');
});

it('does not include non-changed nodes in output', function () {
    // D is a shared dep target but not a changed file
    $edges = [
        ['A', 'D'],
        ['B', 'D'],
        ['C', 'D'],
    ];
    $changed = ['A', 'B', 'C'];

    $result = (new WeightedCouplingClusterer)->cluster($edges, $changed, minClusterSize: 3);

    $allFiles = [];
    foreach ($result as $cluster) {
        $allFiles = array_merge($allFiles, $cluster['files']);
    }
    expect($allFiles)->not->toContain('D');
});

it('respects the limit parameter', function () {
    $edges = [];
    $changed = [];

    for ($i = 0; $i < 5; $i++) {
        $a = "g{$i}_a";
        $b = "g{$i}_b";
        $c = "g{$i}_c";
        // Mutual deps to ensure strong coupling
        $edges[] = [$a, $b];
        $edges[] = [$b, $a];
        $edges[] = [$b, $c];
        $edges[] = [$c, $b];
        $edges[] = [$a, $c];
        $edges[] = [$c, $a];
        $changed[] = $a;
        $changed[] = $b;
        $changed[] = $c;
    }

    $result = (new WeightedCouplingClusterer)->cluster($edges, $changed, minClusterSize: 3, limit: 3);

    expect(count($result))->toBeLessThanOrEqual(3);
});

it('sorts clusters by size descending', function () {
    $edges = [
        ['A', 'B'], ['B', 'A'],
        ['A', 'C'], ['C', 'A'],
        ['B', 'C'], ['C', 'B'],
        ['X', 'Y'], ['Y', 'X'],
        ['X', 'Z'], ['Z', 'X'],
        ['Y', 'Z'], ['Z', 'Y'],
        ['X', 'W'], ['W', 'X'],
        ['W', 'Y'], ['Y', 'W'],
    ];
    $changed = ['A', 'B', 'C', 'X', 'Y', 'Z', 'W'];

    $result = (new WeightedCouplingClusterer)->cluster($edges, $changed, minClusterSize: 3);

    if (count($result) >= 2) {
        expect($result[0]['size'])->toBeGreaterThanOrEqual($result[1]['size']);
    }
});

it('implements the Clusterer interface', function () {
    $clusterer = new WeightedCouplingClusterer;

    expect($clusterer)->toBeInstanceOf(\Vistik\LaravelCodeAnalytics\Coupling\Clusterer::class);
});
