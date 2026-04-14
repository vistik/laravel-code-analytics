<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\NewPhpDependencyRule;

function phpDepComparison(string $old, string $new): array
{
    return (new AstComparer)->compare($old, $new);
}

it('detects new static call dependency', function () {
    $old = '<?php class Foo { public function handle() { return 1; } }';
    $new = '<?php class Foo { public function handle() { return Cache::get("key"); } }';

    $comparison = phpDepComparison($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new NewPhpDependencyRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Cache')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->category)->toBe(ChangeCategory::DEPENDENCY)
        ->and($added[0]->severity)->toBe(Severity::LOW)
        ->and($added[0]->description)->toContain('static call');
});

it('detects new instantiation dependency', function () {
    $old = '<?php class Foo { public function build() { return []; } }';
    $new = '<?php class Foo { public function build() { return new Builder(); } }';

    $comparison = phpDepComparison($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new NewPhpDependencyRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Builder')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->category)->toBe(ChangeCategory::DEPENDENCY)
        ->and($added[0]->severity)->toBe(Severity::LOW)
        ->and($added[0]->description)->toContain('new instance');
});

it('detects new extends dependency', function () {
    $old = '<?php class Foo {}';
    $new = '<?php class Foo extends BaseController {}';

    $comparison = phpDepComparison($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new NewPhpDependencyRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'BaseController')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->category)->toBe(ChangeCategory::DEPENDENCY)
        ->and($added[0]->severity)->toBe(Severity::MEDIUM)
        ->and($added[0]->description)->toContain('extends');
});

it('detects new implements dependency', function () {
    $old = '<?php class Foo {}';
    $new = '<?php class Foo implements Countable {}';

    $comparison = phpDepComparison($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new NewPhpDependencyRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Countable')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->category)->toBe(ChangeCategory::DEPENDENCY)
        ->and($added[0]->severity)->toBe(Severity::LOW)
        ->and($added[0]->description)->toContain('implements');
});

it('detects new container resolution dependency', function () {
    $old = '<?php class Foo { public function run() {} }';
    $new = '<?php class Foo { public function run() { $svc = app(PaymentService::class); } }';

    $comparison = phpDepComparison($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new NewPhpDependencyRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'PaymentService')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->category)->toBe(ChangeCategory::DEPENDENCY)
        ->and($added[0]->severity)->toBe(Severity::MEDIUM)
        ->and($added[0]->description)->toContain('container resolved');
});

it('does not flag dependencies that already existed', function () {
    $old = '<?php class Foo { public function handle() { return Cache::get("key"); } }';
    $new = '<?php class Foo { public function handle() { return Cache::get("other"); } }';

    $comparison = phpDepComparison($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new NewPhpDependencyRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('does not flag plain use imports', function () {
    $old = '<?php class Foo {}';
    $new = '<?php use App\Services\Mailer; class Foo {}';

    $comparison = phpDepComparison($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new NewPhpDependencyRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('returns empty for non-php files', function () {
    $comparison = phpDepComparison('', '');
    $file = new FileDiff('resources/js/app.js', 'resources/js/app.js', FileStatus::MODIFIED);

    $changes = (new NewPhpDependencyRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('returns empty when old source is missing', function () {
    $comparison = (new AstComparer)->compare(null, '<?php class Foo {}');
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::ADDED);

    $changes = (new NewPhpDependencyRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
