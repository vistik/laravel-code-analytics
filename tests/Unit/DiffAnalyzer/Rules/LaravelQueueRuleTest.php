<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelQueueRule;

it('detects ShouldQueue interface added', function () {
    $old = '<?php namespace App\Jobs; use Illuminate\Bus\Queueable; class ProcessPodcast { use Queueable; public function handle() { } }';
    $new = '<?php namespace App\Jobs; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Bus\Queueable; class ProcessPodcast implements ShouldQueue { use Queueable; public function handle() { } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Jobs/ProcessPodcast.php', 'app/Jobs/ProcessPodcast.php', FileStatus::MODIFIED);

    $changes = (new LaravelQueueRule)->analyze($file, $comparison);

    $interfaceChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'ShouldQueue added'));

    expect($interfaceChange)->not->toBeNull()
        ->and($interfaceChange->category)->toBe(ChangeCategory::LARAVEL)
        ->and($interfaceChange->severity)->toBe(Severity::MEDIUM)
        ->and($interfaceChange->description)->toContain('asynchronous');
});

it('detects job property changed', function () {
    $old = '<?php namespace App\Jobs; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Bus\Queueable; class ProcessPodcast implements ShouldQueue { use Queueable; public $tries = 3; public function handle() { } }';
    $new = '<?php namespace App\Jobs; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Bus\Queueable; class ProcessPodcast implements ShouldQueue { use Queueable; public $tries = 5; public function handle() { } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Jobs/ProcessPodcast.php', 'app/Jobs/ProcessPodcast.php', FileStatus::MODIFIED);

    $changes = (new LaravelQueueRule)->analyze($file, $comparison);

    $propChange = collect($changes)->first(fn ($c) => str_contains($c->description, '$tries changed'));

    expect($propChange)->not->toBeNull()
        ->and($propChange->category)->toBe(ChangeCategory::LARAVEL)
        ->and($propChange->severity)->toBe(Severity::MEDIUM)
        ->and($propChange->description)->toContain('ProcessPodcast');
});

it('detects handle method changed', function () {
    $old = '<?php namespace App\Jobs; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Bus\Queueable; class ProcessPodcast implements ShouldQueue { use Queueable; public function handle() { logger("processing"); } }';
    $new = '<?php namespace App\Jobs; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Bus\Queueable; class ProcessPodcast implements ShouldQueue { use Queueable; public function handle() { logger("processing v2"); dispatch(new self); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Jobs/ProcessPodcast.php', 'app/Jobs/ProcessPodcast.php', FileStatus::MODIFIED);

    $changes = (new LaravelQueueRule)->analyze($file, $comparison);

    $handleChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'handle()'));

    expect($handleChange)->not->toBeNull()
        ->and($handleChange->category)->toBe(ChangeCategory::LARAVEL)
        ->and($handleChange->severity)->toBe(Severity::MEDIUM)
        ->and($handleChange->description)->toContain('ProcessPodcast::handle');
});

it('ignores non-job files', function () {
    $old = '<?php namespace App\Services; use Illuminate\Contracts\Queue\ShouldQueue; class ProcessPodcast implements ShouldQueue { public $tries = 3; public function handle() { } }';
    $new = '<?php namespace App\Services; use Illuminate\Contracts\Queue\ShouldQueue; class ProcessPodcast implements ShouldQueue { public $tries = 5; public function handle() { logger("done"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Services/ProcessPodcast.php', 'app/Services/ProcessPodcast.php', FileStatus::MODIFIED);

    $changes = (new LaravelQueueRule)->analyze($file, $comparison);

    // Queue interfaces are detected regardless of path, but handle() and property changes
    // on files outside Jobs/Listeners only fire for classes implementing ShouldQueue
    $handleChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'handle()'));

    expect($handleChange)->toBeNull();
});
