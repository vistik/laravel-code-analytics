<?php

use Vistik\LaravelCodeAnalytics\Support\Detection\PhpPackageDetector;
use Vistik\LaravelCodeAnalytics\Support\Detection\RepoContext;

// ── Helpers ───────────────────────────────────────────────────────────────────

function tempPhpDir(array $composer): string
{
    $dir = sys_get_temp_dir().'/php_'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents("{$dir}/composer.json", json_encode($composer));

    return $dir;
}

function tempPhpGitRepo(array $composer): array
{
    $dir = sys_get_temp_dir().'/php_git_'.uniqid();
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

it('detects a PHP package when composer.json has type library', function () {
    $dir = tempPhpDir(['type' => 'library', 'name' => 'acme/utils']);

    expect((new PhpPackageDetector)->detect(RepoContext::filesystem($dir)))->toBeTrue();
});

it('detects a PHP package when composer.json has no type (defaults to library)', function () {
    $dir = tempPhpDir(['name' => 'acme/utils']);

    expect((new PhpPackageDetector)->detect(RepoContext::filesystem($dir)))->toBeTrue();
});

it('returns false when composer.json type is project', function () {
    $dir = tempPhpDir(['type' => 'project', 'name' => 'acme/app']);

    expect((new PhpPackageDetector)->detect(RepoContext::filesystem($dir)))->toBeFalse();
});

it('returns false when composer.json is absent', function () {
    $dir = sys_get_temp_dir().'/php_empty_'.uniqid();
    mkdir($dir, 0755, true);

    expect((new PhpPackageDetector)->detect(RepoContext::filesystem($dir)))->toBeFalse();
});

it('returns false when composer.json is not valid JSON', function () {
    $dir = sys_get_temp_dir().'/php_bad_'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents("{$dir}/composer.json", 'not json');

    expect((new PhpPackageDetector)->detect(RepoContext::filesystem($dir)))->toBeFalse();
});

// ── Git ───────────────────────────────────────────────────────────────────────

it('detects a PHP package from a git commit', function () {
    ['dir' => $dir, 'commit' => $commit] = tempPhpGitRepo(['name' => 'acme/utils']);

    expect((new PhpPackageDetector)->detect(RepoContext::git($dir, $commit)))->toBeTrue();
})->skip(fn () => trim(shell_exec('which git 2>/dev/null')) === '', 'git not available');

it('returns false from git when composer.json type is project', function () {
    ['dir' => $dir, 'commit' => $commit] = tempPhpGitRepo(['type' => 'project']);

    expect((new PhpPackageDetector)->detect(RepoContext::git($dir, $commit)))->toBeFalse();
})->skip(fn () => trim(shell_exec('which git 2>/dev/null')) === '', 'git not available');
