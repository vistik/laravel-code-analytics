<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\OperatorRule;

it('detects comparison operator changed', function () {
    $old = '<?php class Foo { public function bar($a, $b) { return $a > $b; } }';
    $new = '<?php class Foo { public function bar($a, $b) { return $a < $b; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new OperatorRule)->analyze($file, $comparison);

    $operatorChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Operator changed'),
    ));

    expect($operatorChanges)->toHaveCount(1)
        ->and($operatorChanges[0]->category)->toBe(ChangeCategory::OPERATORS)
        ->and($operatorChanges[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($operatorChanges[0]->description)->toContain('>')
        ->and($operatorChanges[0]->description)->toContain('<');
});

it('detects equality strictness changed', function () {
    $old = '<?php class Foo { public function bar($a, $b) { return $a == $b; } }';
    $new = '<?php class Foo { public function bar($a, $b) { return $a === $b; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new OperatorRule)->analyze($file, $comparison);

    $operatorChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Operator changed'),
    ));

    expect($operatorChanges)->toHaveCount(1)
        ->and($operatorChanges[0]->category)->toBe(ChangeCategory::OPERATORS)
        ->and($operatorChanges[0]->description)->toContain('==')
        ->and($operatorChanges[0]->description)->toContain('===');
});

it('detects logical operator changed', function () {
    $old = '<?php class Foo { public function bar($a, $b) { return $a && $b; } }';
    $new = '<?php class Foo { public function bar($a, $b) { return $a || $b; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new OperatorRule)->analyze($file, $comparison);

    $operatorChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Operator changed'),
    ));

    expect($operatorChanges)->toHaveCount(1)
        ->and($operatorChanges[0]->category)->toBe(ChangeCategory::OPERATORS)
        ->and($operatorChanges[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($operatorChanges[0]->description)->toContain('&&')
        ->and($operatorChanges[0]->description)->toContain('||');
});

it('detects negation added', function () {
    $old = '<?php class Foo { public function bar($a) { if ($a) { return 1; } } }';
    $new = '<?php class Foo { public function bar($a) { if (!$a) { return 1; } } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new OperatorRule)->analyze($file, $comparison);

    $negationChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Negation added'),
    ));

    expect($negationChanges)->toHaveCount(1)
        ->and($negationChanges[0]->category)->toBe(ChangeCategory::OPERATORS)
        ->and($negationChanges[0]->severity)->toBe(Severity::VERY_HIGH);
});

it('returns no changes for identical operators', function () {
    $code = '<?php class Foo { public function bar($a, $b) { return $a > $b && $a !== 0; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new OperatorRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
