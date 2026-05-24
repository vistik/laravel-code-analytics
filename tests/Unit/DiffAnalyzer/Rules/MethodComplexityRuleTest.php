<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodComplexityRule;

// CC = 4 (good: below warn threshold of 5)
$goodCc = '<?php class Foo { public function handle($a, $b, $c): mixed { if ($a) { return 1; } if ($b) { return 2; } if ($c) { return 3; } return 0; } }';

// CC = 6 (warn: between 5 and 10)
$warnCc = '<?php class Foo { public function handle($a, $b, $c, $d, $e): mixed { if ($a) { return 1; } if ($b) { return 2; } if ($c) { return 3; } if ($d) { return 4; } if ($e) { return 5; } return 0; } }';

// CC = 11 (bad: above 10)
$badCc = '<?php class Foo { public function handle($a, $b, $c, $d, $e, $f, $g, $h, $i, $j): mixed { if ($a) { return 1; } if ($b) { return 2; } if ($c) { return 3; } if ($d) { return 4; } if ($e) { return 5; } if ($f) { return 6; } if ($g) { return 7; } if ($h) { return 8; } if ($i) { return 9; } if ($j) { return 10; } return 0; } }';

it('flags cc good -> warn transition as LOW', function () use ($goodCc, $warnCc) {
    $comparison = (new AstComparer)->compare($goodCc, $warnCc);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toHaveCount(1)
        ->and($ccChanges[0]->category)->toBe(ChangeCategory::COMPLEXITY)
        ->and($ccChanges[0]->severity)->toBe(Severity::LOW)
        ->and($ccChanges[0]->description)->toContain('good')
        ->and($ccChanges[0]->description)->toContain('warn');
});

it('flags cc good -> bad transition as HIGH', function () use ($goodCc, $badCc) {
    $comparison = (new AstComparer)->compare($goodCc, $badCc);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toHaveCount(1)
        ->and($ccChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($ccChanges[0]->description)->toContain('good')
        ->and($ccChanges[0]->description)->toContain('bad');
});

it('flags cc warn -> bad transition as MEDIUM', function () use ($warnCc, $badCc) {
    $comparison = (new AstComparer)->compare($warnCc, $badCc);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toHaveCount(1)
        ->and($ccChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($ccChanges[0]->description)->toContain('warn')
        ->and($ccChanges[0]->description)->toContain('bad');
});

it('flags cc warn -> good transition as INFO', function () use ($warnCc, $goodCc) {
    $comparison = (new AstComparer)->compare($warnCc, $goodCc);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toHaveCount(1)
        ->and($ccChanges[0]->severity)->toBe(Severity::INFO)
        ->and($ccChanges[0]->description)->toContain('warn')
        ->and($ccChanges[0]->description)->toContain('good');
});

it('flags cc bad -> warn transition as INFO', function () use ($badCc, $warnCc) {
    $comparison = (new AstComparer)->compare($badCc, $warnCc);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toHaveCount(1)
        ->and($ccChanges[0]->severity)->toBe(Severity::INFO)
        ->and($ccChanges[0]->description)->toContain('bad')
        ->and($ccChanges[0]->description)->toContain('warn');
});

it('flags cc bad -> good transition as LOW', function () use ($badCc, $goodCc) {
    $comparison = (new AstComparer)->compare($badCc, $goodCc);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toHaveCount(1)
        ->and($ccChanges[0]->severity)->toBe(Severity::LOW)
        ->and($ccChanges[0]->description)->toContain('bad')
        ->and($ccChanges[0]->description)->toContain('good');
});

it('produces no finding when cc stays in the same band', function () use ($goodCc) {
    $sameGoodCc = '<?php class Foo { public function handle($a): mixed { if ($a) { return 1; } return 0; } }';

    $comparison = (new AstComparer)->compare($goodCc, $sameGoodCc);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toBeEmpty();
});

it('ignores newly added methods with no baseline', function () use ($goodCc) {
    $withNew = '<?php class Foo { public function handle($a, $b, $c): mixed { if ($a) { return 1; } if ($b) { return 2; } if ($c) { return 3; } return 0; } public function newMethod($a, $b, $c, $d, $e): mixed { if ($a) { return 1; } if ($b) { return 2; } if ($c) { return 3; } if ($d) { return 4; } if ($e) { return 5; } return 0; } }';

    $comparison = (new AstComparer)->compare($goodCc, $withNew);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('does not fire for added files', function () use ($warnCc) {
    $comparison = (new AstComparer)->compare(null, $warnCc);
    $file = new FileDiff('/dev/null', 'app/Foo.php', FileStatus::ADDED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('does not fire for deleted files', function () use ($warnCc) {
    $comparison = (new AstComparer)->compare($warnCc, null);
    $file = new FileDiff('app/Foo.php', '/dev/null', FileStatus::DELETED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('fires for renamed files with complexity change', function () use ($goodCc, $warnCc) {
    $comparison = (new AstComparer)->compare($goodCc, $warnCc);
    $file = new FileDiff('app/OldFoo.php', 'app/Foo.php', FileStatus::RENAMED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toHaveCount(1)
        ->and($ccChanges[0]->severity)->toBe(Severity::LOW);
});

// ── Flog transitions ──────────────────────────────────────────────────────
// Flog uses the ABC formula: sqrt(A² + B² + C²)
// B counts calls and operators; thresholds are warn=10, bad=20.
// good  = flog < 10  (e.g. 1 call → B=1, flog=1.0)
// warn  = flog 10–20 (e.g. 10 calls → B=10, flog=10.0)
// bad   = flog >= 20 (e.g. 20 calls → B=20, flog=20.0)

it('flags flog good -> warn transition as LOW', function () {
    // good: 1 call → B=1, flog=1.0
    $goodFlog = '<?php class Foo { public function handle(): void { $this->run(); } }';

    // warn: 10 calls → B=10, flog=10.0
    $calls = implode(' ', array_map(fn ($i) => "\$this->m{$i}();", range(1, 10)));
    $warnFlog = "<?php class Foo { public function handle(): void { {$calls} } }";

    $comparison = (new AstComparer)->compare($goodFlog, $warnFlog);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    $flogChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'flog')));

    expect($flogChanges)->toHaveCount(1)
        ->and($flogChanges[0]->category)->toBe(ChangeCategory::COMPLEXITY)
        ->and($flogChanges[0]->severity)->toBe(Severity::LOW)
        ->and($flogChanges[0]->description)->toContain('good')
        ->and($flogChanges[0]->description)->toContain('warn');
});

it('flags flog warn -> good transition as INFO', function () {
    // warn: 10 calls → B=10, flog=10.0
    $calls = implode(' ', array_map(fn ($i) => "\$this->m{$i}();", range(1, 10)));
    $warnFlog = "<?php class Foo { public function handle(): void { {$calls} } }";

    // good: 1 call → B=1, flog=1.0
    $goodFlog = '<?php class Foo { public function handle(): void { $this->run(); } }';

    $comparison = (new AstComparer)->compare($warnFlog, $goodFlog);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    $flogChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'flog')));

    expect($flogChanges)->toHaveCount(1)
        ->and($flogChanges[0]->severity)->toBe(Severity::INFO)
        ->and($flogChanges[0]->description)->toContain('warn')
        ->and($flogChanges[0]->description)->toContain('good');
});

it('flags flog good -> bad transition as HIGH', function () {
    // good: 1 call → B=1, flog=1.0
    $goodFlog = '<?php class Foo { public function handle(): void { $this->run(); } }';

    // bad: 20 calls → B=20, flog=20.0
    $calls = implode(' ', array_map(fn ($i) => "\$this->m{$i}();", range(1, 20)));
    $badFlog = "<?php class Foo { public function handle(): void { {$calls} } }";

    $comparison = (new AstComparer)->compare($goodFlog, $badFlog);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new MethodComplexityRule)->analyze($file, $comparison);

    $flogChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'flog')));

    expect($flogChanges)->toHaveCount(1)
        ->and($flogChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($flogChanges[0]->description)->toContain('good')
        ->and($flogChanges[0]->description)->toContain('bad');
});
