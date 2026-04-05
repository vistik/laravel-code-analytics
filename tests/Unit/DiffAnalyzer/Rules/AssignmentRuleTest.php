<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\AssignmentRule;

it('detects new assignment targets', function () {
    $old = '<?php class Foo { public function bar() { $x = 1; } }';
    $new = '<?php class Foo { public function bar() { $x = 1; $y = 2; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new AssignmentRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::ASSIGNMENT)
        ->and($changes[0]->severity)->toBe(Severity::INFO)
        ->and($changes[0]->description)->toContain('New assignment target')
        ->and($changes[0]->description)->toContain('$y')
        ->and($changes[0]->location)->toBe('Foo::bar');
});

it('detects removed assignment targets', function () {
    $old = '<?php class Foo { public function bar() { $x = 1; $y = 2; } }';
    $new = '<?php class Foo { public function bar() { $x = 1; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new AssignmentRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::ASSIGNMENT)
        ->and($changes[0]->severity)->toBe(Severity::INFO)
        ->and($changes[0]->description)->toContain('Assignment target removed')
        ->and($changes[0]->description)->toContain('$y');
});

it('detects compound assignment operator changes', function () {
    $old = '<?php class Foo { public function bar() { $x = 0; $x += 1; } }';
    $new = '<?php class Foo { public function bar() { $x = 0; $x -= 1; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new AssignmentRule)->analyze($file, $comparison);

    $compoundChanges = array_values(array_filter(
        $changes,
        fn ($c) => $c->severity === Severity::VERY_HIGH,
    ));

    expect($compoundChanges)->toHaveCount(1)
        ->and($compoundChanges[0]->category)->toBe(ChangeCategory::ASSIGNMENT)
        ->and($compoundChanges[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($compoundChanges[0]->description)->toContain('Compound assignment operator changed');
});

it('returns no changes for identical methods', function () {
    $code = '<?php class Foo { public function bar() { $x = 1; $y = 2; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new AssignmentRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('skips methods that are only in old or new', function () {
    $old = '<?php class Foo { public function bar() { $x = 1; } }';
    $new = '<?php class Foo { public function baz() { $y = 2; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new AssignmentRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
