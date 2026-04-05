<?php

use Vistik\LaravelCodeAnalytics\Renderers\TreeRenderer;

test('layout JS uses entry-point groups as roots', function () {
    $renderer = new TreeRenderer;
    $js = $renderer->getLayoutSetupJs();

    expect($js)
        ->toContain("var entryGroups = ['route', 'http', 'controller', 'job', 'console']")
        ->toContain('entryGroups.indexOf(n.group) !== -1');
});

test('layout JS falls back to topological roots when no entry points present', function () {
    $renderer = new TreeRenderer;
    $js = $renderer->getLayoutSetupJs();

    expect($js)->toContain('incoming[n.id].length === 0');
});

test('layout JS falls back to most-connected node when all cycles', function () {
    $renderer = new TreeRenderer;
    $js = $renderer->getLayoutSetupJs();

    expect($js)->toContain('outgoing[b.id] || []).length - (outgoing[a.id]');
});

test('simulation JS is a no-op since nodes are pinned', function () {
    $renderer = new TreeRenderer;

    expect($renderer->getSimulationJs())->toContain('no physics');
});

test('layout JS pins nodes and positions them left-to-right', function () {
    $renderer = new TreeRenderer;
    $js = $renderer->getLayoutSetupJs();

    expect($js)
        ->toContain('pinned')
        ->toContain('xSpacing')
        ->toContain('ySpacing');
});
