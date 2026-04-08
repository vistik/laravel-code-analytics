<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConsoleArgumentAddedRule;

$commandFile = new FileDiff('app/Console/Commands/FooCommand.php', 'app/Console/Commands/FooCommand.php', FileStatus::MODIFIED);

it('detects new optional argument added', function () use ($commandFile) {
    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar {name?}"; public function handle() {} }';

    $changes = (new LaravelConsoleArgumentAddedRule)->analyze($commandFile, (new AstComparer)->compare($old, $new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::LOW)
        ->and($changes[0]->description)->toContain("New argument 'name' added");
});

it('detects new required argument added', function () use ($commandFile) {
    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar {name}"; public function handle() {} }';

    $changes = (new LaravelConsoleArgumentAddedRule)->analyze($commandFile, (new AstComparer)->compare($old, $new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::HIGH)
        ->and($changes[0]->description)->toContain("New argument 'name' added");
});

it('detects new option added', function () use ($commandFile) {
    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar {--force}"; public function handle() {} }';

    $changes = (new LaravelConsoleArgumentAddedRule)->analyze($commandFile, (new AstComparer)->compare($old, $new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::LOW)
        ->and($changes[0]->description)->toContain("New option '--force' added");
});

it('ignores non-command files', function () {
    $old = '<?php namespace App\Services; class FooService { protected $signature = "foo:bar"; }';
    $new = '<?php namespace App\Services; class FooService { protected $signature = "foo:bar {name}"; }';
    $file = new FileDiff('app/Services/FooService.php', 'app/Services/FooService.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleArgumentAddedRule)->analyze($file, (new AstComparer)->compare($old, $new));

    expect($changes)->toBeEmpty();
});
