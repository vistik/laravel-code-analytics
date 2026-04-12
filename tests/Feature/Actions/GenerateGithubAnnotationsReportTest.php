<?php

use Vistik\LaravelCodeAnalytics\Actions\GenerateGithubAnnotationsReport;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;

function githubNode(string $path, ?int $cycleId = null): array
{
    return [
        'path' => $path,
        'status' => 'modified',
        'add' => 5,
        'del' => 2,
        'severity' => $cycleId !== null ? 'very_high' : null,
        '_signal' => 10,
        'cycleId' => $cycleId,
        'cycleColor' => $cycleId !== null ? '#f0883e' : null,
        '_cycleBoost' => $cycleId !== null ? 100 : null,
        'veryHighCount' => 0, 'highCount' => 0, 'mediumCount' => 0, 'lowCount' => 0, 'infoCount' => 0, 'analysisCount' => 0,
    ];
}

function generateGithub(array $analysisData = [], array $nodes = []): string
{
    return (new GenerateGithubAnnotationsReport)->generate(
        payload: new GraphPayload(nodes: $nodes, edges: [], fileDiffs: [], analysisData: $analysisData),
        pr: new PullRequestContext(prTitle: 'Test', repo: 'test/repo', headCommit: 'abc1234', prAdditions: 0, prDeletions: 0, fileCount: count($nodes)),
    );
}

test('cycle file emits an error-level annotation', function () {
    $analysisData = [
        'app/Foo.php' => [[
            'category' => 'circular_dependency',
            'severity' => 'very_high',
            'description' => 'Circular dependency (cycle 1): Bar.php',
        ]],
    ];

    $output = generateGithub($analysisData);

    expect($output)->toContain('::error file=app/Foo.php');
});

test('cycle annotation title contains Circular Dependency', function () {
    $analysisData = [
        'app/Foo.php' => [[
            'category' => 'circular_dependency',
            'severity' => 'very_high',
            'description' => 'Circular dependency (cycle 1): Bar.php',
        ]],
    ];

    $output = generateGithub($analysisData);

    expect($output)->toContain('Circular');
});

test('cycle annotation message describes the cycle members', function () {
    $analysisData = [
        'app/Foo.php' => [[
            'category' => 'circular_dependency',
            'severity' => 'very_high',
            'description' => 'Circular dependency (cycle 1): Bar.php, Baz.php',
        ]],
    ];

    $output = generateGithub($analysisData);

    expect($output)->toContain('Bar.php')->toContain('Baz.php');
});

test('non-cycle file does not emit a circular dependency annotation', function () {
    $analysisData = [
        'app/Clean.php' => [[
            'category' => 'imports',
            'severity' => 'info',
            'description' => 'Some import change',
        ]],
    ];

    $output = generateGithub($analysisData);

    expect($output)->not->toContain('circular_dependency');
});
