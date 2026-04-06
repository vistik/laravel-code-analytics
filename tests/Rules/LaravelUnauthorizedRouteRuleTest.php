<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelUnauthorizedRouteRule;

function makeComparison(?string $old, ?string $new): array
{
    return (new AstComparer)->compare($old, $new);
}

function routeFile(string $path = 'routes/web.php'): FileDiff
{
    return new FileDiff($path, $path, FileStatus::MODIFIED);
}

it('flags a newly added route without auth middleware', function () {
    $old = '<?php';
    $new = '<?php Route::get("/admin/users", [UserController::class, "index"]);';

    $changes = (new LaravelUnauthorizedRouteRule)->analyze(routeFile(), makeComparison($old, $new));

    expect($changes)->toHaveCount(1);
    expect($changes[0]->severity)->toBe(Severity::HIGH);
    expect($changes[0]->description)->toContain('/admin/users');
    expect($changes[0]->description)->toContain('without authentication middleware');
});

it('does not flag a new route with direct auth middleware', function () {
    $old = '<?php';
    $new = '<?php Route::get("/dashboard", [DashboardController::class, "index"])->middleware("auth");';

    $changes = (new LaravelUnauthorizedRouteRule)->analyze(routeFile(), makeComparison($old, $new));

    expect($changes)->toBeEmpty();
});

it('does not flag a new route with auth:sanctum middleware', function () {
    $old = '<?php';
    $new = '<?php Route::get("/api/me", [UserController::class, "show"])->middleware("auth:sanctum");';

    $changes = (new LaravelUnauthorizedRouteRule)->analyze(routeFile(), makeComparison($old, $new));

    expect($changes)->toBeEmpty();
});

it('does not flag a new route inside a middleware auth group', function () {
    $old = '<?php';
    $new = <<<'PHP'
    <?php
    Route::middleware("auth")->group(function () {
        Route::get("/settings", [SettingsController::class, "index"]);
    });
    PHP;

    $changes = (new LaravelUnauthorizedRouteRule)->analyze(routeFile(), makeComparison($old, $new));

    expect($changes)->toBeEmpty();
});

it('does not flag a new route inside a middleware array auth group', function () {
    $old = '<?php';
    $new = <<<'PHP'
    <?php
    Route::middleware(["auth", "verified"])->group(function () {
        Route::post("/profile", [ProfileController::class, "update"]);
    });
    PHP;

    $changes = (new LaravelUnauthorizedRouteRule)->analyze(routeFile(), makeComparison($old, $new));

    expect($changes)->toBeEmpty();
});

it('flags a route where auth middleware was removed', function () {
    $old = '<?php Route::get("/orders", [OrderController::class, "index"])->middleware("auth");';
    $new = '<?php Route::get("/orders", [OrderController::class, "index"]);';

    $changes = (new LaravelUnauthorizedRouteRule)->analyze(routeFile(), makeComparison($old, $new));

    expect($changes)->toHaveCount(1);
    expect($changes[0]->severity)->toBe(Severity::VERY_HIGH);
    expect($changes[0]->description)->toContain('/orders');
    expect($changes[0]->description)->toContain('removed');
});

it('does not flag an existing unprotected route that was already unprotected', function () {
    $old = '<?php Route::get("/about", fn() => view("about"));';
    $new = '<?php Route::get("/about", fn() => view("about-updated"));';

    $changes = (new LaravelUnauthorizedRouteRule)->analyze(routeFile(), makeComparison($old, $new));

    expect($changes)->toBeEmpty();
});

it('ignores non-route files', function () {
    $diff = new FileDiff('app/Http/Controllers/UserController.php', 'app/Http/Controllers/UserController.php', FileStatus::MODIFIED);
    $new = '<?php Route::get("/secret", fn() => "oops");';

    $changes = (new LaravelUnauthorizedRouteRule)->analyze($diff, makeComparison('<?php', $new));

    expect($changes)->toBeEmpty();
});

it('flags multiple new unprotected routes', function () {
    $old = '<?php';
    $new = <<<'PHP'
    <?php
    Route::get("/invoices", [InvoiceController::class, "index"]);
    Route::post("/invoices", [InvoiceController::class, "store"]);
    PHP;

    $changes = (new LaravelUnauthorizedRouteRule)->analyze(routeFile(), makeComparison($old, $new));

    expect($changes)->toHaveCount(2);

    $descriptions = array_map(fn ($c) => $c->description, $changes);
    expect(implode(' ', $descriptions))->toContain('/invoices');
});
