<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ControlFlowRule;

it('detects if statement added', function () {
    $old = '<?php class Foo { public function bar() { $x = 1; } }';
    $new = '<?php class Foo { public function bar() { $x = 1; if ($x > 0) { return true; } } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ControlFlowRule($comparer))->analyze($file, $comparison);

    $ifChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'if statement(s) added'),
    ));

    expect($ifChanges)->toHaveCount(1)
        ->and($ifChanges[0]->category)->toBe(ChangeCategory::CONDITIONAL)
        ->and($ifChanges[0]->severity)->toBe(Severity::LOW);
});

it('detects if condition changed', function () {
    $old = '<?php class Foo { public function bar($x) { if ($x > 0) { return true; } } }';
    $new = '<?php class Foo { public function bar($x) { if ($x >= 0) { return true; } } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ControlFlowRule($comparer))->analyze($file, $comparison);

    $condChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'If condition changed'),
    ));

    expect($condChanges)->toHaveCount(1)
        ->and($condChanges[0]->category)->toBe(ChangeCategory::CONDITIONAL)
        ->and($condChanges[0]->severity)->toBe(Severity::MEDIUM);
});

it('detects else branch added', function () {
    $old = '<?php class Foo { public function bar($x) { if ($x > 0) { return true; } } }';
    $new = '<?php class Foo { public function bar($x) { if ($x > 0) { return true; } else { return false; } } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ControlFlowRule($comparer))->analyze($file, $comparison);

    $elseChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Else branch added'),
    ));

    expect($elseChanges)->toHaveCount(1)
        ->and($elseChanges[0]->category)->toBe(ChangeCategory::CONDITIONAL)
        ->and($elseChanges[0]->severity)->toBe(Severity::HIGH);
});

it('detects loop added', function () {
    $old = '<?php class Foo { public function bar() { $items = []; } }';
    $new = '<?php class Foo { public function bar() { $items = []; foreach ($items as $item) { echo $item; } } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ControlFlowRule($comparer))->analyze($file, $comparison);

    $loopChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'loop(s) added'),
    ));

    expect($loopChanges)->toHaveCount(1)
        ->and($loopChanges[0]->category)->toBe(ChangeCategory::LOOP)
        ->and($loopChanges[0]->severity)->toBe(Severity::MEDIUM);
});

it('detects try-catch added', function () {
    $old = '<?php class Foo { public function bar() { doSomething(); } }';
    $new = '<?php class Foo { public function bar() { try { doSomething(); } catch (\Exception $e) { log($e); } } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ControlFlowRule($comparer))->analyze($file, $comparison);

    $tryChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Try-catch block added'),
    ));

    expect($tryChanges)->toHaveCount(1)
        ->and($tryChanges[0]->category)->toBe(ChangeCategory::TRY_CATCH)
        ->and($tryChanges[0]->severity)->toBe(Severity::HIGH);
});

it('detects return statement added', function () {
    $old = '<?php class Foo { public function bar() { $x = 1; } }';
    $new = '<?php class Foo { public function bar() { $x = 1; return $x; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ControlFlowRule($comparer))->analyze($file, $comparison);

    $returnChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'return statement(s) added'),
    ));

    expect($returnChanges)->toHaveCount(1)
        ->and($returnChanges[0]->category)->toBe(ChangeCategory::RETURN)
        ->and($returnChanges[0]->severity)->toBe(Severity::HIGH);
});

it('detects return value changed', function () {
    $old = '<?php class Foo { public function bar() { return 1; } }';
    $new = '<?php class Foo { public function bar() { return 2; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ControlFlowRule($comparer))->analyze($file, $comparison);

    $returnValueChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Return value changed'),
    ));

    expect($returnValueChanges)->toHaveCount(1)
        ->and($returnValueChanges[0]->category)->toBe(ChangeCategory::RETURN)
        ->and($returnValueChanges[0]->severity)->toBe(Severity::MEDIUM);
});

it('summarizes long return value expressions in description', function () {
    $old = '<?php class Foo { public function bar() { return DB::connection("main")->table("users")->select(["id", "name", "email", "phone", "address", "city", "state"])->where("active", true)->orderBy("name")->get(); } }';
    $new = '<?php class Foo { public function bar() { return $query->get(); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ControlFlowRule($comparer))->analyze($file, $comparison);

    $returnValueChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Return value changed'),
    ));

    expect($returnValueChanges)->toHaveCount(1)
        ->and($returnValueChanges[0]->description)->toContain('->…->get()')
        ->and($returnValueChanges[0]->description)->not->toContain('select');
});

it('detects switch case count changed', function () {
    $old = '<?php class Foo { public function bar($x) { switch ($x) { case 1: return "a"; case 2: return "b"; } } }';
    $new = '<?php class Foo { public function bar($x) { switch ($x) { case 1: return "a"; case 2: return "b"; case 3: return "c"; } } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ControlFlowRule($comparer))->analyze($file, $comparison);

    $switchChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Switch cases changed'),
    ));

    expect($switchChanges)->toHaveCount(1)
        ->and($switchChanges[0]->category)->toBe(ChangeCategory::SWITCH_MATCH)
        ->and($switchChanges[0]->severity)->toBe(Severity::HIGH);
});

it('detects match arm count changed', function () {
    $old = '<?php class Foo { public function bar($x) { return match($x) { 1 => "a", 2 => "b" }; } }';
    $new = '<?php class Foo { public function bar($x) { return match($x) { 1 => "a", 2 => "b", 3 => "c" }; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ControlFlowRule($comparer))->analyze($file, $comparison);

    $matchChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Match arms changed'),
    ));

    expect($matchChanges)->toHaveCount(1)
        ->and($matchChanges[0]->category)->toBe(ChangeCategory::SWITCH_MATCH)
        ->and($matchChanges[0]->severity)->toBe(Severity::MEDIUM);
});
