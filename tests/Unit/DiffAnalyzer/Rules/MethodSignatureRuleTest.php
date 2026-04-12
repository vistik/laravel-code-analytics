<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodSignatureRule;

it('detects visibility change', function () {
    $old = '<?php class Foo { public function bar() {} }';
    $new = '<?php class Foo { protected function bar() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodSignatureRule)->analyze($file, $comparison);

    $visibilityChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Visibility changed'),
    ));

    expect($visibilityChanges)->toHaveCount(1)
        ->and($visibilityChanges[0]->category)->toBe(ChangeCategory::METHOD_SIGNATURE)
        ->and($visibilityChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($visibilityChanges[0]->description)->toContain('public -> protected');
});

it('detects method made static', function () {
    $old = '<?php class Foo { public function bar() {} }';
    $new = '<?php class Foo { public static function bar() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodSignatureRule)->analyze($file, $comparison);

    $staticChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'made static'),
    ));

    expect($staticChanges)->toHaveCount(1)
        ->and($staticChanges[0]->category)->toBe(ChangeCategory::METHOD_SIGNATURE)
        ->and($staticChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($staticChanges[0]->description)->toContain('Foo::bar');
});

it('detects method made final', function () {
    $old = '<?php class Foo { public function bar() {} }';
    $new = '<?php class Foo { public final function bar() {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodSignatureRule)->analyze($file, $comparison);

    $finalChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'made final'),
    ));

    expect($finalChanges)->toHaveCount(1)
        ->and($finalChanges[0]->category)->toBe(ChangeCategory::METHOD_SIGNATURE)
        ->and($finalChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($finalChanges[0]->description)->toContain('Foo::bar');
});

it('detects parameter added', function () {
    $old = '<?php class Foo { public function bar(string $a) {} }';
    $new = '<?php class Foo { public function bar(string $a, int $b) {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodSignatureRule)->analyze($file, $comparison);

    $paramChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Parameter added'),
    ));

    expect($paramChanges)->toHaveCount(1)
        ->and($paramChanges[0]->category)->toBe(ChangeCategory::METHOD_SIGNATURE)
        ->and($paramChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($paramChanges[0]->description)->toContain('$b');
});

it('detects parameter removed', function () {
    $old = '<?php class Foo { public function bar(string $a, int $b) {} }';
    $new = '<?php class Foo { public function bar(string $a) {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodSignatureRule)->analyze($file, $comparison);

    $paramChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Parameter removed'),
    ));

    expect($paramChanges)->toHaveCount(1)
        ->and($paramChanges[0]->category)->toBe(ChangeCategory::METHOD_SIGNATURE)
        ->and($paramChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($paramChanges[0]->description)->toContain('$b');
});

it('detects return type change', function () {
    $old = '<?php class Foo { public function bar(): int {} }';
    $new = '<?php class Foo { public function bar(): string {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodSignatureRule)->analyze($file, $comparison);

    $returnChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Return type changed'),
    ));

    expect($returnChanges)->toHaveCount(1)
        ->and($returnChanges[0]->category)->toBe(ChangeCategory::METHOD_SIGNATURE)
        ->and($returnChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($returnChanges[0]->description)->toContain('int -> string');
});

it('detects return type added', function () {
    $old = '<?php class Foo { public function bar() {} }';
    $new = '<?php class Foo { public function bar(): void {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodSignatureRule)->analyze($file, $comparison);

    $returnChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Return type changed'),
    ));

    expect($returnChanges)->toHaveCount(1)
        ->and($returnChanges[0]->description)->toContain('none -> void');
});

it('does not report return type change for identical return types', function () {
    $old = '<?php class Foo { public function bar(): int { return 1; } }';
    $new = '<?php class Foo { public function bar(): int { return 2; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodSignatureRule)->analyze($file, $comparison);

    $returnChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Return type changed'),
    ));

    expect($returnChanges)->toBeEmpty();
});

it('returns no changes for identical methods', function () {
    $code = '<?php class Foo { public function bar(string $a, int $b) { return $a; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodSignatureRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
