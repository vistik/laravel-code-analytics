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

it('elevates to MEDIUM when command is called in a test file', function () use ($commandFile) {
    $repoPath = sys_get_temp_dir().'/console_sig_test_'.uniqid();
    mkdir($repoPath.'/tests/Feature', 0777, true);
    file_put_contents($repoPath.'/tests/Feature/FooCommandTest.php', "<?php class FooCommandTest extends TestCase { public function test_it_runs() { \$this->artisan('foo:bar'); } }");

    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:baz"; public function handle() {} }';

    $changes = (new LaravelConsoleSignatureChangedRule($repoPath))->analyze($commandFile, (new AstComparer)->compare($old, $new));

    unlink($repoPath.'/tests/Feature/FooCommandTest.php');
    rmdir($repoPath.'/tests/Feature');
    rmdir($repoPath.'/tests');
    rmdir($repoPath);

    $testChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'called in tests'));
    expect($testChange)->not->toBeNull()
        ->and($testChange->severity)->toBe(Severity::MEDIUM)
        ->and($testChange->description)->toContain('FooCommandTest.php');
});

it('elevates to VERY_HIGH when command is called in production code via Artisan::call', function () use ($commandFile) {
    $repoPath = sys_get_temp_dir().'/console_sig_test_'.uniqid();
    mkdir($repoPath.'/app/Services', 0777, true);
    file_put_contents($repoPath.'/app/Services/BackupService.php', "<?php class BackupService { public function run() { Artisan::call('foo:bar'); } }");

    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:baz"; public function handle() {} }';

    $changes = (new LaravelConsoleSignatureChangedRule($repoPath))->analyze($commandFile, (new AstComparer)->compare($old, $new));

    unlink($repoPath.'/app/Services/BackupService.php');
    rmdir($repoPath.'/app/Services');
    rmdir($repoPath.'/app');
    rmdir($repoPath);

    $codeChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'called in production code'));
    expect($codeChange)->not->toBeNull()
        ->and($codeChange->severity)->toBe(Severity::VERY_HIGH)
        ->and($codeChange->description)->toContain('BackupService.php');
});

it('reports all callers as dependents when a signature changes — test ($this->artisan), Artisan::call, and Artisan::queue', function () use ($commandFile) {
    $repoPath = sys_get_temp_dir().'/console_sig_test_'.uniqid();
    mkdir($repoPath.'/tests/Feature', 0777, true);
    mkdir($repoPath.'/app/Jobs', 0777, true);
    mkdir($repoPath.'/app/Services', 0777, true);

    // Test using $this->artisan() — valid because it extends TestCase
    file_put_contents(
        $repoPath.'/tests/Feature/FooCommandTest.php',
        "<?php class FooCommandTest extends TestCase { public function test_it_runs() { \$this->artisan('foo:bar'); } }"
    );

    // Production code using Artisan::call()
    file_put_contents(
        $repoPath.'/app/Services/BackupService.php',
        "<?php class BackupService { public function run() { Artisan::call('foo:bar'); } }"
    );

    // Production code using Artisan::queue()
    file_put_contents(
        $repoPath.'/app/Jobs/DispatchBackup.php',
        "<?php class DispatchBackup { public function handle() { Artisan::queue('foo:bar'); } }"
    );

    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:baz"; public function handle() {} }';

    $changes = (new LaravelConsoleSignatureChangedRule($repoPath))->analyze($commandFile, (new AstComparer)->compare($old, $new));

    unlink($repoPath.'/tests/Feature/FooCommandTest.php');
    unlink($repoPath.'/app/Services/BackupService.php');
    unlink($repoPath.'/app/Jobs/DispatchBackup.php');
    rmdir($repoPath.'/tests/Feature');
    rmdir($repoPath.'/tests');
    rmdir($repoPath.'/app/Services');
    rmdir($repoPath.'/app/Jobs');
    rmdir($repoPath.'/app');
    rmdir($repoPath);

    $testChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'called in tests'));
    $codeChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'called in production code'));

    // Test dependency: FooCommandTest depends on foo:bar via $this->artisan()
    expect($testChange)->not->toBeNull()
        ->and($testChange->severity)->toBe(Severity::MEDIUM)
        ->and($testChange->description)->toContain('FooCommandTest.php');

    // Production dependencies: BackupService and DispatchBackup depend on foo:bar via Artisan::call/queue
    expect($codeChange)->not->toBeNull()
        ->and($codeChange->severity)->toBe(Severity::VERY_HIGH)
        ->and($codeChange->description)->toContain('BackupService.php')
        ->and($codeChange->description)->toContain('DispatchBackup.php');
});

it('ignores ->artisan() calls in non-test classes without InteractsWithConsole', function () use ($commandFile) {
    $repoPath = sys_get_temp_dir().'/console_sig_test_'.uniqid();
    mkdir($repoPath.'/tests/Feature', 0777, true);
    // A file in tests/ that does NOT extend TestCase or use InteractsWithConsole
    file_put_contents($repoPath.'/tests/Feature/FooHelper.php', "<?php class FooHelper { public function run() { \$this->artisan('foo:bar'); } }");

    $old = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:bar"; public function handle() {} }';
    $new = '<?php namespace App\Console\Commands; use Illuminate\Console\Command; class FooCommand extends Command { protected $signature = "foo:baz"; public function handle() {} }';

    $changes = (new LaravelConsoleSignatureChangedRule($repoPath))->analyze($commandFile, (new AstComparer)->compare($old, $new));

    unlink($repoPath.'/tests/Feature/FooHelper.php');
    rmdir($repoPath.'/tests/Feature');
    rmdir($repoPath.'/tests');
    rmdir($repoPath);

    $testChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'called in tests'));
    expect($testChange)->toBeNull();
});

it('ignores non-command files', function () {
    $old = '<?php namespace App\Services; class FooService { protected $signature = "foo:bar"; }';
    $new = '<?php namespace App\Services; class FooService { protected $signature = "foo:baz"; }';
    $file = new FileDiff('app/Services/FooService.php', 'app/Services/FooService.php', FileStatus::MODIFIED);

    $changes = (new LaravelConsoleSignatureChangedRule)->analyze($file, (new AstComparer)->compare($old, $new));

    expect($changes)->toBeEmpty();
});
