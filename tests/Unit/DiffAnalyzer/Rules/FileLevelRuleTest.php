<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\FileLevelRule;

function emptyComparison(): array
{
    return [
        'ast_identical' => true,
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

it('detects new file', function () {
    $file = new FileDiff('/dev/null', 'app/Services/PaymentService.php', FileStatus::ADDED);

    $changes = (new FileLevelRule)->analyze($file, emptyComparison());

    $newFile = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'New file')));

    expect($newFile)->toHaveCount(1)
        ->and($newFile[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($newFile[0]->severity)->toBe(Severity::INFO);
});

it('detects deleted file', function () {
    $file = new FileDiff('app/Services/OldService.php', '/dev/null', FileStatus::DELETED);

    $changes = (new FileLevelRule)->analyze($file, emptyComparison());

    $deleted = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'File deleted')));

    expect($deleted)->toHaveCount(1)
        ->and($deleted[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($deleted[0]->severity)->toBe(Severity::MEDIUM);
});

it('detects renamed file', function () {
    $file = new FileDiff('app/Services/OldService.php', 'app/Services/NewService.php', FileStatus::RENAMED);

    $changes = (new FileLevelRule)->analyze($file, emptyComparison());

    $renamed = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'File renamed')));

    expect($renamed)->toHaveCount(1)
        ->and($renamed[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($renamed[0]->severity)->toBe(Severity::MEDIUM)
        ->and($renamed[0]->description)->toContain('OldService.php')
        ->and($renamed[0]->description)->toContain('NewService.php');
});

it('classifies migration file', function () {
    $file = new FileDiff(
        'database/migrations/2024_01_01_000000_create_users_table.php',
        'database/migrations/2024_01_01_000000_create_users_table.php',
        FileStatus::MODIFIED,
    );

    $changes = (new FileLevelRule)->analyze($file, emptyComparison());

    $migration = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Database migration')));

    expect($migration)->toHaveCount(1)
        ->and($migration[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($migration[0]->severity)->toBe(Severity::MEDIUM);
});

it('classifies controller file', function () {
    $file = new FileDiff(
        'app/Http/Controllers/UserController.php',
        'app/Http/Controllers/UserController.php',
        FileStatus::MODIFIED,
    );

    $changes = (new FileLevelRule)->analyze($file, emptyComparison());

    $controller = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Controller')));

    expect($controller)->toHaveCount(1)
        ->and($controller[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($controller[0]->severity)->toBe(Severity::MEDIUM);
});

it('classifies composer.json', function () {
    $file = new FileDiff('composer.json', 'composer.json', FileStatus::MODIFIED);

    $changes = (new FileLevelRule)->analyze($file, emptyComparison());

    $composer = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Composer dependencies')));

    expect($composer)->toHaveCount(1)
        ->and($composer[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($composer[0]->severity)->toBe(Severity::MEDIUM);
});

it('detects parse errors', function () {
    $comparison = emptyComparison();
    $comparison['new_parse_errors'] = ['Syntax error, unexpected token "}" on line 10'];

    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new FileLevelRule)->analyze($file, $comparison);

    $parseErrors = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Parse errors')));

    expect($parseErrors)->toHaveCount(1)
        ->and($parseErrors[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($parseErrors[0]->severity)->toBe(Severity::MEDIUM)
        ->and($parseErrors[0]->description)->toContain('new version');
});

it('classifies policy file as critical', function () {
    $file = new FileDiff(
        'app/Policies/PostPolicy.php',
        'app/Policies/PostPolicy.php',
        FileStatus::MODIFIED,
    );

    $changes = (new FileLevelRule)->analyze($file, emptyComparison());

    $policy = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Authorization policy')));

    expect($policy)->toHaveCount(1)
        ->and($policy[0]->category)->toBe(ChangeCategory::FILE_LEVEL)
        ->and($policy[0]->severity)->toBe(Severity::VERY_HIGH);
});
