<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\JsonFileGroupResolver;
use Vistik\LaravelCodeAnalytics\Enums\NodeGroup;

beforeEach(function () {
    $this->jsonPath = tempnam(sys_get_temp_dir(), 'groups_').'.json';
});

afterEach(function () {
    if (file_exists($this->jsonPath)) {
        unlink($this->jsonPath);
    }
});

it('resolves a path to the matching group', function () {
    file_put_contents($this->jsonPath, json_encode([
        'test' => ['^tests/'],
        'model' => ['app/Models/'],
    ]));

    $resolver = new JsonFileGroupResolver($this->jsonPath);

    expect($resolver->resolve('tests/Feature/FooTest.php'))->toBe(NodeGroup::TEST)
        ->and($resolver->resolve('app/Models/User.php'))->toBe(NodeGroup::MODEL);
});

it('returns other when no pattern matches', function () {
    file_put_contents($this->jsonPath, json_encode([
        'model' => ['app/Models/'],
    ]));

    $resolver = new JsonFileGroupResolver($this->jsonPath);

    expect($resolver->resolve('app/Services/FooService.php'))->toBe(NodeGroup::OTHER);
});

it('matches the first group when multiple patterns could match', function () {
    file_put_contents($this->jsonPath, json_encode([
        'http' => ['app/Http/'],
        'controller' => ['app/Http/Controllers/'],
    ]));

    $resolver = new JsonFileGroupResolver($this->jsonPath);

    expect($resolver->resolve('app/Http/Controllers/FooController.php'))->toBe(NodeGroup::HTTP);
});

it('throws when the file does not exist', function () {
    new JsonFileGroupResolver('/tmp/does-not-exist-abc123.json');
})->throws(RuntimeException::class);

it('throws when the file contains invalid json', function () {
    file_put_contents($this->jsonPath, 'not json');

    new JsonFileGroupResolver($this->jsonPath);
})->throws(InvalidArgumentException::class);
