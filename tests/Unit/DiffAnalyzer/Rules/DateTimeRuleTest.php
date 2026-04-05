<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\DateTimeRule;

it('detects native time function added', function () {
    $old = '<?php class Foo { public function bar() { return 1; } }';
    $new = '<?php class Foo { public function bar() { return time(); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::DATETIME)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('Native time function added')
        ->and($changes[0]->description)->toContain('time()');
});

it('detects native time function removed', function () {
    $old = '<?php class Foo { public function bar() { return strtotime("2025-01-01"); } }';
    $new = '<?php class Foo { public function bar() { return 1735689600; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('Native time function removed')
        ->and($changes[0]->description)->toContain('strtotime()');
});

it('detects sleep function added', function () {
    $old = '<?php class Foo { public function bar() { $this->doWork(); } }';
    $new = '<?php class Foo { public function bar() { sleep(5); $this->doWork(); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('sleep()');
});

it('detects timezone override added as critical', function () {
    $old = '<?php class Foo { public function boot() { } }';
    $new = '<?php class Foo { public function boot() { date_default_timezone_set("America/New_York"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::DATETIME)
        ->and($changes[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($changes[0]->description)->toContain('Timezone override added')
        ->and($changes[0]->description)->toContain('date_default_timezone_set()');
});

it('detects timezone override removed as critical', function () {
    $old = '<?php class Foo { public function boot() { date_default_timezone_set("UTC"); } }';
    $new = '<?php class Foo { public function boot() { } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($changes[0]->description)->toContain('Timezone override removed');
});

it('detects Carbon::setTestNow added', function () {
    $old = '<?php class Foo { public function setUp() { } }';
    $new = '<?php
use Carbon\Carbon;
class Foo { public function setUp() { Carbon::setTestNow("2025-01-01"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::DATETIME)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('Test clock manipulation added')
        ->and($changes[0]->description)->toContain('Carbon::setTestNow()');
});

it('detects Carbon::setTestNow removed', function () {
    $old = '<?php
use Carbon\Carbon;
class Foo { public function tearDown() { Carbon::setTestNow(); } }';
    $new = '<?php class Foo { public function tearDown() { } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('Test clock manipulation removed')
        ->and($changes[0]->description)->toContain('Carbon::setTestNow()');
});

it('detects CarbonImmutable::setTestNow added', function () {
    $old = '<?php class Foo { public function setUp() { } }';
    $new = '<?php class Foo { public function setUp() { CarbonImmutable::setTestNow("2025-06-01"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->description)->toContain('CarbonImmutable::setTestNow()');
});

it('detects microtime added', function () {
    $old = '<?php class Foo { public function measure() { $this->run(); } }';
    $new = '<?php class Foo { public function measure() { $start = microtime(true); $this->run(); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('microtime()');
});

it('returns no changes when code is identical', function () {
    $code = '<?php class Foo { public function bar() { return now()->toDateString(); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('returns no changes when Carbon::now is unchanged', function () {
    $old = '<?php class Foo { public function bar() { return Carbon::now(); } }';
    $new = '<?php class Foo { public function bar() { return Carbon::now()->addDays(1); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('detects now() helper added as info', function () {
    $old = '<?php class Foo { public function bar() { return $this->created_at; } }';
    $new = '<?php class Foo { public function bar() { return now(); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::DATETIME)
        ->and($changes[0]->severity)->toBe(Severity::INFO)
        ->and($changes[0]->description)->toContain('Time helper added')
        ->and($changes[0]->description)->toContain('now()');
});

it('detects today() helper removed as info', function () {
    $old = '<?php class Foo { public function bar() { return today()->toDateString(); } }';
    $new = '<?php class Foo { public function bar() { return $this->date->toDateString(); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::INFO)
        ->and($changes[0]->description)->toContain('Time helper removed')
        ->and($changes[0]->description)->toContain('today()');
});

it('detects Carbon::now added as info', function () {
    $old = '<?php class Foo { public function bar() { return $this->created_at; } }';
    $new = '<?php class Foo { public function bar() { return Carbon::now(); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::DATETIME)
        ->and($changes[0]->severity)->toBe(Severity::INFO)
        ->and($changes[0]->description)->toContain('Carbon time instance added')
        ->and($changes[0]->description)->toContain('Carbon::now()');
});

it('detects Carbon::parse added as info', function () {
    $old = '<?php class Foo { public function bar() { return $this->date; } }';
    $new = '<?php class Foo { public function bar() { return Carbon::parse($this->date); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Foo.php', 'app/Foo.php', FileStatus::MODIFIED);

    $changes = (new DateTimeRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::INFO)
        ->and($changes[0]->description)->toContain('Carbon::parse()');
});
