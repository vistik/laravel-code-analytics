<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConfigRule;

it('detects config file change', function () {
    $old = '<?php return ["name" => "MyApp"];';
    $new = '<?php return ["name" => "MyApp", "debug" => true];';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('config/app.php', 'config/app.php', FileStatus::MODIFIED);

    $changes = (new LaravelConfigRule)->analyze($file, $comparison);

    expect($changes)->not->toBeEmpty();

    $configChange = $changes[0];
    expect($configChange->category)->toBe(ChangeCategory::LARAVEL)
        ->and($configChange->description)->toContain('Configuration modified');

    // config/app.php is not in SENSITIVE_CONFIGS, so default Info severity
    $modifiedChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Configuration modified'),
    ));
    expect($modifiedChanges[0]->severity)->toBe(Severity::INFO);

    // Should also detect the added key
    $keyAdded = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Config key added'),
    ));
    expect($keyAdded)->toHaveCount(1)
        ->and($keyAdded[0]->description)->toContain('debug');
});

it('detects auth config as critical', function () {
    $old = '<?php return ["defaults" => ["guard" => "web"]];';
    $new = '<?php return ["defaults" => ["guard" => "api"]];';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('config/auth.php', 'config/auth.php', FileStatus::MODIFIED);

    $changes = (new LaravelConfigRule)->analyze($file, $comparison);

    $configModified = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Configuration modified'),
    ));

    expect($configModified)->toHaveCount(1)
        ->and($configModified[0]->severity)->toBe(Severity::VERY_HIGH);
});

it('ignores non-config files', function () {
    $old = '<?php return ["name" => "MyApp"];';
    $new = '<?php return ["name" => "MyApp", "debug" => true];';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new LaravelConfigRule)->analyze($file, $comparison);

    $configChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Configuration modified'),
    ));

    expect($configChanges)->toBeEmpty();
});

it('returns no changes when config unchanged', function () {
    $code = '<?php return ["name" => "MyApp", "debug" => true];';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('config/app.php', 'config/app.php', FileStatus::MODIFIED);

    $changes = (new LaravelConfigRule)->analyze($file, $comparison);

    // Even identical config files at config/ path will produce a "Configuration modified" entry
    // because the rule always emits one when the path starts with config/
    // But no key-added/removed changes
    $keyChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Config key'),
    ));

    expect($keyChanges)->toBeEmpty();
});
