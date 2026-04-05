<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\SideEffectRule;

it('detects dispatch call added', function () {
    $old = '<?php class Foo { public function bar() { $x = 1; } }';
    $new = '<?php class Foo { public function bar() { $x = 1; dispatch(new SomeJob()); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new SideEffectRule)->analyze($file, $comparison);

    $sideEffectChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'dispatch()'),
    ));

    expect($sideEffectChanges)->toHaveCount(1)
        ->and($sideEffectChanges[0]->category)->toBe(ChangeCategory::SIDE_EFFECTS)
        ->and($sideEffectChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($sideEffectChanges[0]->description)->toContain('Side-effect function call added');
});

it('detects Log facade call added', function () {
    $old = '<?php class Foo { public function bar() { $x = 1; } }';
    $new = '<?php class Foo { public function bar() { $x = 1; \Log::info("test"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new SideEffectRule)->analyze($file, $comparison);

    $staticChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Log::'),
    ));

    expect($staticChanges)->toHaveCount(1)
        ->and($staticChanges[0]->category)->toBe(ChangeCategory::SIDE_EFFECTS)
        ->and($staticChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($staticChanges[0]->description)->toContain('Side-effect static call added');
});

it('detects Cache facade call added', function () {
    $old = '<?php class Foo { public function bar() { return 1; } }';
    $new = '<?php class Foo { public function bar() { \Cache::put("key", "value"); return 1; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new SideEffectRule)->analyze($file, $comparison);

    $cacheChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Cache::'),
    ));

    expect($cacheChanges)->toHaveCount(1)
        ->and($cacheChanges[0]->category)->toBe(ChangeCategory::SIDE_EFFECTS)
        ->and($cacheChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($cacheChanges[0]->description)->toContain('Side-effect static call added');
});

it('detects throw added', function () {
    $old = '<?php class Foo { public function bar($x) { return $x; } }';
    $new = '<?php class Foo { public function bar($x) { if (!$x) { throw new \RuntimeException("fail"); } return $x; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new SideEffectRule)->analyze($file, $comparison);

    $throwChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Exception throw added'),
    ));

    expect($throwChanges)->toHaveCount(1)
        ->and($throwChanges[0]->category)->toBe(ChangeCategory::SIDE_EFFECTS)
        ->and($throwChanges[0]->severity)->toBe(Severity::MEDIUM);
});

it('detects side effect removed', function () {
    $old = '<?php class Foo { public function bar() { dispatch(new SomeJob()); return 1; } }';
    $new = '<?php class Foo { public function bar() { return 1; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new SideEffectRule)->analyze($file, $comparison);

    $removedChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'dispatch()') && str_contains($c->description, 'removed'),
    ));

    expect($removedChanges)->toHaveCount(1)
        ->and($removedChanges[0]->category)->toBe(ChangeCategory::SIDE_EFFECTS)
        ->and($removedChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($removedChanges[0]->description)->toContain('Side-effect function call removed');
});

it('returns no changes for identical methods', function () {
    $code = '<?php class Foo { public function bar() { dispatch(new SomeJob()); \Log::info("test"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new SideEffectRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
