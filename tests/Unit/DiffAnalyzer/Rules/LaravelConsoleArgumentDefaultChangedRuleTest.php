<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConsoleArgumentDefaultChangedRule;

$commandFile = new FileDiff('app/Console/Commands/FooCommand.php', 'app/Console/Commands/FooCommand.php', FileStatus::MODIFIED);

it('detects argument default value changed', function () use ($commandFile) {
    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar {name=world}"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar {name=universe}"; public function handle() {} }';

    $changes = (new LaravelConsoleArgumentDefaultChangedRule)->analyze($commandFile, (new AstComparer)->compare($old, $new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain("'world'")
        ->and($changes[0]->description)->toContain("'universe'");
});

it('detects option default value changed', function () use ($commandFile) {
    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar {--format=json}"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar {--format=csv}"; public function handle() {} }';

    $changes = (new LaravelConsoleArgumentDefaultChangedRule)->analyze($commandFile, (new AstComparer)->compare($old, $new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain("'json'")
        ->and($changes[0]->description)->toContain("'csv'");
});

it('ignores non-command files', function () {
    $old = '<?php namespace App\Services; class FooService { protected $signature = "foo:bar {name=world}"; }';
    $new = '<?php namespace App\Services; class FooService { protected $signature = "foo:bar {name=universe}"; }';
    $file = new FileDiff('app/Services/FooService.php', 'app/Services/FooService.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleArgumentDefaultChangedRule)->analyze($file, (new AstComparer)->compare($old, $new));

    expect($changes)->toBeEmpty();
});
