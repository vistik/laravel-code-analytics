<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\ArrayFileGroupResolver;
use Vistik\LaravelCodeAnalytics\Enums\FileGroup;

it('resolves a path to the matching group', function () {
    $resolver = new ArrayFileGroupResolver([
        'test' => ['^tests/'],
        'model' => ['app/Models/'],
    ]);

    expect($resolver->resolve('tests/Feature/FooTest.php'))->toBe(FileGroup::TEST);
    expect($resolver->resolve('app/Models/User.php'))->toBe(FileGroup::MODEL);
});

it('returns OTHER when no pattern matches', function () {
    $resolver = new ArrayFileGroupResolver([
        'model' => ['app/Models/'],
    ]);

    expect($resolver->resolve('app/Http/Controllers/FooController.php'))->toBe(FileGroup::OTHER);
});

it('returns OTHER for an unknown group key', function () {
    $resolver = new ArrayFileGroupResolver([
        'not_a_real_group' => ['some/path/'],
    ]);

    expect($resolver->resolve('some/path/file.php'))->toBe(FileGroup::OTHER);
});

it('uses the first matching group when multiple patterns match', function () {
    $resolver = new ArrayFileGroupResolver([
        'test' => ['^tests/'],
        'model' => ['tests/'],  // would also match, but test wins
    ]);

    expect($resolver->resolve('tests/Feature/FooTest.php'))->toBe(FileGroup::TEST);
});
