<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\AttributeRule;

it('detects attribute added on class', function () {
    $old = '<?php class Foo { public function bar() {} }';
    $new = '<?php #[Deprecated] class Foo { public function bar() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new AttributeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::CLASS_STRUCTURE)
        ->and($changes[0]->description)->toContain('Attribute added on Foo')
        ->and($changes[0]->description)->toContain('#[Deprecated]');
});

it('detects attribute removed from method', function () {
    $old = '<?php class Foo { #[Override] public function bar() {} }';
    $new = '<?php class Foo { public function bar() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new AttributeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::CLASS_STRUCTURE)
        ->and($changes[0]->description)->toContain('Attribute removed from Foo::bar')
        ->and($changes[0]->description)->toContain('#[Override]');
});

it('assigns warning severity to Deprecated attribute', function () {
    $old = '<?php class Foo { public function bar() {} }';
    $new = '<?php #[Deprecated] class Foo { public function bar() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new AttributeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM);
});

it('assigns info severity to custom attributes', function () {
    $old = '<?php class Foo { public function bar() {} }';
    $new = '<?php class Foo { #[MyCustomAttribute] public function bar() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new AttributeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::INFO);
});

it('returns no changes when attributes unchanged', function () {
    $code = '<?php #[Deprecated] class Foo { #[Override] public function bar() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new AttributeRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
