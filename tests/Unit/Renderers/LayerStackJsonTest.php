<?php

use Vistik\LaravelCodeAnalytics\Enums\NodeGroup;
use Vistik\LaravelCodeAnalytics\Renderers\CakeLayer;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;

test('toArray returns correct structure', function () {
    $stack = new LayerStack(
        new CakeLayer('Entry', '#ffa657', [NodeGroup::ROUTE, NodeGroup::CONFIG]),
        new CakeLayer('Domain', '#3fb950', [NodeGroup::MODEL]),
    );

    expect($stack->toArray())->toBe([
        'layers' => [
            ['label' => 'Entry', 'color' => '#ffa657', 'groups' => ['route', 'config']],
            ['label' => 'Domain', 'color' => '#3fb950', 'groups' => ['model']],
        ],
    ]);
});

test('fromArray reconstructs identical stack', function () {
    $original = LayerStack::default();
    $restored = LayerStack::fromArray($original->toArray());

    expect($restored->toArray())->toBe($original->toArray());
});

test('fromArray round-trips through JSON', function () {
    $original = LayerStack::default();
    $json = json_encode($original->toArray(), JSON_PRETTY_PRINT);
    $restored = LayerStack::fromArray(json_decode($json, true));

    expect($restored->buildLayerMapJs())->toBe($original->buildLayerMapJs());
    expect($restored->buildColorsJs())->toBe($original->buildColorsJs());
    expect($restored->buildLabelsJs())->toBe($original->buildLabelsJs());
});

test('fromArray rejects duplicate groups', function () {
    LayerStack::fromArray([
        'layers' => [
            ['label' => 'A', 'color' => '#ff0000', 'groups' => ['route']],
            ['label' => 'B', 'color' => '#00ff00', 'groups' => ['route']],
        ],
    ]);
})->throws(InvalidArgumentException::class);
