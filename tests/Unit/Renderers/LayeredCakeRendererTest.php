<?php

use Vistik\LaravelCodeAnalytics\Enums\FileGroup;
use Vistik\LaravelCodeAnalytics\Renderers\CakeLayer;
use Vistik\LaravelCodeAnalytics\Renderers\LayeredCakeRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;

test('default layers produce valid JS with all node groups', function () {
    $renderer = new LayeredCakeRenderer(LayerStack::default());
    $js = $renderer->getLayoutSetupJs();

    foreach (FileGroup::cases() as $group) {
        expect($js)->toContain("{$group->value}: { layer:");
    }
});

test('default layers contain 8 layers', function () {
    expect(LayerStack::default())->toHaveCount(8);
});

test('custom layers override defaults', function () {
    $stack = new LayerStack(
        new CakeLayer('Outer', '#ff0000', [FileGroup::ROUTE, FileGroup::HTTP]),
        new CakeLayer('Inner', '#00ff00', [FileGroup::MODEL, FileGroup::TEST]),
    );

    $renderer = new LayeredCakeRenderer($stack);
    $js = $renderer->getLayoutSetupJs();

    expect($js)
        ->toContain('route: { layer: 0, label: "Outer" }')
        ->toContain('http: { layer: 0, label: "Outer" }')
        ->toContain('model: { layer: 1, label: "Inner" }')
        ->toContain('test: { layer: 1, label: "Inner" }')
        ->toContain('"#ff0000"')
        ->toContain('"#00ff00"');
});

test('fallback layer index matches last layer', function () {
    $stack = new LayerStack(
        new CakeLayer('Only', '#aabbcc', [FileGroup::ROUTE]),
    );

    $renderer = new LayeredCakeRenderer($stack);
    $js = $renderer->getLayoutSetupJs();

    // Fallback for unmapped groups should point to last layer (index 0 in this case)
    expect($js)->toContain('|| { layer: 0, label: \'Other\' }');
});

test('simulation and frame hook JS are unchanged regardless of layer config', function () {
    $a = new LayeredCakeRenderer(LayerStack::default());
    $b = new LayeredCakeRenderer(new LayerStack(
        new CakeLayer('X', '#000', [FileGroup::ROUTE]),
    ));

    expect($a->getSimulationJs())->toBe($b->getSimulationJs());
    expect($a->getFrameHookJs())->toBe($b->getFrameHookJs());
});
