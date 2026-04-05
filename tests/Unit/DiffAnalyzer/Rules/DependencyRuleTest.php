<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\DependencyRule;

function dependencyComparison(array $old, array $new): array
{
    return [
        'old_source' => json_encode($old),
        'new_source' => json_encode($new),
        'ast_identical' => false,
        'old_nodes' => null,
        'new_nodes' => null,
        'classes' => [],
        'interfaces' => [],
        'enums' => [],
        'functions' => [],
        'methods' => [],
        'properties' => [],
        'class_constants' => [],
        'use_statements' => ['old' => [], 'new' => []],
        'old_parse_errors' => [],
        'new_parse_errors' => [],
    ];
}

it('detects composer production dependency added', function () {
    $comparison = dependencyComparison(
        ['require' => ['laravel/framework' => '^11.0']],
        ['require' => ['laravel/framework' => '^11.0', 'spatie/laravel-query-builder' => '^6.0']],
    );
    $file = new FileDiff('composer.json', 'composer.json', FileStatus::MODIFIED);

    $changes = (new DependencyRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'dependency added')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($added[0]->severity)->toBe(Severity::MEDIUM)
        ->and($added[0]->description)->toContain('spatie/laravel-query-builder')
        ->and($added[0]->description)->toContain('production');
});

it('detects composer dependency removed', function () {
    $comparison = dependencyComparison(
        ['require' => ['laravel/framework' => '^11.0', 'spatie/laravel-query-builder' => '^6.0']],
        ['require' => ['laravel/framework' => '^11.0']],
    );
    $file = new FileDiff('composer.json', 'composer.json', FileStatus::MODIFIED);

    $changes = (new DependencyRule)->analyze($file, $comparison);

    $removed = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'dependency removed')));

    expect($removed)->toHaveCount(1)
        ->and($removed[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($removed[0]->severity)->toBe(Severity::MEDIUM)
        ->and($removed[0]->description)->toContain('spatie/laravel-query-builder');
});

it('detects composer dependency version changed', function () {
    $comparison = dependencyComparison(
        ['require' => ['laravel/framework' => '^11.0']],
        ['require' => ['laravel/framework' => '^12.0']],
    );
    $file = new FileDiff('composer.json', 'composer.json', FileStatus::MODIFIED);

    $changes = (new DependencyRule)->analyze($file, $comparison);

    $versionChanged = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'version changed')));

    expect($versionChanged)->toHaveCount(1)
        ->and($versionChanged[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($versionChanged[0]->severity)->toBe(Severity::MEDIUM)
        ->and($versionChanged[0]->description)->toContain('^11.0')
        ->and($versionChanged[0]->description)->toContain('^12.0');
});

it('detects sensitive package with critical severity', function () {
    $comparison = dependencyComparison(
        ['require' => ['laravel/framework' => '^11.0']],
        ['require' => ['laravel/framework' => '^11.0', 'laravel/sanctum' => '^4.0']],
    );
    $file = new FileDiff('composer.json', 'composer.json', FileStatus::MODIFIED);

    $changes = (new DependencyRule)->analyze($file, $comparison);

    $sanctum = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'laravel/sanctum')));

    expect($sanctum)->toHaveCount(1)
        ->and($sanctum[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($sanctum[0]->category)->toBe(ChangeCategory::FILE_LEVEL);
});

it('detects php version constraint changed', function () {
    $comparison = dependencyComparison(
        ['require' => ['php' => '^8.2', 'laravel/framework' => '^11.0']],
        ['require' => ['php' => '^8.3', 'laravel/framework' => '^11.0']],
    );
    $file = new FileDiff('composer.json', 'composer.json', FileStatus::MODIFIED);

    $changes = (new DependencyRule)->analyze($file, $comparison);

    $phpChange = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'PHP version constraint')));

    expect($phpChange)->toHaveCount(1)
        ->and($phpChange[0]->severity)->toBe(Severity::MEDIUM)
        ->and($phpChange[0]->description)->toContain('^8.2')
        ->and($phpChange[0]->description)->toContain('^8.3');
});

it('detects npm dependency added', function () {
    $comparison = dependencyComparison(
        ['dependencies' => ['react' => '^19.0']],
        ['dependencies' => ['react' => '^19.0', 'axios' => '^1.7']],
    );
    $file = new FileDiff('package.json', 'package.json', FileStatus::MODIFIED);

    $changes = (new DependencyRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'NPM')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($added[0]->severity)->toBe(Severity::INFO)
        ->and($added[0]->description)->toContain('axios');
});

it('detects composer lock file updated', function () {
    $comparison = dependencyComparison([], []);
    $file = new FileDiff('composer.lock', 'composer.lock', FileStatus::MODIFIED);

    $changes = (new DependencyRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($changes[0]->severity)->toBe(Severity::INFO)
        ->and($changes[0]->description)->toContain('Composer lock file updated');
});

it('returns empty for non-dependency files', function () {
    $comparison = dependencyComparison([], []);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DependencyRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
