<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\TypeSystemRule;

it('detects return type added', function () {
    $old = '<?php class Foo { public function bar() { return 1; } }';
    $new = '<?php class Foo { public function bar(): int { return 1; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new TypeSystemRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::TYPE_SYSTEM)
        ->and($changes[0]->severity)->toBe(Severity::INFO)
        ->and($changes[0]->description)->toContain('Return type changed')
        ->and($changes[0]->description)->toContain('Foo::bar')
        ->and($changes[0]->description)->toContain('none')
        ->and($changes[0]->description)->toContain('int');
});

it('detects return type removed', function () {
    $old = '<?php class Foo { public function bar(): int { return 1; } }';
    $new = '<?php class Foo { public function bar() { return 1; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new TypeSystemRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::TYPE_SYSTEM)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('Return type changed')
        ->and($changes[0]->description)->toContain('int')
        ->and($changes[0]->description)->toContain('none');
});

it('detects return type changed', function () {
    $old = '<?php class Foo { public function bar(): int { return 1; } }';
    $new = '<?php class Foo { public function bar(): string { return "1"; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new TypeSystemRule)->analyze($file, $comparison);

    $returnChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Return type changed'),
    ));

    expect($returnChanges)->toHaveCount(1)
        ->and($returnChanges[0]->category)->toBe(ChangeCategory::TYPE_SYSTEM)
        ->and($returnChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($returnChanges[0]->description)->toContain('int')
        ->and($returnChanges[0]->description)->toContain('string');
});

it('detects parameter type changed', function () {
    $old = '<?php class Foo { public function bar(int $x) { return $x; } }';
    $new = '<?php class Foo { public function bar(string $x) { return $x; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new TypeSystemRule)->analyze($file, $comparison);

    $paramChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Parameter type changed'),
    ));

    expect($paramChanges)->toHaveCount(1)
        ->and($paramChanges[0]->category)->toBe(ChangeCategory::TYPE_SYSTEM)
        ->and($paramChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($paramChanges[0]->description)->toContain('$x')
        ->and($paramChanges[0]->description)->toContain('int')
        ->and($paramChanges[0]->description)->toContain('string');
});

it('detects property type added', function () {
    $old = '<?php class Foo { public $name = "test"; }';
    $new = '<?php class Foo { public string $name = "test"; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new TypeSystemRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::TYPE_SYSTEM)
        ->and($changes[0]->severity)->toBe(Severity::INFO)
        ->and($changes[0]->description)->toContain('Property type changed')
        ->and($changes[0]->description)->toContain('$name')
        ->and($changes[0]->description)->toContain('none')
        ->and($changes[0]->description)->toContain('string');
});

it('returns no changes for identical types', function () {
    $code = '<?php class Foo { public string $name = "test"; public function bar(int $x): string { return (string) $x; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new TypeSystemRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
