<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ClassStructureRule;

it('detects class renamed', function () {
    $old = '<?php class AuthenticateWorkOSController extends Controller {}';
    $new = '<?php class AuthenticateController extends Controller {}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/AuthenticateController.php', 'app/AuthenticateController.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $renamedChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'renamed'),
    ));

    expect($renamedChanges)->toHaveCount(1)
        ->and($renamedChanges[0]->category)->toBe(ChangeCategory::CLASS_STRUCTURE)
        ->and($renamedChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($renamedChanges[0]->description)->toContain('AuthenticateWorkOSController')
        ->and($renamedChanges[0]->description)->toContain('AuthenticateController');
});

it('detects class added', function () {
    $old = '<?php class Foo {}';
    $new = '<?php class Foo {} class Bar {}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $addedChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'added: Bar'),
    ));

    expect($addedChanges)->toHaveCount(1)
        ->and($addedChanges[0]->category)->toBe(ChangeCategory::CLASS_STRUCTURE)
        ->and($addedChanges[0]->severity)->toBe(Severity::INFO)
        ->and($addedChanges[0]->description)->toContain('added: Bar');
});

it('detects class removed', function () {
    $old = '<?php class Foo {} class Bar {}';
    $new = '<?php class Foo {}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $removedChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'removed: Bar'),
    ));

    expect($removedChanges)->toHaveCount(1)
        ->and($removedChanges[0]->category)->toBe(ChangeCategory::CLASS_STRUCTURE)
        ->and($removedChanges[0]->severity)->toBe(Severity::VERY_HIGH);
});

it('detects parent class change', function () {
    $old = '<?php class Foo extends ParentA {}';
    $new = '<?php class Foo extends ParentB {}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $parentChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Parent class changed'),
    ));

    expect($parentChanges)->toHaveCount(1)
        ->and($parentChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($parentChanges[0]->description)->toContain('ParentA')
        ->and($parentChanges[0]->description)->toContain('ParentB');
});

it('detects interface added', function () {
    $old = '<?php class Foo {}';
    $new = '<?php class Foo implements Countable {}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $implChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Interface added'),
    ));

    expect($implChanges)->toHaveCount(1)
        ->and($implChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($implChanges[0]->description)->toContain('Countable');
});

it('detects interface removed', function () {
    $old = '<?php class Foo implements Countable {}';
    $new = '<?php class Foo {}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $implChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Interface removed'),
    ));

    expect($implChanges)->toHaveCount(1)
        ->and($implChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($implChanges[0]->description)->toContain('Countable');
});

it('detects trait added', function () {
    $old = '<?php class Foo {}';
    $new = '<?php class Foo { use SomeTrait; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $traitChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Trait added'),
    ));

    expect($traitChanges)->toHaveCount(1)
        ->and($traitChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($traitChanges[0]->description)->toContain('SomeTrait');
});

it('detects property added', function () {
    $old = '<?php class Foo {}';
    $new = '<?php class Foo { public string $name; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $propChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Property added'),
    ));

    expect($propChanges)->toHaveCount(1)
        ->and($propChanges[0]->severity)->toBe(Severity::INFO)
        ->and($propChanges[0]->description)->toContain('Foo::$name');
});

it('detects property removed', function () {
    $old = '<?php class Foo { public string $name; }';
    $new = '<?php class Foo {}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $propChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Property removed'),
    ));

    expect($propChanges)->toHaveCount(1)
        ->and($propChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($propChanges[0]->description)->toContain('Foo::$name');
});

it('detects class constant added', function () {
    $old = '<?php class Foo {}';
    $new = '<?php class Foo { const MAX = 100; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $constChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Class constant added'),
    ));

    expect($constChanges)->toHaveCount(1)
        ->and($constChanges[0]->severity)->toBe(Severity::INFO)
        ->and($constChanges[0]->description)->toContain('Foo::MAX');
});

it('detects class constant removed', function () {
    $old = '<?php class Foo { const MAX = 100; }';
    $new = '<?php class Foo {}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $constChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Class constant removed'),
    ));

    expect($constChanges)->toHaveCount(1)
        ->and($constChanges[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($constChanges[0]->description)->toContain('Foo::MAX');
});

it('detects class made final', function () {
    $old = '<?php class Foo {}';
    $new = '<?php final class Foo {}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $finalChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'made final'),
    ));

    expect($finalChanges)->toHaveCount(1)
        ->and($finalChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($finalChanges[0]->description)->toContain('Class made final: Foo');
});

it('detects property visibility change', function () {
    $old = '<?php class Foo { public string $name; }';
    $new = '<?php class Foo { protected string $name; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $visChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'visibility changed'),
    ));

    expect($visChanges)->toHaveCount(1)
        ->and($visChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($visChanges[0]->description)->toContain('public')
        ->and($visChanges[0]->description)->toContain('protected');
});

it('detects property made readonly', function () {
    $old = '<?php class Foo { public string $name; }';
    $new = '<?php class Foo { public readonly string $name; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ClassStructureRule)->analyze($file, $comparison);

    $readonlyChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'readonly'),
    ));

    expect($readonlyChanges)->toHaveCount(1)
        ->and($readonlyChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($readonlyChanges[0]->description)->toContain('Property made readonly');
});
