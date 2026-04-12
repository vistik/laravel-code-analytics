<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\EnumRule;

it('detects enum case added', function () {
    $old = '<?php enum Status: string { case Active = "active"; case Inactive = "inactive"; }';
    $new = '<?php enum Status: string { case Active = "active"; case Inactive = "inactive"; case Pending = "pending"; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Enums/Status.php', 'app/Enums/Status.php', FileStatus::MODIFIED);

    $changes = (new EnumRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'case added')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->category)->toBe(ChangeCategory::CLASS_STRUCTURE)
        ->and($added[0]->severity)->toBe(Severity::MEDIUM)
        ->and($added[0]->description)->toContain('Pending')
        ->and($added[0]->location)->toBe('Status');
});

it('detects enum case removed', function () {
    $old = '<?php enum Status: string { case Active = "active"; case Inactive = "inactive"; case Pending = "pending"; }';
    $new = '<?php enum Status: string { case Active = "active"; case Inactive = "inactive"; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Enums/Status.php', 'app/Enums/Status.php', FileStatus::MODIFIED);

    $changes = (new EnumRule)->analyze($file, $comparison);

    $removed = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'case removed')));

    expect($removed)->toHaveCount(1)
        ->and($removed[0]->category)->toBe(ChangeCategory::CLASS_STRUCTURE)
        ->and($removed[0]->severity)->toBe(Severity::HIGH)
        ->and($removed[0]->description)->toContain('Pending')
        ->and($removed[0]->location)->toBe('Status');
});

it('detects enum case value changed', function () {
    $old = '<?php enum Status: string { case Active = "active"; case Inactive = "inactive"; }';
    $new = '<?php enum Status: string { case Active = "enabled"; case Inactive = "inactive"; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Enums/Status.php', 'app/Enums/Status.php', FileStatus::MODIFIED);

    $changes = (new EnumRule)->analyze($file, $comparison);

    $valueChanged = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'case value changed')));

    expect($valueChanged)->toHaveCount(1)
        ->and($valueChanged[0]->category)->toBe(ChangeCategory::VALUES)
        ->and($valueChanged[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($valueChanged[0]->description)->toContain('Active')
        ->and($valueChanged[0]->location)->toBe('Status');
});

it('detects backed type changed', function () {
    $old = '<?php enum Status: string { case Active = "active"; }';
    $new = '<?php enum Status: int { case Active = 1; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Enums/Status.php', 'app/Enums/Status.php', FileStatus::MODIFIED);

    $changes = (new EnumRule)->analyze($file, $comparison);

    $typeChanged = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'backed type changed')));

    expect($typeChanged)->toHaveCount(1)
        ->and($typeChanged[0]->category)->toBe(ChangeCategory::CLASS_STRUCTURE)
        ->and($typeChanged[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($typeChanged[0]->description)->toContain('string')
        ->and($typeChanged[0]->description)->toContain('int')
        ->and($typeChanged[0]->location)->toBe('Status');
});

it('returns no changes for unchanged enum', function () {
    $code = '<?php enum Status: string { case Active = "active"; case Inactive = "inactive"; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Enums/Status.php', 'app/Enums/Status.php', FileStatus::MODIFIED);

    $changes = (new EnumRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
