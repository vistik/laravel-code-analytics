<?php

use Vistik\LaravelCodeAnalytics\Endpoints\AffectedEndpoint;
use Vistik\LaravelCodeAnalytics\Endpoints\AffectedEndpointResolver;
use Vistik\LaravelCodeAnalytics\Endpoints\RouteDefinition;
use Vistik\LaravelCodeAnalytics\Endpoints\RouteIndexBuilder;

// ── RouteDefinition.sourceFile ────────────────────────────────────────────────

it('RouteDefinition stores sourceFile and defaults to empty string', function () {
    $route = new RouteDefinition('GET', '/users', null, [], 'App\\Http\\Controllers\\UserController', 'index');

    expect($route->sourceFile)->toBe('');
});

it('RouteDefinition accepts a sourceFile', function () {
    $route = new RouteDefinition('GET', '/users', null, [], 'App\\Http\\Controllers\\UserController', 'index', 'routes/api.php');

    expect($route->sourceFile)->toBe('routes/api.php');
});

// ── AffectedEndpoint.toArray() includes sourceFile ────────────────────────────

it('AffectedEndpoint.toArray() includes sourceFile key', function () {
    $route = new RouteDefinition('GET', '/users', null, [], 'App\\Http\\Controllers\\UserController', 'index', 'routes/api.php');
    $endpoint = new AffectedEndpoint($route, 'app/Http/Controllers/UserController.php', ['app/Http/Controllers/UserController.php']);

    $arr = $endpoint->toArray();

    expect($arr)->toHaveKey('sourceFile');
    expect($arr['sourceFile'])->toBe('routes/api.php');
});

it('AffectedEndpoint.toArray() sourceFile is empty string when not set', function () {
    $route = new RouteDefinition('POST', '/orders', null, [], 'App\\Http\\Controllers\\OrderController', 'store');
    $endpoint = new AffectedEndpoint($route, 'app/Http/Controllers/OrderController.php', []);

    $arr = $endpoint->toArray();

    expect($arr['sourceFile'])->toBe('');
});

// ── RouteIndexBuilder stamps sourceFile ───────────────────────────────────────

it('RouteIndexBuilder stamps the route file path as sourceFile on each route', function () {
    $source = <<<'PHP'
    <?php
    use App\Http\Controllers\UserController;
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    PHP;

    $index = (new RouteIndexBuilder)->build(
        routeFileContents: ['routes/api.php' => $source],
        fqcnToPath: fn (string $fqcn) => match ($fqcn) {
            'App\\Http\\Controllers\\UserController' => 'app/Http/Controllers/UserController.php',
            default => null,
        },
    );

    expect($index)->toHaveKey('app/Http/Controllers/UserController.php');

    foreach ($index['app/Http/Controllers/UserController.php'] as $route) {
        expect($route->sourceFile)->toBe('routes/api.php');
    }
});

it('routes from different files get distinct sourceFile values', function () {
    $apiSource = <<<'PHP'
    <?php
    use App\Http\Controllers\ApiController;
    Route::get('/api/data', [ApiController::class, 'index']);
    PHP;

    $webSource = <<<'PHP'
    <?php
    use App\Http\Controllers\WebController;
    Route::get('/home', [WebController::class, 'index']);
    PHP;

    $index = (new RouteIndexBuilder)->build(
        routeFileContents: [
            'routes/api.php' => $apiSource,
            'routes/web.php' => $webSource,
        ],
        fqcnToPath: fn (string $fqcn) => match ($fqcn) {
            'App\\Http\\Controllers\\ApiController' => 'app/Http/Controllers/ApiController.php',
            'App\\Http\\Controllers\\WebController' => 'app/Http/Controllers/WebController.php',
            default => null,
        },
    );

    expect($index['app/Http/Controllers/ApiController.php'][0]->sourceFile)->toBe('routes/api.php');
    expect($index['app/Http/Controllers/WebController.php'][0]->sourceFile)->toBe('routes/web.php');
});

// ── sourceFile is included end-to-end through AffectedEndpointResolver ────────

it('sourceFile propagates through AffectedEndpointResolver into toArray()', function () {
    $source = <<<'PHP'
    <?php
    use App\Http\Controllers\OrderController;
    Route::post('/orders', [OrderController::class, 'store']);
    PHP;

    $routeIndex = (new RouteIndexBuilder)->build(
        routeFileContents: ['routes/api.php' => $source],
        fqcnToPath: fn (string $fqcn) => $fqcn === 'App\\Http\\Controllers\\OrderController'
            ? 'app/Http/Controllers/OrderController.php'
            : null,
    );

    $result = (new AffectedEndpointResolver)->resolve(
        routeIndex: $routeIndex,
        edges: [],
        nodeIdToPath: ['ctrl-node' => 'app/Http/Controllers/OrderController.php'],
        diffNodes: [['id' => 'ctrl-node', 'path' => 'app/Http/Controllers/OrderController.php']],
    );

    expect($result)->toHaveCount(1);
    expect($result[0]->toArray()['sourceFile'])->toBe('routes/api.php');
});
