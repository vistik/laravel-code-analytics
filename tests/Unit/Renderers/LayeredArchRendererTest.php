<?php

use Vistik\LaravelCodeAnalytics\Enums\NodeGroup;
use Vistik\LaravelCodeAnalytics\Renderers\CakeLayer;
use Vistik\LaravelCodeAnalytics\Renderers\LayeredArchRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;

test('default layers produce valid JS with all node groups', function () {
    $renderer = new LayeredArchRenderer(LayerStack::default());
    $js = $renderer->getLayoutSetupJs();

    foreach (NodeGroup::cases() as $group) {
        expect($js)->toContain("{$group->value}: { layer:");
    }
});

test('custom layers override defaults', function () {
    $stack = new LayerStack(
        new CakeLayer('Left', '#ff0000', [NodeGroup::ROUTE, NodeGroup::HTTP]),
        new CakeLayer('Right', '#00ff00', [NodeGroup::MODEL, NodeGroup::TEST]),
    );

    $renderer = new LayeredArchRenderer($stack);
    $js = $renderer->getLayoutSetupJs();

    expect($js)
        ->toContain('route: { layer: 0, label: "Left" }')
        ->toContain('http: { layer: 0, label: "Left" }')
        ->toContain('model: { layer: 1, label: "Right" }')
        ->toContain('test: { layer: 1, label: "Right" }')
        ->toContain('"#ff0000"')
        ->toContain('"#00ff00"');
});

test('fallback layer index matches last layer', function () {
    $stack = new LayerStack(
        new CakeLayer('Only', '#aabbcc', [NodeGroup::ROUTE]),
    );

    $renderer = new LayeredArchRenderer($stack);
    $js = $renderer->getLayoutSetupJs();

    expect($js)->toContain('|| { layer: 0, label: \'Other\' }');
});

test('layout JS positions nodes in columns and pins them', function () {
    $renderer = new LayeredArchRenderer(LayerStack::default());
    $js = $renderer->getLayoutSetupJs();

    expect($js)
        ->toContain('archColumnData')
        ->toContain('colWidth')
        ->toContain('midX')
        ->toContain('pinned = true')
        ->not->toContain('cakeRingData')
        ->not->toContain('Math.PI * 2');
});

test('simulation JS is a no-op since nodes are pinned', function () {
    $renderer = new LayeredArchRenderer(LayerStack::default());
    $js = $renderer->getSimulationJs();

    expect($js)
        ->toContain('function simulate()')
        ->not->toContain('radialStrength')
        ->not->toContain('cakePinned');
});

test('frame hook JS draws column bands', function () {
    $renderer = new LayeredArchRenderer(LayerStack::default());
    $js = $renderer->getFrameHookJs();

    expect($js)
        ->toContain('archColumnData')
        ->toContain('fillRect')
        ->not->toContain('arc(');
});
