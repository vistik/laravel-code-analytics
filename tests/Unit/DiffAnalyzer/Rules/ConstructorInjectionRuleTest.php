<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ConstructorInjectionRule;

it('detects new dependency injected', function () {
    $old = '<?php class Foo { public function __construct(private Logger $logger) {} }';
    $new = '<?php class Foo { public function __construct(private Logger $logger, private Mailer $mailer) {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ConstructorInjectionRule)->analyze($file, $comparison);

    $addedDeps = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'New dependency injected'),
    ));

    expect($addedDeps)->toHaveCount(1)
        ->and($addedDeps[0]->category)->toBe(ChangeCategory::METHOD_SIGNATURE)
        ->and($addedDeps[0]->severity)->toBe(Severity::MEDIUM)
        ->and($addedDeps[0]->description)->toContain('Mailer')
        ->and($addedDeps[0]->description)->toContain('$mailer');
});

it('detects dependency removed', function () {
    $old = '<?php class Foo { public function __construct(private Logger $logger, private Mailer $mailer) {} }';
    $new = '<?php class Foo { public function __construct(private Logger $logger) {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ConstructorInjectionRule)->analyze($file, $comparison);

    $removedDeps = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Dependency removed'),
    ));

    expect($removedDeps)->toHaveCount(1)
        ->and($removedDeps[0]->category)->toBe(ChangeCategory::METHOD_SIGNATURE)
        ->and($removedDeps[0]->severity)->toBe(Severity::MEDIUM)
        ->and($removedDeps[0]->description)->toContain('Mailer')
        ->and($removedDeps[0]->description)->toContain('$mailer');
});

it('detects dependency type changed', function () {
    $old = '<?php class Foo { public function __construct(private Logger $logger) {} }';
    $new = '<?php class Foo { public function __construct(private FileLogger $logger) {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ConstructorInjectionRule)->analyze($file, $comparison);

    $typeChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Dependency type changed'),
    ));

    expect($typeChanges)->toHaveCount(1)
        ->and($typeChanges[0]->category)->toBe(ChangeCategory::METHOD_SIGNATURE)
        ->and($typeChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($typeChanges[0]->description)->toContain('Logger')
        ->and($typeChanges[0]->description)->toContain('FileLogger');
});

it('ignores untyped parameters', function () {
    $old = '<?php class Foo { public function __construct($name) {} }';
    $new = '<?php class Foo { public function __construct($name, $age) {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ConstructorInjectionRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('ignores non-constructor methods', function () {
    $old = '<?php class Foo { public function __construct() {} public function handle(Logger $logger) {} }';
    $new = '<?php class Foo { public function __construct() {} public function handle(Logger $logger, Mailer $mailer) {} }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new ConstructorInjectionRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
