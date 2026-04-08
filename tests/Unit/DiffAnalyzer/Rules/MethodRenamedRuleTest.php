<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodAddedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodRemovedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodRenamedRule;

it('detects a method rename with same parameters and body', function () {
    $old = '<?php class Foo { public function webWorkersCount(int $n): int { return $n * 2; } }';
    $new = '<?php class Foo { public function environmentUsage(int $n): int { return $n * 2; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodRenamedRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::METHOD_RENAMED)
        ->and($changes[0]->severity)->toBe(Severity::HIGH)
        ->and($changes[0]->description)->toContain('webWorkersCount')
        ->and($changes[0]->description)->toContain('environmentUsage');
});

it('detects a method rename with same parameters but different return type', function () {
    $old = '<?php class Foo { public function webWorkersCount(\MetricPeriod $period, \Environment $env): \MultiLineTimeSeries { return new \MultiLineTimeSeries; } }';
    $new = '<?php class Foo { public function environmentUsage(\MetricPeriod $period, \Environment $env): \EnvironmentUsage { return new \EnvironmentUsage; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodRenamedRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::METHOD_RENAMED)
        ->and($changes[0]->description)->toContain('webWorkersCount')
        ->and($changes[0]->description)->toContain('environmentUsage');
});

it('does not report a rename as method removed or added', function () {
    $old = '<?php class Foo { public function webWorkersCount(int $n): int { return $n; } }';
    $new = '<?php class Foo { public function environmentUsage(int $n): int { return $n; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    expect((new MethodRemovedRule)->analyze($file, $comparison))->toBeEmpty();
    expect((new MethodAddedRule)->analyze($file, $comparison))->toBeEmpty();
});

it('does not detect rename when parameter lists differ', function () {
    $old = '<?php class Foo { public function foo(int $a): void {} }';
    $new = '<?php class Foo { public function bar(string $a): void {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodRenamedRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('does not detect rename for zero-param methods with different bodies', function () {
    $old = '<?php class Foo { public function foo(): void { doA(); } }';
    $new = '<?php class Foo { public function bar(): void { doB(); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodRenamedRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('detects rename for zero-param methods with identical bodies', function () {
    $old = '<?php class Foo { public function foo(): void { doSomething(); } }';
    $new = '<?php class Foo { public function bar(): void { doSomething(); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodRenamedRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->description)->toContain('foo')
        ->and($changes[0]->description)->toContain('bar');
});
