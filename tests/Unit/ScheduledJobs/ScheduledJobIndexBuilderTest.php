<?php

use Vistik\LaravelCodeAnalytics\ScheduledJobs\ScheduledJobIndexBuilder;

function buildJobIndex(string $source, array $fqcnMap = []): array
{
    return (new ScheduledJobIndexBuilder)->build(
        consoleFileContents: ['routes/console.php' => $source],
        fqcnToPath: fn (string $fqcn) => $fqcnMap[$fqcn] ?? null,
    );
}

// ── Method call style ($schedule->command) ────────────────────────────────────

it('detects $schedule->command(Class::class)->daily()', function () {
    $source = <<<'PHP'
    <?php
    use App\Jobs\SendEmails;
    $schedule->command(SendEmails::class)->daily();
    PHP;

    $index = buildJobIndex($source, ['App\\Jobs\\SendEmails' => 'app/Jobs/SendEmails.php']);

    expect($index)->toHaveKey('app/Jobs/SendEmails.php');
    $job = $index['app/Jobs/SendEmails.php'][0];
    expect($job->handlerFqcn)->toBe('App\\Jobs\\SendEmails');
    expect($job->scheduleExpression)->toBe('->daily()');
});

it('detects $schedule->command(Class::class)->hourly()', function () {
    $source = <<<'PHP'
    <?php
    use App\Console\Commands\ProcessPodcast;
    $schedule->command(ProcessPodcast::class)->hourly();
    PHP;

    $index = buildJobIndex($source, ['App\\Console\\Commands\\ProcessPodcast' => 'app/Console/Commands/ProcessPodcast.php']);

    expect($index)->toHaveKey('app/Console/Commands/ProcessPodcast.php');
    expect($index['app/Console/Commands/ProcessPodcast.php'][0]->scheduleExpression)->toBe('->hourly()');
});

it('captures cron expression argument', function () {
    $source = <<<'PHP'
    <?php
    use App\Jobs\NightlyJob;
    $schedule->command(NightlyJob::class)->cron('0 0 * * *');
    PHP;

    $index = buildJobIndex($source, ['App\\Jobs\\NightlyJob' => 'app/Jobs/NightlyJob.php']);

    expect($index['app/Jobs/NightlyJob.php'][0]->scheduleExpression)->toBe("->cron('0 0 * * *')");
});

it('captures full fluent chain with multiple methods', function () {
    $source = <<<'PHP'
    <?php
    use App\Jobs\SendEmails;
    $schedule->command(SendEmails::class)->daily()->onOneServer();
    PHP;

    $index = buildJobIndex($source, ['App\\Jobs\\SendEmails' => 'app/Jobs/SendEmails.php']);

    expect($index['app/Jobs/SendEmails.php'][0]->scheduleExpression)->toBe('->daily()->onOneServer()');
});

// ── Job style ($schedule->job) ────────────────────────────────────────────────

it('detects $schedule->job(new Class)->weekly()', function () {
    $source = <<<'PHP'
    <?php
    use App\Jobs\CleanupJob;
    $schedule->job(new CleanupJob)->weekly();
    PHP;

    $index = buildJobIndex($source, ['App\\Jobs\\CleanupJob' => 'app/Jobs/CleanupJob.php']);

    expect($index)->toHaveKey('app/Jobs/CleanupJob.php');
    expect($index['app/Jobs/CleanupJob.php'][0]->handlerFqcn)->toBe('App\\Jobs\\CleanupJob');
    expect($index['app/Jobs/CleanupJob.php'][0]->scheduleExpression)->toBe('->weekly()');
});

// ── Static facade style (Laravel 11) ─────────────────────────────────────────

it('detects Schedule::command(Class::class)->daily() facade style', function () {
    $source = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Schedule;
    use App\Console\Commands\SendReport;
    Schedule::command(SendReport::class)->daily();
    PHP;

    $index = buildJobIndex($source, ['App\\Console\\Commands\\SendReport' => 'app/Console/Commands/SendReport.php']);

    expect($index)->toHaveKey('app/Console/Commands/SendReport.php');
    expect($index['app/Console/Commands/SendReport.php'][0]->scheduleExpression)->toBe('->daily()');
});

// ── Skipped / unresolvable entries ────────────────────────────────────────────

it('skips string command names (non-resolvable)', function () {
    $source = <<<'PHP'
    <?php
    $schedule->command('emails:send')->daily();
    PHP;

    $index = buildJobIndex($source);

    expect($index)->toBeEmpty();
});

it('skips closures', function () {
    $source = <<<'PHP'
    <?php
    $schedule->call(function () { })->daily();
    PHP;

    $index = buildJobIndex($source);

    expect($index)->toBeEmpty();
});

it('skips exec shell commands', function () {
    $source = <<<'PHP'
    <?php
    $schedule->exec('rm /tmp/stale')->daily();
    PHP;

    $index = buildJobIndex($source);

    expect($index)->toBeEmpty();
});

it('skips entries where FQCN cannot be resolved to a file path', function () {
    $source = <<<'PHP'
    <?php
    use App\Jobs\UnknownJob;
    $schedule->command(UnknownJob::class)->daily();
    PHP;

    // No entry in fqcnMap → path is null
    $index = buildJobIndex($source, []);

    expect($index)->toBeEmpty();
});

// ── Multiple entries ──────────────────────────────────────────────────────────

it('indexes multiple jobs from the same file', function () {
    $source = <<<'PHP'
    <?php
    use App\Jobs\JobA;
    use App\Jobs\JobB;
    $schedule->command(JobA::class)->daily();
    $schedule->command(JobB::class)->hourly();
    PHP;

    $index = buildJobIndex($source, [
        'App\\Jobs\\JobA' => 'app/Jobs/JobA.php',
        'App\\Jobs\\JobB' => 'app/Jobs/JobB.php',
    ]);

    expect($index)->toHaveKey('app/Jobs/JobA.php');
    expect($index)->toHaveKey('app/Jobs/JobB.php');
    expect($index['app/Jobs/JobA.php'][0]->scheduleExpression)->toBe('->daily()');
    expect($index['app/Jobs/JobB.php'][0]->scheduleExpression)->toBe('->hourly()');
});

it('groups multiple schedule entries for the same handler class', function () {
    $source = <<<'PHP'
    <?php
    use App\Jobs\ReportJob;
    $schedule->command(ReportJob::class)->daily();
    $schedule->command(ReportJob::class)->weekly();
    PHP;

    $index = buildJobIndex($source, ['App\\Jobs\\ReportJob' => 'app/Jobs/ReportJob.php']);

    expect($index['app/Jobs/ReportJob.php'])->toHaveCount(2);
    expect($index['app/Jobs/ReportJob.php'][0]->scheduleExpression)->toBe('->daily()');
    expect($index['app/Jobs/ReportJob.php'][1]->scheduleExpression)->toBe('->weekly()');
});

// ── Null / empty input ────────────────────────────────────────────────────────

it('returns empty index for null source', function () {
    $index = (new ScheduledJobIndexBuilder)->build(
        consoleFileContents: ['routes/console.php' => null],
        fqcnToPath: fn ($f) => null,
    );

    expect($index)->toBeEmpty();
});

it('returns empty index for empty source', function () {
    $index = buildJobIndex('');

    expect($index)->toBeEmpty();
});

it('returns empty index for source with no schedule calls', function () {
    $index = buildJobIndex('<?php // just a comment');

    expect($index)->toBeEmpty();
});

// ── bootstrap/app.php style (Laravel 11+) ────────────────────────────────────

it('detects commands inside ->withSchedule() closure in bootstrap/app.php', function () {
    $source = <<<'PHP'
    <?php
    use Illuminate\Foundation\Application;
    use Illuminate\Support\Facades\Schedule;
    use App\Jobs\InvoiceCustomers;
    use App\Jobs\SendReminders;

    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(web: __DIR__.'/../routes/web.php')
        ->withSchedule(function (Schedule $schedule): void {
            $schedule->command(InvoiceCustomers::class)->daily();
            $schedule->command(SendReminders::class)->weekly();
        })
        ->create();
    PHP;

    $index = buildJobIndex($source, [
        'App\\Jobs\\InvoiceCustomers' => 'app/Jobs/InvoiceCustomers.php',
        'App\\Jobs\\SendReminders'    => 'app/Jobs/SendReminders.php',
    ]);

    expect($index)->toHaveKey('app/Jobs/InvoiceCustomers.php');
    expect($index)->toHaveKey('app/Jobs/SendReminders.php');
    expect($index['app/Jobs/InvoiceCustomers.php'][0]->scheduleExpression)->toBe('->daily()');
    expect($index['app/Jobs/SendReminders.php'][0]->scheduleExpression)->toBe('->weekly()');
});

// ── Kernel.php style (older Laravel) ─────────────────────────────────────────

it('detects commands defined inside Kernel schedule() method', function () {
    $source = <<<'PHP'
    <?php
    namespace App\Console;
    use App\Jobs\GenerateReport;
    use Illuminate\Console\Scheduling\Schedule;
    class Kernel extends ConsoleKernel {
        protected function schedule(Schedule $schedule): void {
            $schedule->command(GenerateReport::class)->monthly();
        }
    }
    PHP;

    $index = buildJobIndex($source, ['App\\Jobs\\GenerateReport' => 'app/Jobs/GenerateReport.php']);

    expect($index)->toHaveKey('app/Jobs/GenerateReport.php');
    expect($index['app/Jobs/GenerateReport.php'][0]->scheduleExpression)->toBe('->monthly()');
});

// ── everyMinute and other frequency methods ───────────────────────────────────

it('handles everyMinute without arguments', function () {
    $source = <<<'PHP'
    <?php
    use App\Jobs\QuickJob;
    $schedule->command(QuickJob::class)->everyMinute();
    PHP;

    $index = buildJobIndex($source, ['App\\Jobs\\QuickJob' => 'app/Jobs/QuickJob.php']);

    expect($index['app/Jobs/QuickJob.php'][0]->scheduleExpression)->toBe('->everyMinute()');
});

it('handles dailyAt with time argument', function () {
    $source = <<<'PHP'
    <?php
    use App\Jobs\MorningJob;
    $schedule->command(MorningJob::class)->dailyAt('08:00');
    PHP;

    $index = buildJobIndex($source, ['App\\Jobs\\MorningJob' => 'app/Jobs/MorningJob.php']);

    expect($index['app/Jobs/MorningJob.php'][0]->scheduleExpression)->toBe("->dailyAt('08:00')");
});
