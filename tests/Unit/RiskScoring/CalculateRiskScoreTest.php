<?php

use Vistik\LaravelCodeAnalytics\RiskScoring\CalculateRiskScore;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

function makeLocalScore(
    array $nodes = [],
    int $additions = 0,
    int $deletions = 0,
    int $fileCount = 1,
    int $phpHotSpots = 0,
): RiskScore {
    return (new CalculateRiskScore)->calculate($nodes, $additions, $deletions, $fileCount, $phpHotSpots);
}

it('returns zero for a minimal change', function () {
    $result = makeLocalScore(additions: 5, deletions: 2);

    expect($result->score)->toBe(0)
        ->and($result->factors)->toHaveCount(5);
});

it('scores change size factor', function () {
    $result = makeLocalScore(additions: 600, deletions: 500);

    $factor = collect($result->factors)->firstWhere('name', 'Change Size');

    // >1000 total lines → 25 pts
    expect($factor['score'])->toBe(25)
        ->and($factor['maxScore'])->toBe(25);
});

it('scores file spread factor', function () {
    $result = makeLocalScore(fileCount: 35);

    $factor = collect($result->factors)->firstWhere('name', 'File Spread');

    // >30 files → 10 pts
    expect($factor['score'])->toBe(10)
        ->and($factor['maxScore'])->toBe(10);
});

it('scores deletion ratio factor', function () {
    $result = makeLocalScore(additions: 10, deletions: 90);

    $factor = collect($result->factors)->firstWhere('name', 'Deletion Ratio');

    // 90% deletion ratio → 10 pts
    expect($factor['score'])->toBe(10)
        ->and($factor['maxScore'])->toBe(10);
});

it('scores code analysis findings and caps at max', function () {
    $nodes = [
        ['veryHighCount' => 4, 'highCount' => 0, 'mediumCount' => 0, 'lowCount' => 0],
    ];

    $result = makeLocalScore(nodes: $nodes);

    $factor = collect($result->factors)->firstWhere('name', 'Code Analysis Findings');

    // 4*10 = 40, capped at 40
    expect($factor['score'])->toBe(40)
        ->and($factor['maxScore'])->toBe(40);
});

it('scores php code quality factor', function () {
    $result = makeLocalScore(phpHotSpots: 3);

    $factor = collect($result->factors)->firstWhere('name', 'PHP Code Quality');

    // 3 hot spots → 6 pts
    expect($factor['score'])->toBe(6)
        ->and($factor['maxScore'])->toBe(15);
});

it('normalizes total score against sum of all factor maxScores', function () {
    // All factors at max: ChangeSize(25)+FileSpread(10)+DeletionRatio(10)+Severity(40)+PhpMetrics(15) = 100
    // To hit deletion ratio >0.8: additions=200, deletions=1000 → ratio=0.833, totalLines=1200 >1000
    $nodes = [
        ['veryHighCount' => 4, 'highCount' => 0, 'mediumCount' => 0, 'lowCount' => 0],
    ];

    $result = makeLocalScore(
        nodes: $nodes,
        additions: 200,
        deletions: 1000,
        fileCount: 35,
        phpHotSpots: 11,
    );

    expect($result->score)->toBe(100);
});
