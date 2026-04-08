<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConsoleSignatureChangedRule;

$commandFile = new FileDiff('app/Console/Commands/FooCommand.php', 'app/Console/Commands/FooCommand.php', FileStatus::MODIFIED);

it('detects signature change at MEDIUM when not scheduled', function () use ($commandFile) {
    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:baz"; public function handle() {} }';

    $changes = (new LaravelConsoleSignatureChangedRule)->analyze($commandFile, (new AstComparer)->compare($old, $new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('$signature')
        ->and($changes[0]->description)->toContain('CLI interface changed');
});

it('detects $name change at MEDIUM when not scheduled', function () use ($commandFile) {
    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $name = "foo:bar"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $name = "foo:baz"; public function handle() {} }';

    $changes = (new LaravelConsoleSignatureChangedRule)->analyze($commandFile, (new AstComparer)->compare($old, $new));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('$name');
});

it('elevates to VERY_HIGH when command is hardcoded in schedule', function () use ($commandFile) {
    $repoPath = sys_get_temp_dir().'/console_sig_test_'.uniqid();
    mkdir($repoPath.'/routes', 0777, true);
    file_put_contents($repoPath.'/routes/console.php', "<?php Schedule::command('foo:bar')->daily();");

    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:baz"; public function handle() {} }';

    $changes = (new LaravelConsoleSignatureChangedRule($repoPath))->analyze($commandFile, (new AstComparer)->compare($old, $new));

    unlink($repoPath.'/routes/console.php');
    rmdir($repoPath.'/routes');
    rmdir($repoPath);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($changes[0]->description)->toContain('hardcoded in schedule');
});

it('ignores non-command files', function () {
    $old = '<?php namespace App\Services; class FooService { protected $signature = "foo:bar"; }';
    $new = '<?php namespace App\Services; class FooService { protected $signature = "foo:baz"; }';
    $file = new FileDiff('app/Services/FooService.php', 'app/Services/FooService.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleSignatureChangedRule)->analyze($file, (new AstComparer)->compare($old, $new));

    expect($changes)->toBeEmpty();
});
