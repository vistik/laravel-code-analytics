<?php

use Vistik\LaravelCodeAnalytics\Enums\FileGroup;
use Vistik\LaravelCodeAnalytics\Renderers\CakeLayer;
use Vistik\LaravelCodeAnalytics\Renderers\LayeredCakeRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;

test('config file layers are used when no constructor args given', function () {
    config()->set('analysis.layer_stack', new LayerStack(
        new CakeLayer('Custom', '#112233', [FileGroup::ROUTE, FileGroup::MODEL]),
    ));

    $renderer = new LayeredCakeRenderer;
    $js = $renderer->getLayoutSetupJs();

    expect($js)
        ->toContain('route: { layer: 0, label: "Custom" }')
        ->toContain('model: { layer: 0, label: "Custom" }')
        ->toContain('"#112233"');
});

test('default config produces same output as default layers', function () {
    $fromConfig = new LayeredCakeRenderer;
    $fromDefaults = new LayeredCakeRenderer(LayerStack::default());

    expect($fromConfig->getLayoutSetupJs())->toBe($fromDefaults->getLayoutSetupJs());
});
