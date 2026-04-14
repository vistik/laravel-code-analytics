<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConsoleRule;

it('detects handle method change', function () {
    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() { $this->info("hello"); } }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() { $this->info("goodbye"); $this->line("done"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Console/Commands/FooCommand.php', 'app/Console/Commands/FooCommand.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleRule)->analyze($file, $comparison);

    $handleChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'handle()'),
    ));

    expect($handleChanges)->toHaveCount(1)
        ->and($handleChanges[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($handleChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($handleChanges[0]->description)->toContain('Artisan command handle() changed');
});

it('ignores non-command files', function () {
    $old = '<?php namespace App\Services; class FooService { protected $signature = "foo:bar"; public function handle() { return true; } }';
    $new = '<?php namespace App\Services; class FooService { protected $signature = "foo:baz"; public function handle() { return false; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Services/FooService.php', 'app/Services/FooService.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('detects commented-out scheduled command as HIGH', function () {
    $old = <<<'PHP'
        <?php
        Schedule::command('lago:sync-subscriptions')->onOneServer()->dailyAt('04:10');
        PHP;

    $new = <<<'PHP'
        <?php
        // Schedule::command('lago:sync-subscriptions')->onOneServer()->dailyAt('04:10');
        PHP;

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('routes/console.php', 'routes/console.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::HIGH)
        ->and($changes[0]->description)->toContain('lago:sync-subscriptions')
        ->and($changes[0]->description)->toContain('disabled via comment');
});

it('detects truly removed scheduled command as HIGH', function () {
    $old = <<<'PHP'
        <?php
        Schedule::command('lago:sync-subscriptions')->onOneServer()->dailyAt('04:10');
        PHP;

    $new = <<<'PHP'
        <?php
        PHP;

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('routes/console.php', 'routes/console.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::HIGH)
        ->and($changes[0]->description)->toContain('lago:sync-subscriptions');
});

it('detects commented-out scheduled job as HIGH', function () {
    $old = <<<'PHP'
        <?php
        Schedule::job(SyncSubscriptionsJob::class)->onOneServer()->dailyAt('04:10');
        PHP;

    $new = <<<'PHP'
        <?php
        // Schedule::job(SyncSubscriptionsJob::class)->onOneServer()->dailyAt('04:10');
        PHP;

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('routes/console.php', 'routes/console.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::HIGH)
        ->and($changes[0]->description)->toContain('SyncSubscriptionsJob')
        ->and($changes[0]->description)->toContain('disabled via comment');
});

it('detects truly removed scheduled job as HIGH', function () {
    $old = <<<'PHP'
        <?php
        $schedule->job(SyncSubscriptionsJob::class)->onOneServer()->dailyAt('04:10');
        PHP;

    $new = <<<'PHP'
        <?php
        PHP;

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('routes/console.php', 'routes/console.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::HIGH)
        ->and($changes[0]->description)->toContain('SyncSubscriptionsJob');
});

it('detects truly removed static scheduled job (Schedule::job) as HIGH', function () {
    $old = <<<'PHP'
        <?php
        Schedule::job(SyncSubscriptionsJob::class)->onOneServer()->dailyAt('04:10');
        PHP;

    $new = <<<'PHP'
        <?php
        PHP;

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('routes/console.php', 'routes/console.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::HIGH)
        ->and($changes[0]->description)->toContain('SyncSubscriptionsJob');
});

it('detects commented-out instance-style scheduled job ($schedule->job) as HIGH', function () {
    $old = <<<'PHP'
        <?php
        $schedule->job(SyncSubscriptionsJob::class)->onOneServer()->dailyAt('04:10');
        PHP;

    $new = <<<'PHP'
        <?php
        // $schedule->job(SyncSubscriptionsJob::class)->onOneServer()->dailyAt('04:10');
        PHP;

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('routes/console.php', 'routes/console.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::HIGH)
        ->and($changes[0]->description)->toContain('SyncSubscriptionsJob')
        ->and($changes[0]->description)->toContain('disabled via comment');
});

it('does not flag a newly added scheduled job', function () {
    $old = <<<'PHP'
        <?php
        PHP;

    $new = <<<'PHP'
        <?php
        Schedule::job(SyncSubscriptionsJob::class)->onOneServer()->dailyAt('04:10');
        PHP;

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('routes/console.php', 'routes/console.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleRule)->analyze($file, $comparison);

    $highChanges = array_values(array_filter($changes, fn ($c) => $c->severity === Severity::HIGH));

    expect($highChanges)->toBeEmpty();
});
