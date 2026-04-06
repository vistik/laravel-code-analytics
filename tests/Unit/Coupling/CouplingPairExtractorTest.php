<?php

use Vistik\LaravelCodeAnalytics\Coupling\CouplingPairExtractor;

it('returns empty array when no commits given', function () {
    $result = (new CouplingPairExtractor)->extract([]);

    expect($result)->toBe([]);
});

it('returns empty array when no pair meets minCoChanges', function () {
    $result = (new CouplingPairExtractor)->extract([
        'c1' => ['a.php', 'b.php'],
    ], minCoChanges: 2);

    expect($result)->toBe([]);
});

it('counts co-changes across commits and filters by minCoChanges', function () {
    $result = (new CouplingPairExtractor)->extract([
        'c1' => ['a.php', 'b.php'],
        'c2' => ['a.php', 'b.php'],
        'c3' => ['a.php', 'c.php'],
    ], minCoChanges: 2);

    expect($result)->toHaveCount(1)
        ->and($result[0]['file_a'])->toBe('a.php')
        ->and($result[0]['file_b'])->toBe('b.php')
        ->and($result[0]['co_change_count'])->toBe(2);
});

it('normalises pair order so file_a < file_b lexicographically', function () {
    $result = (new CouplingPairExtractor)->extract([
        'c1' => ['z.php', 'a.php'],
        'c2' => ['z.php', 'a.php'],
    ], minCoChanges: 1);

    expect($result[0]['file_a'])->toBe('a.php')
        ->and($result[0]['file_b'])->toBe('z.php');
});

it('deduplicates pairs that appear in reversed order across commits', function () {
    $result = (new CouplingPairExtractor)->extract([
        'c1' => ['b.php', 'a.php'],
        'c2' => ['a.php', 'b.php'],
    ], minCoChanges: 1);

    expect($result)->toHaveCount(1)
        ->and($result[0]['co_change_count'])->toBe(2);
});

it('sorts results by co_change_count descending', function () {
    $result = (new CouplingPairExtractor)->extract([
        'c1' => ['a.php', 'b.php'],
        'c2' => ['a.php', 'b.php'],
        'c3' => ['a.php', 'b.php'],
        'c4' => ['x.php', 'y.php'],
        'c5' => ['x.php', 'y.php'],
    ], minCoChanges: 1);

    expect($result[0]['co_change_count'])->toBeGreaterThanOrEqual($result[1]['co_change_count']);
});

it('handles commits with more than two files generating all pairs', function () {
    $result = (new CouplingPairExtractor)->extract([
        'c1' => ['a.php', 'b.php', 'c.php'],
        'c2' => ['a.php', 'b.php', 'c.php'],
    ], minCoChanges: 1);

    // 3 files → 3 pairs: a-b, a-c, b-c
    expect($result)->toHaveCount(3);
});
