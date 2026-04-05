<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ErrorHandlingRule;

it('detects error suppression added', function () {
    $old = '<?php class Foo { public function bar() { $result = file_get_contents("test"); } }';
    $new = '<?php class Foo { public function bar() { $result = @file_get_contents("test"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ErrorHandlingRule)->analyze($file, $comparison);

    $suppression = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'error suppression')));

    expect($suppression)->toHaveCount(1)
        ->and($suppression[0]->category)->toBe(ChangeCategory::SIDE_EFFECTS)
        ->and($suppression[0]->severity)->toBe(Severity::MEDIUM)
        ->and($suppression[0]->description)->toContain('added')
        ->and($suppression[0]->location)->toBe('Foo::bar');
});

it('detects error suppression removed', function () {
    $old = '<?php class Foo { public function bar() { $result = @file_get_contents("test"); } }';
    $new = '<?php class Foo { public function bar() { $result = file_get_contents("test"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ErrorHandlingRule)->analyze($file, $comparison);

    $suppression = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'error suppression')));

    expect($suppression)->toHaveCount(1)
        ->and($suppression[0]->category)->toBe(ChangeCategory::SIDE_EFFECTS)
        ->and($suppression[0]->severity)->toBe(Severity::INFO)
        ->and($suppression[0]->description)->toContain('removed');
});

it('detects exit call added', function () {
    $old = '<?php class Foo { public function bar() { return 1; } }';
    $new = '<?php class Foo { public function bar() { exit(1); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ErrorHandlingRule)->analyze($file, $comparison);

    $exitChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'exit/die')));

    expect($exitChanges)->toHaveCount(1)
        ->and($exitChanges[0]->category)->toBe(ChangeCategory::SIDE_EFFECTS)
        ->and($exitChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($exitChanges[0]->description)->toContain('added')
        ->and($exitChanges[0]->location)->toBe('Foo::bar');
});

it('detects exit call removed', function () {
    $old = '<?php class Foo { public function bar() { exit(1); } }';
    $new = '<?php class Foo { public function bar() { return 1; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ErrorHandlingRule)->analyze($file, $comparison);

    $exitChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'exit/die')));

    expect($exitChanges)->toHaveCount(1)
        ->and($exitChanges[0]->category)->toBe(ChangeCategory::SIDE_EFFECTS)
        ->and($exitChanges[0]->severity)->toBe(Severity::INFO)
        ->and($exitChanges[0]->description)->toContain('removed');
});

it('returns no changes for identical methods', function () {
    $code = '<?php class Foo { public function bar() { $result = @file_get_contents("test"); exit(1); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ErrorHandlingRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
