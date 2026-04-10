<?php

use Vistik\LaravelCodeAnalytics\Enums\FileGroup;
use Vistik\LaravelCodeAnalytics\Renderers\CakeLayer;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;

test('rejects duplicate node group across layers', function () {
    new LayerStack(
        new CakeLayer('A', '#ff0000', [FileGroup::ROUTE, FileGroup::HTTP]),
        new CakeLayer('B', '#00ff00', [FileGroup::HTTP, FileGroup::MODEL]),
    );
})->throws(InvalidArgumentException::class, 'FileGroup http appears in both "A" and "B"');

test('rejects duplicate color across layers', function () {
    new LayerStack(
        new CakeLayer('A', '#ff0000', [FileGroup::ROUTE]),
        new CakeLayer('B', '#FF0000', [FileGroup::MODEL]),
    );
})->throws(InvalidArgumentException::class, 'Duplicate layer color: #FF0000 (used by "A" and "B")');

test('allows distinct colors and non-overlapping groups', function () {
    $stack = new LayerStack(
        new CakeLayer('A', '#ff0000', [FileGroup::ROUTE]),
        new CakeLayer('B', '#00ff00', [FileGroup::MODEL]),
    );

    expect($stack)->toHaveCount(2);
});

test('default stack passes validation', function () {
    expect(LayerStack::default())->toHaveCount(8);
});
