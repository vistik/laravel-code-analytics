<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MagicMethodRule;

it('detects magic method added', function () {
    $old = '<?php class Foo { public function bar() {} }';
    $new = '<?php class Foo { public function bar() {} public function __toString(): string { return "foo"; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MagicMethodRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::METHOD_SIGNATURE)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('Magic method added')
        ->and($changes[0]->description)->toContain('__toString')
        ->and($changes[0]->location)->toBe('Foo::__toString');
});

it('detects magic method removed', function () {
    $old = '<?php class Foo { public function bar() {} public function __toString(): string { return "foo"; } }';
    $new = '<?php class Foo { public function bar() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MagicMethodRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::METHOD_SIGNATURE)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('Magic method removed')
        ->and($changes[0]->description)->toContain('__toString');
});

it('detects magic method modified', function () {
    $old = '<?php class Foo { public function __get(string $name) { return $this->data[$name]; } }';
    $new = '<?php class Foo { public function __get(string $name) { return $this->data[$name] ?? null; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MagicMethodRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::METHOD_SIGNATURE)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('Foo::__get()')
        ->and($changes[0]->description)->toContain('Magic getter changed')
        ->and($changes[0]->location)->toBe('Foo::__get');
});

it('ignores non-magic methods', function () {
    $old = '<?php class Foo { public function bar() { return 1; } }';
    $new = '<?php class Foo { public function bar() { return 2; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MagicMethodRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('returns no changes for identical code', function () {
    $code = '<?php class Foo { public function __toString(): string { return "foo"; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MagicMethodRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
