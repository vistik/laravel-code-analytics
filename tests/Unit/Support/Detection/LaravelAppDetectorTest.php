<?php

use Vistik\LaravelCodeAnalytics\Support\Detection\LaravelAppDetector;
use Vistik\LaravelCodeAnalytics\Support\Detection\RepoContext;

// ── Helpers ───────────────────────────────────────────────────────────────────

function tempDir(): string
{
    $dir = sys_get_temp_dir().'/detector_'.uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function tempGitRepo(array $files = []): array
{
    $dir = tempDir();
    shell_exec("git init {$dir} 2>/dev/null");
    shell_exec("git -C {$dir} config user.email test@test.com 2>/dev/null");
    shell_exec("git -C {$dir} config user.name Test 2>/dev/null");

    foreach ($files as $path => $content) {
        $full = "{$dir}/{$path}";
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, $content);
        shell_exec('git -C '.escapeshellarg($dir).' add '.escapeshellarg($path).' 2>/dev/null');
    }

    shell_exec("git -C {$dir} commit -m init 2>/dev/null");
    $commit = trim(shell_exec("git -C {$dir} rev-parse HEAD 2>/dev/null"));

    return ['dir' => $dir, 'commit' => $commit];
}

// ── Filesystem ────────────────────────────────────────────────────────────────

it('detects a Laravel app when artisan file exists', function () {
    $dir = tempDir();
    file_put_contents("{$dir}/artisan", '#!/usr/bin/env php');

    $result = (new LaravelAppDetector)->detect(RepoContext::filesystem($dir));

    expect($result)->toBeTrue();
});

it('returns false when artisan file is absent', function () {
    $dir = tempDir();

    $result = (new LaravelAppDetector)->detect(RepoContext::filesystem($dir));

    expect($result)->toBeFalse();
});

it('returns false when artisan is a directory, not a file', function () {
    $dir = tempDir();
    mkdir("{$dir}/artisan");

    $result = (new LaravelAppDetector)->detect(RepoContext::filesystem($dir));

    expect($result)->toBeFalse();
});

// ── Git ───────────────────────────────────────────────────────────────────────

it('detects a Laravel app from a git commit when artisan blob exists', function () {
    ['dir' => $dir, 'commit' => $commit] = tempGitRepo(['artisan' => '#!/usr/bin/env php']);

    $result = (new LaravelAppDetector)->detect(RepoContext::git($dir, $commit));

    expect($result)->toBeTrue();
})->skip(fn () => trim(shell_exec('which git 2>/dev/null')) === '', 'git not available');

it('returns false from git when artisan blob is absent', function () {
    ['dir' => $dir, 'commit' => $commit] = tempGitRepo(['README.md' => '# hello']);

    $result = (new LaravelAppDetector)->detect(RepoContext::git($dir, $commit));

    expect($result)->toBeFalse();
})->skip(fn () => trim(shell_exec('which git 2>/dev/null')) === '', 'git not available');
