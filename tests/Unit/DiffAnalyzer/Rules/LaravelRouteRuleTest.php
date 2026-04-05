<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelRouteRule;

it('detects new route added', function () {
    $old = '<?php use Illuminate\Support\Facades\Route; Route::get("/users", function () { return "users"; });';
    $new = '<?php use Illuminate\Support\Facades\Route; Route::get("/users", function () { return "users"; }); Route::get("/posts", function () { return "posts"; });';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('routes/web.php', 'routes/web.php', FileStatus::MODIFIED);

    $changes = (new LaravelRouteRule)->analyze($file, $comparison);

    $routeAdded = collect($changes)->first(fn ($c) => str_contains($c->description, 'Route added'));

    expect($routeAdded)->not->toBeNull()
        ->and($routeAdded->category)->toBe(ChangeCategory::LARAVEL)
        ->and($routeAdded->severity)->toBe(Severity::MEDIUM)
        ->and($routeAdded->description)->toContain('GET')
        ->and($routeAdded->description)->toContain('/posts');
});

it('detects route removed', function () {
    $old = '<?php use Illuminate\Support\Facades\Route; Route::get("/users", function () { return "users"; }); Route::get("/posts", function () { return "posts"; });';
    $new = '<?php use Illuminate\Support\Facades\Route; Route::get("/users", function () { return "users"; });';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('routes/web.php', 'routes/web.php', FileStatus::MODIFIED);

    $changes = (new LaravelRouteRule)->analyze($file, $comparison);

    $routeRemoved = collect($changes)->first(fn ($c) => str_contains($c->description, 'Route removed'));

    expect($routeRemoved)->not->toBeNull()
        ->and($routeRemoved->category)->toBe(ChangeCategory::LARAVEL)
        ->and($routeRemoved->severity)->toBe(Severity::VERY_HIGH)
        ->and($routeRemoved->description)->toContain('/posts');
});

it('ignores non-route files', function () {
    $old = '<?php use Illuminate\Support\Facades\Route; Route::get("/users", function () { return "users"; });';
    $new = '<?php use Illuminate\Support\Facades\Route; Route::get("/users", function () { return "users"; }); Route::post("/users", function () { return "created"; });';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Controllers/UserController.php', 'app/Http/Controllers/UserController.php', FileStatus::MODIFIED);

    $changes = (new LaravelRouteRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
