<?php

use Vistik\LaravelCodeAnalytics\Actions\DependencyRules\BladeDependencyRule;

it('detects @extends', function () {
    $content = "@extends('layouts.app')";

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe(['resources/views/layouts/app.blade.php']);
});

it('detects @include', function () {
    $content = "@include('partials.header')";

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe(['resources/views/partials/header.blade.php']);
});

it('detects @includeIf', function () {
    $content = "@includeIf('partials.sidebar')";

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe(['resources/views/partials/sidebar.blade.php']);
});

it('detects @includeWhen (second arg)', function () {
    $content = "@includeWhen(\$show, 'partials.header')";

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe(['resources/views/partials/header.blade.php']);
});

it('detects @includeUnless (second arg)', function () {
    $content = "@includeUnless(\$hidden, 'partials.footer')";

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe(['resources/views/partials/footer.blade.php']);
});

it('detects @includeFirst (all views in array)', function () {
    $content = "@includeFirst(['partials.header', 'partials.fallback'])";

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe([
        'resources/views/partials/header.blade.php',
        'resources/views/partials/fallback.blade.php',
    ]);
});

it('detects @each', function () {
    $content = "@each('partials.item', \$items, 'item')";

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe(['resources/views/partials/item.blade.php']);
});

it('detects @component', function () {
    $content = "@component('components.alert')";

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe(['resources/views/components/alert.blade.php']);
});

it('detects simple <x-component> tags', function () {
    $content = '<x-alert />';

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe(['resources/views/components/alert.blade.php']);
});

it('detects nested <x-namespace.component> tags', function () {
    $content = '<x-forms.input />';

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe(['resources/views/components/forms/input.blade.php']);
});

it('detects <x-component> with attributes', function () {
    $content = '<x-alert type="danger" message="Oops" />';

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe(['resources/views/components/alert.blade.php']);
});

it('detects multiple directives in the same file', function () {
    $content = <<<'BLADE'
@extends('layouts.app')

@include('partials.header')
<x-alert />
BLADE;

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe([
        'resources/views/layouts/app.blade.php',
        'resources/views/partials/header.blade.php',
        'resources/views/components/alert.blade.php',
    ]);
});

it('deduplicates repeated references to the same view', function () {
    $content = <<<'BLADE'
@include('partials.header')
@include('partials.header')
BLADE;

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe(['resources/views/partials/header.blade.php']);
});

it('returns empty array when there are no blade directives', function () {
    $content = '<div>Hello world</div>';

    $paths = (new BladeDependencyRule)->resolve($content);

    expect($paths)->toBe([]);
});
