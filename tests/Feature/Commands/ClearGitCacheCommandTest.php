<?php

use Illuminate\Support\Facades\File;

function makeBareRepo(string $dir, string $origin): void
{
    File::ensureDirectoryExists($dir);
    shell_exec('git init --bare '.escapeshellarg($dir).' 2>&1');
    shell_exec('git -C '.escapeshellarg($dir).' remote add origin '.escapeshellarg($origin).' 2>&1');
}

afterEach(function () {
    File::deleteDirectory(storage_path('app/git-objects'));
    File::deleteDirectory(storage_path('app/pr-cache'));
});

it('reports nothing to clear when the cache is empty', function () {
    $this->artisan('code:clear-cache')
        ->expectsOutputToContain('Nothing to clear')
        ->assertSuccessful();
});

it('clears the cache when the confirmation is accepted', function () {
    $repoDir = storage_path('app/git-objects/ab/abc123');
    makeBareRepo($repoDir, 'https://github.com/laravel/framework.git');

    $this->artisan('code:clear-cache')
        ->expectsConfirmation('Continue?', 'yes')
        ->expectsOutputToContain('Cleared')
        ->assertSuccessful();

    expect(File::isDirectory($repoDir))->toBeFalse();
});

it('does not delete anything when the confirmation is declined', function () {
    $repoDir = storage_path('app/git-objects/ab/abc123');
    makeBareRepo($repoDir, 'https://github.com/laravel/framework.git');

    $this->artisan('code:clear-cache')
        ->expectsConfirmation('Continue?', 'no')
        ->assertSuccessful();

    expect(File::isDirectory($repoDir))->toBeTrue();
});

it('clears the cache with --force and no prompt', function () {
    $repoDir = storage_path('app/git-objects/ab/abc123');
    makeBareRepo($repoDir, 'https://github.com/laravel/framework.git');
    File::ensureDirectoryExists(storage_path('app/pr-cache/ab'));
    File::put(storage_path('app/pr-cache/ab/abc123.diff'), 'diff --git a/x b/x');

    $this->artisan('code:clear-cache', ['--force' => true])
        ->expectsOutputToContain('Cleared')
        ->assertSuccessful();

    expect(File::isDirectory(storage_path('app/git-objects')))->toBeFalse();
    expect(File::isDirectory(storage_path('app/pr-cache')))->toBeFalse();
});

it('only clears the cache for the given repo', function () {
    $framework = storage_path('app/git-objects/ab/abc123');
    $cloud = storage_path('app/git-objects/cd/def456');
    makeBareRepo($framework, 'https://github.com/laravel/framework.git');
    makeBareRepo($cloud, 'https://github.com/laravel/cloud.git');

    $this->artisan('code:clear-cache', ['repo' => 'laravel/framework', '--force' => true])
        ->expectsOutputToContain('Cleared')
        ->assertSuccessful();

    expect(File::isDirectory($framework))->toBeFalse();
    expect(File::isDirectory($cloud))->toBeTrue();
});

it('reports no match when the repo has no cached objects', function () {
    $this->artisan('code:clear-cache', ['repo' => 'laravel/nova', '--force' => true])
        ->expectsOutputToContain('No cached git objects found for laravel/nova')
        ->assertSuccessful();
});
