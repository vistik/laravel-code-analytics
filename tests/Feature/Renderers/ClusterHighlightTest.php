<?php

test('inner blade template supports highlightCluster message handler', function () {
    $viewPath = realpath(__DIR__.'/../../../resources/views/analysis/inner.blade.php');

    $content = file_get_contents($viewPath);

    expect($content)
        ->toContain('highlightCluster')
        ->toContain('clusterPaths');
});

test('inner blade template dims non-cluster nodes when cluster is active', function () {
    $viewPath = realpath(__DIR__.'/../../../resources/views/analysis/inner.blade.php');

    $content = file_get_contents($viewPath);

    expect($content)
        ->toContain('isClusterNode')
        ->toContain('clusterPaths.has(n.path)');
});

test('inner blade template dims non-cluster edges when cluster is active', function () {
    $viewPath = realpath(__DIR__.'/../../../resources/views/analysis/inner.blade.php');

    $content = file_get_contents($viewPath);

    expect($content)->toContain('isClusterEdge');
});
