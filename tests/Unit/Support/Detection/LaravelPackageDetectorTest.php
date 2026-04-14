<?php

use Vistik\LaravelCodeAnalytics\Support\Detection\LaravelPackageDetector;
use Vistik\LaravelCodeAnalytics\Support\Detection\RepoContext;

// ── Helpers ───────────────────────────────────────────────────────────────────

function tempDirWithComposer(array $composer): string
{
    $dir = sys_get_temp_dir().'/pkg_'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents("{$dir}/composer.json", json_encode($composer));

    return $dir;
}

function tempGitRepoWithComposer(array $composer): array
{
    $dir = sys_get_temp_dir().'/pkg_git_'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents("{$dir}/composer.json", json_encode($composer));

    shell_exec("git init {$dir} 2>/dev/null");
    shell_exec("git -C {$dir} config user.email test@test.com 2>/dev/null");
    shell_exec("git -C {$dir} config user.name Test 2>/dev/null");
    shell_exec("git -C {$dir} add composer.json 2>/dev/null");
    shell_exec("git -C {$dir} commit -m init 2>/dev/null");
    $commit = trim(shell_exec("git -C {$dir} rev-parse HEAD 2>/dev/null"));

    return ['dir' => $dir, 'commit' => $commit];
}

// ── Filesystem ────────────────────────────────────────────────────────────────

it('detects a Laravel package with illuminate/* in require', function () {
    $dir = tempDirWithComposer([
        'require' => ['illuminate/support' => '^11.0'],
    ]);

    expect((new LaravelPackageDetector)->detect(RepoContext::filesystem($dir)))->toBeTrue();
});

it('detects a Laravel package with illuminate/* in require-dev', function () {
    $dir = tempDirWithComposer([
        'require-dev' => ['illuminate/testing' => '^11.0'],
    ]);

    expect((new LaravelPackageDetector)->detect(RepoContext::filesystem($dir)))->toBeTrue();
});

it('detects a Laravel package with laravel/framework in require', function () {
    $dir = tempDirWithComposer([
        'require' => ['laravel/framework' => '^11.0'],
    ]);

    expect((new LaravelPackageDetector)->detect(RepoContext::filesystem($dir)))->toBeTrue();
});

it('returns false when composer.json has no Laravel dependencies', function () {
    $dir = tempDirWithComposer([
        'require' => ['guzzlehttp/guzzle' => '^7.0'],
    ]);

    expect((new LaravelPackageDetector)->detect(RepoContext::filesystem($dir)))->toBeFalse();
});

it('returns false when composer.json is absent', function () {
    $dir = sys_get_temp_dir().'/pkg_empty_'.uniqid();
    mkdir($dir, 0755, true);

    expect((new LaravelPackageDetector)->detect(RepoContext::filesystem($dir)))->toBeFalse();
});

it('returns false when composer.json is not valid JSON', function () {
    $dir = sys_get_temp_dir().'/pkg_bad_'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents("{$dir}/composer.json", 'not json');

    expect((new LaravelPackageDetector)->detect(RepoContext::filesystem($dir)))->toBeFalse();
});

// ── Git ───────────────────────────────────────────────────────────────────────

it('detects a Laravel package from a git commit', function () {
    ['dir' => $dir, 'commit' => $commit] = tempGitRepoWithComposer([
        'require' => ['illuminate/support' => '^11.0'],
    ]);

    expect((new LaravelPackageDetector)->detect(RepoContext::git($dir, $commit)))->toBeTrue();
})->skip(fn () => trim(shell_exec('which git 2>/dev/null')) === '', 'git not available');

it('returns false from git when no Laravel deps in composer.json', function () {
    ['dir' => $dir, 'commit' => $commit] = tempGitRepoWithComposer([
        'require' => ['psr/log' => '^3.0'],
    ]);

    expect((new LaravelPackageDetector)->detect(RepoContext::git($dir, $commit)))->toBeFalse();
})->skip(fn () => trim(shell_exec('which git 2>/dev/null')) === '', 'git not available');
