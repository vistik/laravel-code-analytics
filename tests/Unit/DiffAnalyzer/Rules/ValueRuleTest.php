<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ValueRule;

it('detects class constant value changed', function () {
    $old = '<?php class Foo { const MAX = 10; }';
    $new = '<?php class Foo { const MAX = 20; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ValueRule)->analyze($file, $comparison);

    $constChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Constant value changed'),
    ));

    expect($constChanges)->toHaveCount(1)
        ->and($constChanges[0]->category)->toBe(ChangeCategory::VALUES)
        ->and($constChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($constChanges[0]->description)->toContain('Foo::MAX')
        ->and($constChanges[0]->description)->toContain('10')
        ->and($constChanges[0]->description)->toContain('20');
});

it('detects default parameter value changed', function () {
    $old = '<?php class Foo { public function bar(int $x = 5) { return $x; } }';
    $new = '<?php class Foo { public function bar(int $x = 10) { return $x; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ValueRule)->analyze($file, $comparison);

    $defaultChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Default value changed'),
    ));

    expect($defaultChanges)->toHaveCount(1)
        ->and($defaultChanges[0]->category)->toBe(ChangeCategory::VALUES)
        ->and($defaultChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($defaultChanges[0]->description)->toContain('$x')
        ->and($defaultChanges[0]->description)->toContain('5')
        ->and($defaultChanges[0]->description)->toContain('10');
});

it('detects boolean literal flipped', function () {
    $old = '<?php class Foo { public function bar() { return true; } }';
    $new = '<?php class Foo { public function bar() { return false; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ValueRule)->analyze($file, $comparison);

    $boolChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Boolean literal flipped'),
    ));

    expect($boolChanges)->toHaveCount(1)
        ->and($boolChanges[0]->category)->toBe(ChangeCategory::VALUES)
        ->and($boolChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($boolChanges[0]->description)->toContain('true')
        ->and($boolChanges[0]->description)->toContain('false');
});

it('detects property default changed', function () {
    $old = '<?php class Foo { public string $name = "hello"; }';
    $new = '<?php class Foo { public string $name = "world"; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ValueRule)->analyze($file, $comparison);

    $propChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Property default changed'),
    ));

    expect($propChanges)->toHaveCount(1)
        ->and($propChanges[0]->category)->toBe(ChangeCategory::VALUES)
        ->and($propChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($propChanges[0]->description)->toContain('Foo::$name');
});

it('returns no changes for identical values', function () {
    $code = '<?php class Foo { const MAX = 10; public string $name = "test"; public function bar(int $x = 5) { return true; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ValueRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
