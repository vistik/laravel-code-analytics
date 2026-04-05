<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\ArrayFileGroupResolver;
use Vistik\LaravelCodeAnalytics\Enums\NodeGroup;

it('resolves a path to the matching group', function () {
    $resolver = new ArrayFileGroupResolver([
        'test' => ['^tests/'],
        'model' => ['app/Models/'],
    ]);

    expect($resolver->resolve('tests/Feature/FooTest.php'))->toBe(NodeGroup::TEST);
    expect($resolver->resolve('app/Models/User.php'))->toBe(NodeGroup::MODEL);
});

it('returns OTHER when no pattern matches', function () {
    $resolver = new ArrayFileGroupResolver([
        'model' => ['app/Models/'],
    ]);

    expect($resolver->resolve('app/Http/Controllers/FooController.php'))->toBe(NodeGroup::OTHER);
});

it('returns OTHER for an unknown group key', function () {
    $resolver = new ArrayFileGroupResolver([
        'not_a_real_group' => ['some/path/'],
    ]);

    expect($resolver->resolve('some/path/file.php'))->toBe(NodeGroup::OTHER);
});

it('uses the first matching group when multiple patterns match', function () {
    $resolver = new ArrayFileGroupResolver([
        'test' => ['^tests/'],
        'model' => ['tests/'],  // would also match, but test wins
    ]);

    expect($resolver->resolve('tests/Feature/FooTest.php'))->toBe(NodeGroup::TEST);
});
