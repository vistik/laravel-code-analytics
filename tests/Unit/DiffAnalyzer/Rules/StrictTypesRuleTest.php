<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\StrictTypesRule;

it('detects strict_types added', function () {
    $old = '<?php class Foo {}';
    $new = '<?php declare(strict_types=1); class Foo {}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new StrictTypesRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::TYPE_SYSTEM)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('added');
});

it('detects strict_types removed', function () {
    $old = '<?php declare(strict_types=1); class Foo {}';
    $new = '<?php class Foo {}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new StrictTypesRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::TYPE_SYSTEM)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('removed');
});

it('returns no changes when strict_types unchanged', function () {
    $code = '<?php declare(strict_types=1); class Foo {}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new StrictTypesRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('returns no changes for non-php files', function () {
    $old = '{"name": "test"}';
    $new = '{"name": "test2"}';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('config.json', 'config.json', FileStatus::MODIFIED);

    $changes = (new StrictTypesRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
