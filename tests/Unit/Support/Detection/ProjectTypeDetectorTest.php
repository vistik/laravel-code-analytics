<?php

use Vistik\LaravelCodeAnalytics\Support\Detection\ProjectType;
use Vistik\LaravelCodeAnalytics\Support\Detection\ProjectTypeDetector;

// ── Helpers ───────────────────────────────────────────────────────────────────

function repoDir(array $files): string
{
    $dir = sys_get_temp_dir().'/ptd_'.uniqid();
    mkdir($dir, 0755, true);

    foreach ($files as $path => $content) {
        file_put_contents("{$dir}/{$path}", $content);
    }

    return $dir;
}

function gitRepo(array $files): array
{
    $dir = sys_get_temp_dir().'/ptd_git_'.uniqid();
    mkdir($dir, 0755, true);

    shell_exec("git init {$dir} 2>/dev/null");
    shell_exec("git -C {$dir} config user.email test@test.com 2>/dev/null");
    shell_exec("git -C {$dir} config user.name Test 2>/dev/null");

    foreach ($files as $path => $content) {
        file_put_contents("{$dir}/{$path}", $content);
        shell_exec('git -C '.escapeshellarg($dir).' add '.escapeshellarg($path).' 2>/dev/null');
    }

    shell_exec("git -C {$dir} commit -m init 2>/dev/null");
    $commit = trim(shell_exec("git -C {$dir} rev-parse HEAD 2>/dev/null"));

    return ['dir' => $dir, 'commit' => $commit];
}

$laravelComposer = json_encode(['require' => ['illuminate/support' => '^11.0']]);
$libraryComposer = json_encode(['name' => 'acme/utils']);
$projectComposer = json_encode(['type' => 'project']);

// ── Filesystem ────────────────────────────────────────────────────────────────

it('returns LaravelApp when artisan file is present', function () use ($laravelComposer) {
    $dir = repoDir(['artisan' => '#!/usr/bin/env php', 'composer.json' => $laravelComposer]);

    expect((new ProjectTypeDetector)->fromFilesystem($dir))->toBe(ProjectType::LaravelApp);
});

it('returns LaravelPackage when no artisan but illuminate deps exist', function () use ($laravelComposer) {
    $dir = repoDir(['composer.json' => $laravelComposer]);

    expect((new ProjectTypeDetector)->fromFilesystem($dir))->toBe(ProjectType::LaravelPackage);
});

it('returns PhpPackage when composer.json has no type and no Laravel deps', function () use ($libraryComposer) {
    $dir = repoDir(['composer.json' => $libraryComposer]);

    expect((new ProjectTypeDetector)->fromFilesystem($dir))->toBe(ProjectType::PhpPackage);
});

it('returns Unknown when there is no composer.json and no artisan', function () {
    $dir = repoDir([]);

    expect((new ProjectTypeDetector)->fromFilesystem($dir))->toBe(ProjectType::Unknown);
});

it('returns Unknown for a project-type composer.json with no artisan', function () use ($projectComposer) {
    $dir = repoDir(['composer.json' => $projectComposer]);

    expect((new ProjectTypeDetector)->fromFilesystem($dir))->toBe(ProjectType::Unknown);
});

it('prioritises LaravelApp over LaravelPackage when both artisan and illuminate deps are present', function () use ($laravelComposer) {
    $dir = repoDir(['artisan' => '#!/usr/bin/env php', 'composer.json' => $laravelComposer]);

    expect((new ProjectTypeDetector)->fromFilesystem($dir))->toBe(ProjectType::LaravelApp);
});

// ── Git ───────────────────────────────────────────────────────────────────────

it('returns LaravelApp from git when artisan blob is present', function () use ($laravelComposer) {
    ['dir' => $dir, 'commit' => $commit] = gitRepo([
        'artisan' => '#!/usr/bin/env php',
        'composer.json' => $laravelComposer,
    ]);

    expect((new ProjectTypeDetector)->fromGit($dir, $commit))->toBe(ProjectType::LaravelApp);
})->skip(fn () => trim(shell_exec('which git 2>/dev/null')) === '', 'git not available');

it('returns LaravelPackage from git when no artisan but illuminate deps present', function () use ($laravelComposer) {
    ['dir' => $dir, 'commit' => $commit] = gitRepo(['composer.json' => $laravelComposer]);

    expect((new ProjectTypeDetector)->fromGit($dir, $commit))->toBe(ProjectType::LaravelPackage);
})->skip(fn () => trim(shell_exec('which git 2>/dev/null')) === '', 'git not available');

it('returns PhpPackage from git for a plain library composer.json', function () use ($libraryComposer) {
    ['dir' => $dir, 'commit' => $commit] = gitRepo(['composer.json' => $libraryComposer]);

    expect((new ProjectTypeDetector)->fromGit($dir, $commit))->toBe(ProjectType::PhpPackage);
})->skip(fn () => trim(shell_exec('which git 2>/dev/null')) === '', 'git not available');

it('returns Unknown from git when no recognised files are present', function () {
    ['dir' => $dir, 'commit' => $commit] = gitRepo(['README.md' => '# hello']);

    expect((new ProjectTypeDetector)->fromGit($dir, $commit))->toBe(ProjectType::Unknown);
})->skip(fn () => trim(shell_exec('which git 2>/dev/null')) === '', 'git not available');
