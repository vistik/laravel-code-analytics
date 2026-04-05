<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelEnvironmentRule;

it('detects app()->isProduction() added', function () {
    $old = '<?php class Foo { public function handle(): void { $this->doWork(); } }';
    $new = '<?php class Foo { public function handle(): void { if (app()->isProduction()) { $this->doWork(); } } }';

    $comparison = (new AstComparer)->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new LaravelEnvironmentRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'added')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($added[0]->severity)->toBe(Severity::MEDIUM)
        ->and($added[0]->description)->toContain('isProduction');
});

it('detects app()->environment() with string args added', function () {
    $old = '<?php class Foo { public function handle(): void { return true; } }';
    $new = '<?php class Foo { public function handle(): void { if (app()->environment("production")) { return true; } } }';

    $comparison = (new AstComparer)->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new LaravelEnvironmentRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'added')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->description)->toContain("environment('production')");
});

it('detects App::environment() static facade call added', function () {
    $old = '<?php class Foo { public function handle(): void { return; } }';
    $new = '<?php class Foo { public function handle(): void { if (App::environment("staging", "local")) { return; } } }';

    $comparison = (new AstComparer)->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new LaravelEnvironmentRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'added')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->description)->toContain("App::environment('staging', 'local')");
});

it('detects environment check removed with INFO severity', function () {
    $old = '<?php class Foo { public function handle(): void { if (app()->isProduction()) { $this->doWork(); } } }';
    $new = '<?php class Foo { public function handle(): void { $this->doWork(); } }';

    $comparison = (new AstComparer)->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new LaravelEnvironmentRule)->analyze($file, $comparison);

    $removed = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'removed')));

    expect($removed)->toHaveCount(1)
        ->and($removed[0]->severity)->toBe(Severity::INFO)
        ->and($removed[0]->description)->toContain('isProduction');
});

it('detects environment check in newly added method', function () {
    $old = '<?php class Foo { }';
    $new = '<?php class Foo { public function handle(): void { if (app()->isLocal()) { dump("debug"); } } }';

    $comparison = (new AstComparer)->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new LaravelEnvironmentRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'added')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->description)->toContain('isLocal');
});

it('returns no changes when environment checks are unchanged', function () {
    $code = '<?php class Foo { public function handle(): void { if (app()->isProduction()) { $this->doWork(); } } }';

    $comparison = (new AstComparer)->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new LaravelEnvironmentRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('ignores non-environment method calls on app()', function () {
    $old = '<?php class Foo { public function handle(): void { return app()->make(Bar::class); } }';
    $new = '<?php class Foo { public function handle(): void { return app()->make(Baz::class); } }';

    $comparison = (new AstComparer)->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new LaravelEnvironmentRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
