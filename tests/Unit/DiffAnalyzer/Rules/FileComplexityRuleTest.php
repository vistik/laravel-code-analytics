<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\FileComplexityRule;

// Thresholds: cc warn=25, bad=50 | flog warn=30, bad=60
//
// CC helpers (each method contributes CC = 1 + number of ifs)
// - goodFile:  2 methods × CC=5  = 10 total  (good < 25)
// - warnFile:  5 methods × CC=6  = 30 total  (warn 25–50)
// - badFile:  10 methods × CC=6  = 60 total  (bad  ≥ 50)

function makeFile(int $methodCount, int $ifCount): string
{
    $methods = '';
    for ($i = 1; $i <= $methodCount; $i++) {
        $ifs = implode(' ', array_map(fn ($j) => "if (\$p{$j}) { return {$j}; }", range(1, $ifCount)));
        $params = implode(', ', array_map(fn ($j) => "\$p{$j}", range(1, $ifCount)));
        $methods .= "public function m{$i}({$params}): mixed { {$ifs} return 0; } ";
    }

    return "<?php class Foo { {$methods} }";
}

// good CC total = 2 methods × (1 base + 4 ifs) = 10
$goodCcFile = makeFile(methodCount: 2, ifCount: 4);

// warn CC total = 5 methods × (1 base + 5 ifs) = 30
$warnCcFile = makeFile(methodCount: 5, ifCount: 5);

// bad CC total = 10 methods × (1 base + 5 ifs) = 60
$badCcFile = makeFile(methodCount: 10, ifCount: 5);

it('flags total cc good -> warn as LOW', function () use ($goodCcFile, $warnCcFile) {
    $comparison = (new AstComparer)->compare($goodCcFile, $warnCcFile);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new FileComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toHaveCount(1)
        ->and($ccChanges[0]->category)->toBe(ChangeCategory::COMPLEXITY)
        ->and($ccChanges[0]->severity)->toBe(Severity::LOW)
        ->and($ccChanges[0]->description)->toContain('good')
        ->and($ccChanges[0]->description)->toContain('warn');
});

it('flags total cc warn -> bad as MEDIUM', function () use ($warnCcFile, $badCcFile) {
    $comparison = (new AstComparer)->compare($warnCcFile, $badCcFile);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new FileComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toHaveCount(1)
        ->and($ccChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($ccChanges[0]->description)->toContain('warn')
        ->and($ccChanges[0]->description)->toContain('bad');
});

it('flags total cc good -> bad as HIGH', function () use ($goodCcFile, $badCcFile) {
    $comparison = (new AstComparer)->compare($goodCcFile, $badCcFile);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new FileComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toHaveCount(1)
        ->and($ccChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($ccChanges[0]->description)->toContain('good')
        ->and($ccChanges[0]->description)->toContain('bad');
});

it('flags total cc warn -> good as INFO', function () use ($warnCcFile, $goodCcFile) {
    $comparison = (new AstComparer)->compare($warnCcFile, $goodCcFile);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new FileComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toHaveCount(1)
        ->and($ccChanges[0]->severity)->toBe(Severity::INFO)
        ->and($ccChanges[0]->description)->toContain('warn')
        ->and($ccChanges[0]->description)->toContain('good');
});

it('produces no finding when total cc stays in the same band', function () use ($goodCcFile) {
    // Slightly different but still good
    $alsoGood = makeFile(methodCount: 2, ifCount: 3);

    $comparison = (new AstComparer)->compare($goodCcFile, $alsoGood);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new FileComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toBeEmpty();
});

it('flags total flog good -> warn as LOW', function () {
    // Flog thresholds: warn=30, bad=60
    // Each method call counts as B=1 toward Flog (ABC score).
    // good: 2 methods × flog≈5 = 10 total flog (good < 30)
    // warn: 5 methods × flog≈8 = 40 total flog (warn 30–60)

    // good flog file: 2 simple methods, 1 call each → each flog ≈ 1.0, total ≈ 2.0
    $goodFlogFile = '<?php class Foo {
        public function a(): void { $this->x(); }
        public function b(): void { $this->y(); }
    }';

    // warn flog file: each method has ~6 calls → each B=6, flog=6.0, 6 methods → total ≈ 36
    $calls = implode(' ', array_map(fn ($i) => "\$this->m{$i}();", range(1, 6)));
    $warnFlogFile = '<?php class Foo {'.implode('', array_map(
        fn ($i) => " public function m{$i}(): void { {$calls} }",
        range(1, 6)
    )).' }';

    $comparison = (new AstComparer)->compare($goodFlogFile, $warnFlogFile);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new FileComplexityRule)->analyze($file, $comparison);

    $flogChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'flog')));

    expect($flogChanges)->toHaveCount(1)
        ->and($flogChanges[0]->category)->toBe(ChangeCategory::COMPLEXITY)
        ->and($flogChanges[0]->severity)->toBe(Severity::LOW)
        ->and($flogChanges[0]->description)->toContain('good')
        ->and($flogChanges[0]->description)->toContain('warn');
});

it('does not fire for added files', function () use ($warnCcFile) {
    $comparison = (new AstComparer)->compare(null, $warnCcFile);
    $file = new FileDiff('/dev/null', 'app/Foo.php', FileStatus::ADDED);

    $changes = (new FileComplexityRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('does not fire for deleted files', function () use ($warnCcFile) {
    $comparison = (new AstComparer)->compare($warnCcFile, null);
    $file = new FileDiff('app/Foo.php', '/dev/null', FileStatus::DELETED);

    $changes = (new FileComplexityRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('fires for renamed files', function () use ($goodCcFile, $warnCcFile) {
    $comparison = (new AstComparer)->compare($goodCcFile, $warnCcFile);
    $file = new FileDiff('app/OldFoo.php', 'app/Foo.php', FileStatus::RENAMED);

    $changes = (new FileComplexityRule)->analyze($file, $comparison);

    $ccChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'cc')));

    expect($ccChanges)->toHaveCount(1)
        ->and($ccChanges[0]->severity)->toBe(Severity::LOW);
});

it('does not fire when either side has no methods', function () {
    $noMethods = '<?php class Foo {}';
    $withMethods = makeFile(methodCount: 5, ifCount: 5);

    $comparison = (new AstComparer)->compare($noMethods, $withMethods);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new FileComplexityRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
