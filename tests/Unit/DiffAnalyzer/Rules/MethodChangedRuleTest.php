<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodChangedRule;

it('detects a method body change in a modified file', function () {
    $old = '<?php class Foo { public function bar() { return 1; } }';
    $new = '<?php class Foo { public function bar() { return 2; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodChangedRule($comparer))->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::METHOD_CHANGED)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('Method changed')
        ->and($changes[0]->description)->toContain('Foo::bar');
});

it('detects a method signature change in a modified file', function () {
    $old = '<?php class Foo { public function bar(int $x) { return $x; } }';
    $new = '<?php class Foo { public function bar(int $x, int $y) { return $x; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodChangedRule($comparer))->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM);
});

it('returns no changes for identical methods', function () {
    $code = '<?php class Foo { public function bar() { return 1; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodChangedRule($comparer))->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('ignores added methods', function () {
    $old = '<?php class Foo { public function bar() {} }';
    $new = '<?php class Foo { public function bar() {} public function baz() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodChangedRule($comparer))->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('ignores removed methods', function () {
    $old = '<?php class Foo { public function bar() {} public function baz() {} }';
    $new = '<?php class Foo { public function bar() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodChangedRule($comparer))->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('does not fire for newly added files', function () {
    $new = '<?php class Foo { public function bar() { return 1; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare(null, $new);
    $file = new FileDiff('/dev/null', 'app/Foo.php', FileStatus::ADDED);

    $changes = (new MethodChangedRule($comparer))->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('fires for renamed files with changed methods', function () {
    $old = '<?php class Foo { public function bar() { return 1; } }';
    $new = '<?php class Foo { public function bar() { return 2; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/OldFoo.php', 'app/Foo.php', FileStatus::RENAMED);

    $changes = (new MethodChangedRule($comparer))->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM);
});
