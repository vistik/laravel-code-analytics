<?php

use Vistik\LaravelCodeAnalytics\Coupling\CouplingClusterer;

it('returns empty array when no pairs given', function () {
    $result = (new CouplingClusterer)->cluster([]);

    expect($result)->toBe([]);
});

it('excludes clusters below minClusterSize', function () {
    $pairs = [
        ['file_a' => 'a.php', 'file_b' => 'b.php'],
    ];

    $result = (new CouplingClusterer)->cluster($pairs, minClusterSize: 3);

    expect($result)->toBe([]);
});

it('merges connected pairs into a single cluster', function () {
    $pairs = [
        ['file_a' => 'a.php', 'file_b' => 'b.php'],
        ['file_a' => 'b.php', 'file_b' => 'c.php'],
    ];

    $result = (new CouplingClusterer)->cluster($pairs, minClusterSize: 3);

    expect($result)->toHaveCount(1)
        ->and($result[0]['size'])->toBe(3)
        ->and($result[0]['files'])->toContain('a.php', 'b.php', 'c.php');
});

it('returns two separate clusters', function () {
    $pairs = [
        ['file_a' => 'a.php', 'file_b' => 'b.php'],
        ['file_a' => 'b.php', 'file_b' => 'c.php'],
        ['file_a' => 'x.php', 'file_b' => 'y.php'],
        ['file_a' => 'y.php', 'file_b' => 'z.php'],
    ];

    $result = (new CouplingClusterer)->cluster($pairs, minClusterSize: 3);

    expect($result)->toHaveCount(2);
    expect($result[0]['size'])->toBe(3);
    expect($result[1]['size'])->toBe(3);
});

it('sorts clusters by size descending', function () {
    $pairs = [
        ['file_a' => 'x.php', 'file_b' => 'y.php'],
        ['file_a' => 'y.php', 'file_b' => 'z.php'],
        ['file_a' => 'a.php', 'file_b' => 'b.php'],
        ['file_a' => 'b.php', 'file_b' => 'c.php'],
        ['file_a' => 'c.php', 'file_b' => 'd.php'],
    ];

    $result = (new CouplingClusterer)->cluster($pairs, minClusterSize: 3);

    expect($result[0]['size'])->toBeGreaterThanOrEqual($result[1]['size']);
});

it('respects the limit parameter', function () {
    $pairs = [];
    for ($i = 0; $i < 20; $i++) {
        $base = $i * 3;
        $pairs[] = ['file_a' => "file{$base}.php", 'file_b' => 'file'.($base + 1).'.php'];
        $pairs[] = ['file_a' => 'file'.($base + 1).'.php', 'file_b' => 'file'.($base + 2).'.php'];
    }

    $result = (new CouplingClusterer)->cluster($pairs, minClusterSize: 3, limit: 5);

    expect($result)->toHaveCount(5);
});
