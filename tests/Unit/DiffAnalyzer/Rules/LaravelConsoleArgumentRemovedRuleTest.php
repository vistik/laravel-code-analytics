<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConsoleArgumentRemovedRule;

$commandFile = new FileDiff('app/Console/Commands/FooCommand.php', 'app/Console/Commands/FooCommand.php', FileStatus::MODIFIED);

it('detects argument removed', function () use ($commandFile) {
    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar {name}"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() {} }';

    $changes = (new LaravelConsoleArgumentRemovedRule)->analyze($commandFile, (new AstComparer)->compare($old, $new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::HIGH)
        ->and($changes[0]->description)->toContain("argument 'name' removed");
});

it('detects option removed', function () use ($commandFile) {
    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar {--force}"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() {} }';

    $changes = (new LaravelConsoleArgumentRemovedRule)->analyze($commandFile, (new AstComparer)->compare($old, $new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::HIGH)
        ->and($changes[0]->description)->toContain("option '--force' removed");
});

it('ignores non-command files', function () {
    $old = '<?php namespace App\Services; class FooService { protected $signature = "foo:bar {name}"; }';
    $new = '<?php namespace App\Services; class FooService { protected $signature = "foo:bar"; }';
    $file = new FileDiff('app/Services/FooService.php', 'app/Services/FooService.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleArgumentRemovedRule)->analyze($file, (new AstComparer)->compare($old, $new));

    expect($changes)->toBeEmpty();
});
