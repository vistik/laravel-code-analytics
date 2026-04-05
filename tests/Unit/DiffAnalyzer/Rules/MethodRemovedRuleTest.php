<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodRemovedRule;

it('detects method removed', function () {
    $old = '<?php class Foo { public function bar() {} public function baz() {} }';
    $new = '<?php class Foo { public function bar() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodRemovedRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::METHOD_REMOVED)
        ->and($changes[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($changes[0]->description)->toContain('Method removed')
        ->and($changes[0]->description)->toContain('Foo::baz');
});

it('does not flag added methods', function () {
    $old = '<?php class Foo { public function bar() {} }';
    $new = '<?php class Foo { public function bar() {} public function baz() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodRemovedRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('returns no changes for identical methods', function () {
    $code = '<?php class Foo { public function bar() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodRemovedRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
