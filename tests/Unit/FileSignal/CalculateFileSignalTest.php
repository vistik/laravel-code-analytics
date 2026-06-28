<?php

use Vistik\LaravelCodeAnalytics\FileSignal\CalculateFileSignal;

// ── Return shape ──────────────────────────────────────────────────────────────

test('returns an array with score and breakdown keys', function () {
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], [], null);

    expect($result)->toHaveKeys(['score', 'breakdown']);
});

test('breakdown contains all five component keys', function () {
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], [], null);

    expect($result['breakdown'])->toHaveKeys(['findings', 'change_size', 'cc', 'mi', 'lloc']);
});

test('score is an integer', function () {
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], [], null);

    expect($result['score'])->toBeInt();
});

// ── Default behaviour (no config) ─────────────────────────────────────────────

test('score is zero for empty node with no findings and no metrics', function () {
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], [], null);

    expect($result['score'])->toBe(0);
});

test('change_size contribution is sqrt(add + del) * 2 by default', function () {
    // sqrt(100) * 2 = 20
    $result = (new CalculateFileSignal)->calculate(['add' => 100, 'del' => 0], [], null);

    expect($result['breakdown']['change_size'])->toBe(20);
});

test('findings contribution uses default severity weights', function () {
    $findings = [
        ['severity' => 'very_high'],  // 10
        ['severity' => 'high'],        // 7
        ['severity' => 'medium'],      // 5
        ['severity' => 'low'],         // 3
        ['severity' => 'info'],        // 1
    ];

    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], $findings, null);

    expect($result['breakdown']['findings'])->toBe(26);
});

test('cc score is zero when cc is at the threshold', function () {
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], [], ['cc' => 10]);

    expect($result['breakdown']['cc'])->toBe(0);
});

test('cc score applies (cc - 10) * 2 when cc exceeds default threshold', function () {
    // cc=15: (15 - 10) * 2 = 10
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], [], ['cc' => 15]);

    expect($result['breakdown']['cc'])->toBe(10);
});

test('mi score is zero when mi is at or above the threshold', function () {
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], [], ['mi' => 65]);

    expect($result['breakdown']['mi'])->toBe(0);
});

test('mi score applies (65 - mi) * 0.5 when mi is below default threshold', function () {
    // mi=45: (65 - 45) * 0.5 = 10
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], [], ['mi' => 45]);

    expect($result['breakdown']['mi'])->toBe(10);
});

test('lloc score is zero when lloc is at the cutoff', function () {
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], [], ['lloc' => 200]);

    expect($result['breakdown']['lloc'])->toBe(0);
});

test('lloc score applies sqrt(lloc - cutoff) * 0.5 when lloc exceeds default cutoff', function () {
    // lloc=300: sqrt(300 - 200) * 0.5 = sqrt(100) * 0.5 = 5
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], [], ['lloc' => 300]);

    expect($result['breakdown']['lloc'])->toBe(5);
});

test('score equals sum of all breakdown components', function () {
    $findings = [['severity' => 'high']]; // 7
    $metrics = ['cc' => 15, 'mi' => 45, 'lloc' => 300];
    // change_size: sqrt(100) * 2 = 20
    // findings: 7
    // cc: (15-10)*2 = 10
    // mi: (65-45)*0.5 = 10
    // lloc: sqrt(100)*0.5 = 5
    // total: 7 + 20 + 10 + 10 + 5 = 52
    $result = (new CalculateFileSignal)->calculate(['add' => 100, 'del' => 0], $findings, $metrics);

    $b = $result['breakdown'];
    expect($result['score'])->toBe($b['findings'] + $b['change_size'] + $b['cc'] + $b['mi'] + $b['lloc']);
});

// ── Custom config: severity weights ───────────────────────────────────────────

test('custom severity weights override defaults for individual levels', function () {
    $scorer = new CalculateFileSignal(['findings' => ['high' => 20, 'low' => 1]]);
    $result = $scorer->calculate(['add' => 0, 'del' => 0], [['severity' => 'high']], null);

    expect($result['breakdown']['findings'])->toBe(20);
});

test('unspecified severity levels in custom config fall back to default weights', function () {
    // Only 'high' is overridden; 'very_high' should still use its default of 10
    $scorer = new CalculateFileSignal(['findings' => ['high' => 20]]);
    $result = $scorer->calculate(['add' => 0, 'del' => 0], [['severity' => 'very_high']], null);

    expect($result['breakdown']['findings'])->toBe(10);
});

// ── Custom config: change_size multiplier ─────────────────────────────────────

test('custom change_size multiplier replaces the default of 2.0', function () {
    // sqrt(100) * 3 = 30
    $scorer = new CalculateFileSignal(['change_size' => ['multiplier' => 3.0]]);
    $result = $scorer->calculate(['add' => 100, 'del' => 0], [], null);

    expect($result['breakdown']['change_size'])->toBe(30);
});

test('change_size multiplier of zero produces a zero contribution', function () {
    $scorer = new CalculateFileSignal(['change_size' => ['multiplier' => 0.0]]);
    $result = $scorer->calculate(['add' => 100, 'del' => 0], [], null);

    expect($result['breakdown']['change_size'])->toBe(0);
});

// ── Custom config: cc threshold and multiplier ────────────────────────────────

test('custom cc threshold raises the bar before penalties apply', function () {
    // cc=15, default threshold 10 → 10 points; custom threshold 20 → 0 points
    $scorer = new CalculateFileSignal(['cc' => ['threshold' => 20]]);
    $result = $scorer->calculate(['add' => 0, 'del' => 0], [], ['cc' => 15]);

    expect($result['breakdown']['cc'])->toBe(0);
});

test('custom cc multiplier scales the penalty per unit above threshold', function () {
    // cc=15, threshold=10, multiplier=5: (15-10)*5 = 25
    $scorer = new CalculateFileSignal(['cc' => ['threshold' => 10, 'multiplier' => 5.0]]);
    $result = $scorer->calculate(['add' => 0, 'del' => 0], [], ['cc' => 15]);

    expect($result['breakdown']['cc'])->toBe(25);
});

// ── Custom config: mi threshold and multiplier ────────────────────────────────

test('custom mi threshold changes when mi penalties start', function () {
    // mi=70, default threshold 65 → 0 penalty; custom threshold 80 → (80-70)*0.5 = 5
    $scorer = new CalculateFileSignal(['mi' => ['threshold' => 80]]);
    $result = $scorer->calculate(['add' => 0, 'del' => 0], [], ['mi' => 70]);

    expect($result['breakdown']['mi'])->toBe(5);
});

test('custom mi multiplier scales the penalty', function () {
    // mi=55, threshold=65, multiplier=2: (65-55)*2 = 20
    $scorer = new CalculateFileSignal(['mi' => ['threshold' => 65, 'multiplier' => 2.0]]);
    $result = $scorer->calculate(['add' => 0, 'del' => 0], [], ['mi' => 55]);

    expect($result['breakdown']['mi'])->toBe(20);
});

// ── Custom config: lloc cutoff and multiplier ─────────────────────────────────

test('custom lloc cutoff changes when lloc penalties start', function () {
    // lloc=250, default cutoff 200 → sqrt(50)*0.5≈3.5→4; custom cutoff 300 → 0
    $scorer = new CalculateFileSignal(['lloc' => ['cutoff' => 300]]);
    $result = $scorer->calculate(['add' => 0, 'del' => 0], [], ['lloc' => 250]);

    expect($result['breakdown']['lloc'])->toBe(0);
});

test('custom lloc multiplier scales the penalty', function () {
    // lloc=300, cutoff=200, multiplier=2: sqrt(100)*2 = 20
    $scorer = new CalculateFileSignal(['lloc' => ['cutoff' => 200, 'multiplier' => 2.0]]);
    $result = $scorer->calculate(['add' => 0, 'del' => 0], [], ['lloc' => 300]);

    expect($result['breakdown']['lloc'])->toBe(20);
});

// ── Edge cases ────────────────────────────────────────────────────────────────

test('metrics null skips cc, mi, and lloc components entirely', function () {
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], [], null);

    expect($result['breakdown']['cc'])->toBe(0)
        ->and($result['breakdown']['mi'])->toBe(0)
        ->and($result['breakdown']['lloc'])->toBe(0);
});

test('deletions contribute to change_size the same as additions', function () {
    // sqrt(0 + 100) * 2 = 20
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 100], [], null);

    expect($result['breakdown']['change_size'])->toBe(20);
});

test('empty findings array produces a zero findings score', function () {
    $result = (new CalculateFileSignal)->calculate(['add' => 0, 'del' => 0], [], null);

    expect($result['breakdown']['findings'])->toBe(0);
});
