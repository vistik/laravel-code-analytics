<?php

use Vistik\LaravelCodeAnalytics\Coupling\NamedEntityClusterer;

it('returns empty array when no nodes given', function () {
    expect((new NamedEntityClusterer)->cluster([], []))->toBe([]);
});

it('groups User, UserController, CreateUserRequest into one cluster', function () {
    $changed = ['User', 'UserController', 'CreateUserRequest', 'UpdateUserRequest'];

    $result = (new NamedEntityClusterer)->cluster([], $changed, minClusterSize: 3);

    expect($result)->toHaveCount(1)
        ->and($result[0]['files'])->toContain('User', 'UserController', 'CreateUserRequest', 'UpdateUserRequest');
});

it('creates separate clusters for distinct entities', function () {
    $changed = [
        'Order', 'OrderController', 'CreateOrderRequest',
        'Invoice', 'InvoiceController', 'CreateInvoiceRequest',
    ];

    $result = (new NamedEntityClusterer)->cluster([], $changed, minClusterSize: 3);

    expect($result)->toHaveCount(2);

    $roots = array_map(fn ($c) => $c['files'], $result);
    $allFiles = array_merge(...$roots);
    expect($allFiles)->toContain('Order', 'OrderController', 'Invoice', 'InvoiceController');
});

it('strips action prefixes correctly', function () {
    $changed = [
        'User',
        'CreateUserAction',
        'UpdateUserAction',
        'DeleteUserAction',
    ];

    $result = (new NamedEntityClusterer)->cluster([], $changed, minClusterSize: 3);

    expect($result)->toHaveCount(1)
        ->and($result[0]['files'])->toContain('User', 'CreateUserAction', 'UpdateUserAction', 'DeleteUserAction');
});

it('strips service and repository suffixes', function () {
    $changed = ['Payment', 'PaymentService', 'PaymentRepository', 'PaymentFactory'];

    $result = (new NamedEntityClusterer)->cluster([], $changed, minClusterSize: 3);

    expect($result)->toHaveCount(1)
        ->and($result[0]['files'])->toContain('Payment', 'PaymentService', 'PaymentRepository');
});

it('pulls in dependency-connected nodes with no name match', function () {
    // BaseController has no "User" in its name but depends on UserController
    // and User — if it connects to 2+ User-group nodes it should be pulled in
    $changed = ['User', 'UserController', 'CreateUserRequest', 'BaseController'];
    $edges = [
        ['BaseController', 'UserController'],
        ['BaseController', 'User'],
    ];

    $result = (new NamedEntityClusterer)->cluster($edges, $changed, minClusterSize: 3);

    expect($result)->toHaveCount(1)
        ->and($result[0]['files'])->toContain('BaseController');
});

it('does not pull in a node connected to only one group member', function () {
    $changed = ['User', 'UserController', 'CreateUserRequest', 'Unrelated'];
    $edges = [
        ['Unrelated', 'User'],
    ];

    $result = (new NamedEntityClusterer)->cluster($edges, $changed, minClusterSize: 3);

    expect($result)->toHaveCount(1);
    $allFiles = $result[0]['files'];
    expect($allFiles)->not->toContain('Unrelated');
});

it('handles domain-prefixed node ids', function () {
    // Collision-prefixed IDs like "Http/UserController"
    $changed = ['User', 'Http/UserController', 'Http/CreateUserRequest'];

    $result = (new NamedEntityClusterer)->cluster([], $changed, minClusterSize: 3);

    expect($result)->toHaveCount(1)
        ->and($result[0]['files'])->toContain('User', 'Http/UserController', 'Http/CreateUserRequest');
});

it('requires at least two name-matched nodes before forming a group', function () {
    // Only one file matches "Order" root — not enough for a named group
    $changed = ['OrderController', 'SomethingElse', 'AnotherThing'];

    $result = (new NamedEntityClusterer)->cluster([], $changed, minClusterSize: 3);

    expect($result)->toBe([]);
});

it('respects the minClusterSize parameter', function () {
    $changed = ['User', 'UserController']; // only 2 in the group

    $result = (new NamedEntityClusterer)->cluster([], $changed, minClusterSize: 3);

    expect($result)->toBe([]);
});

it('sorts clusters by size descending', function () {
    $changed = [
        'Order', 'OrderController', 'CreateOrderRequest', 'UpdateOrderRequest',
        'Invoice', 'InvoiceController', 'CreateInvoiceRequest',
    ];

    $result = (new NamedEntityClusterer)->cluster([], $changed, minClusterSize: 3);

    expect($result)->toHaveCount(2)
        ->and($result[0]['size'])->toBeGreaterThanOrEqual($result[1]['size']);
});

it('implements the Clusterer interface', function () {
    expect(new NamedEntityClusterer)->toBeInstanceOf(\Vistik\LaravelCodeAnalytics\Coupling\Clusterer::class);
});
