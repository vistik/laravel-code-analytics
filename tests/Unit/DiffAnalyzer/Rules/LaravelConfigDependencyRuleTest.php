<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConfigDependencyRule;

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeRepoPath(): string
{
    $path = sys_get_temp_dir().'/config_dep_test_'.uniqid();
    mkdir($path, 0777, true);

    return $path;
}

function writeFile(string $repoPath, string $relPath, string $content): void
{
    $dir = dirname($repoPath.'/'.$relPath);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($repoPath.'/'.$relPath, $content);
}

function removeDir(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
    }
    rmdir($path);
}

function configFileDiff(string $name = 'services'): FileDiff
{
    return new FileDiff("config/{$name}.php", "config/{$name}.php", FileStatus::MODIFIED);
}

function phpFileDiff(string $path = 'app/Services/PaymentService.php'): FileDiff
{
    return new FileDiff($path, $path, FileStatus::MODIFIED);
}

// ── Config file changed: repo scan ───────────────────────────────────────────

it('returns empty when no repoPath is given for a config file change', function () {
    $file = configFileDiff('services');
    $comparison = (new AstComparer)->compare(
        '<?php return ["stripe" => ["key" => "old"]];',
        '<?php return ["stripe" => ["key" => "new"]];',
    );

    $changes = (new LaravelConfigDependencyRule(null))->analyze($file, $comparison);

    $depChanges = array_filter($changes, fn ($c) => str_contains($c->description, 'is read by'));
    expect(array_values($depChanges))->toBeEmpty();
});

it('reports consumers when a config file changes and files in repo read from it', function () {
    $repoPath = makeRepoPath();
    writeFile($repoPath, 'app/Services/StripeService.php', "<?php config('services.stripe.key');");
    writeFile($repoPath, 'app/Services/PaypalService.php', "<?php Config::get('services.paypal.secret');");

    $file = configFileDiff('services');
    $comparison = (new AstComparer)->compare(
        '<?php return ["stripe" => ["key" => "old"]];',
        '<?php return ["stripe" => ["key" => "new"]];',
    );

    $changes = (new LaravelConfigDependencyRule($repoPath))->analyze($file, $comparison);
    removeDir($repoPath);

    $depChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'is read by')));
    expect($depChanges)->toHaveCount(1)
        ->and($depChanges[0]->category)->toBe(ChangeCategory::LARAVEL_CONFIG)
        ->and($depChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($depChanges[0]->description)->toContain("Config 'services' is read by 2 files");
});

it('returns empty when no files in the repo read from the changed config', function () {
    $repoPath = makeRepoPath();
    writeFile($repoPath, 'app/Services/FooService.php', "<?php config('mail.from.address');");

    $file = configFileDiff('services');
    $comparison = (new AstComparer)->compare(
        '<?php return ["stripe" => ["key" => "old"]];',
        '<?php return ["stripe" => ["key" => "new"]];',
    );

    $changes = (new LaravelConfigDependencyRule($repoPath))->analyze($file, $comparison);
    removeDir($repoPath);

    $depChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'is read by')));
    expect($depChanges)->toBeEmpty();
});

it('skips vendor files when scanning for config consumers', function () {
    $repoPath = makeRepoPath();
    writeFile($repoPath, 'vendor/some/package/Foo.php', "<?php config('services.stripe');");

    $file = configFileDiff('services');
    $comparison = (new AstComparer)->compare(
        '<?php return [];',
        '<?php return ["new_key" => true];',
    );

    $changes = (new LaravelConfigDependencyRule($repoPath))->analyze($file, $comparison);
    removeDir($repoPath);

    $depChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'is read by')));
    expect($depChanges)->toBeEmpty();
});

it('does not count the config file itself as a consumer', function () {
    $repoPath = makeRepoPath();
    // The config file references itself (unusual but possible)
    writeFile($repoPath, 'config/services.php', "<?php config('services.stripe');");

    $file = configFileDiff('services');
    $comparison = (new AstComparer)->compare('<?php return [];', '<?php return ["x" => 1];');

    $changes = (new LaravelConfigDependencyRule($repoPath))->analyze($file, $comparison);
    removeDir($repoPath);

    $depChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'is read by')));
    expect($depChanges)->toBeEmpty();
});

it('shows up to 5 file names and an overflow count in the description', function () {
    $repoPath = makeRepoPath();
    foreach (range(1, 7) as $i) {
        writeFile($repoPath, "app/Services/Service{$i}.php", "<?php config('services.key');");
    }

    $file = configFileDiff('services');
    $comparison = (new AstComparer)->compare('<?php return [];', '<?php return ["x" => 1];');

    $changes = (new LaravelConfigDependencyRule($repoPath))->analyze($file, $comparison);
    removeDir($repoPath);

    $depChanges = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'is read by')));
    expect($depChanges)->toHaveCount(1)
        ->and($depChanges[0]->description)->toContain('7 files')
        ->and($depChanges[0]->description)->toContain('(+2 more)');
});

// ── PHP file changed: dependency detection ────────────────────────────────────

it('detects added config() dependency when a PHP file is changed', function () {
    $old = '<?php class Foo { public function handle() { return 42; } }';
    $new = '<?php class Foo { public function handle() { return config("services.stripe.key"); } }';

    $file = phpFileDiff();
    $comparison = (new AstComparer)->compare($old, $new);

    $changes = (new LaravelConfigDependencyRule(null))->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Added dependency')));
    expect($added)->toHaveCount(1)
        ->and($added[0]->category)->toBe(ChangeCategory::LARAVEL_CONFIG)
        ->and($added[0]->severity)->toBe(Severity::LOW)
        ->and($added[0]->description)->toContain("config 'services'")
        ->and($added[0]->description)->toContain('config/services.php');
});

it('detects removed config() dependency when a PHP file is changed', function () {
    $old = '<?php class Foo { public function handle() { return config("services.stripe.key"); } }';
    $new = '<?php class Foo { public function handle() { return "hardcoded"; } }';

    $file = phpFileDiff();
    $comparison = (new AstComparer)->compare($old, $new);

    $changes = (new LaravelConfigDependencyRule(null))->analyze($file, $comparison);

    $removed = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Removed dependency')));
    expect($removed)->toHaveCount(1)
        ->and($removed[0]->severity)->toBe(Severity::LOW)
        ->and($removed[0]->description)->toContain("config 'services'");
});

it('detects Config::get() facade calls as a dependency', function () {
    $old = '<?php class Foo {}';
    $new = '<?php use Illuminate\Support\Facades\Config; class Foo { public function handle() { return Config::get("mail.from.address"); } }';

    $file = phpFileDiff();
    $comparison = (new AstComparer)->compare($old, $new);

    $changes = (new LaravelConfigDependencyRule(null))->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Added dependency')));
    expect($added)->toHaveCount(1)
        ->and($added[0]->description)->toContain("config 'mail'");
});

it('detects Config::has() facade calls as a dependency', function () {
    $old = '<?php class Foo {}';
    $new = '<?php class Foo { public function handle() { return Config::has("queue.connections.redis"); } }';

    $file = phpFileDiff();
    $comparison = (new AstComparer)->compare($old, $new);

    $changes = (new LaravelConfigDependencyRule(null))->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Added dependency')));
    expect($added)->toHaveCount(1)
        ->and($added[0]->description)->toContain("config 'queue'");
});

it('emits no changes when the same config namespaces are used before and after', function () {
    $code = '<?php class Foo { public function handle() { return config("services.stripe.key"); } }';

    $file = phpFileDiff();
    $comparison = (new AstComparer)->compare($code, $code);

    $changes = (new LaravelConfigDependencyRule(null))->analyze($file, $comparison);

    $depChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Added dependency') || str_contains($c->description, 'Removed dependency'),
    ));
    expect($depChanges)->toBeEmpty();
});

it('deduplicates the same config namespace used multiple times in a file', function () {
    $old = '<?php class Foo {}';
    $new = '<?php class Foo { public function a() { config("services.stripe"); } public function b() { config("services.paypal"); } }';

    $file = phpFileDiff();
    $comparison = (new AstComparer)->compare($old, $new);

    $changes = (new LaravelConfigDependencyRule(null))->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Added dependency')));
    // Both calls share the "services" namespace — should only produce one finding
    expect($added)->toHaveCount(1)
        ->and($added[0]->description)->toContain("config 'services'");
});

it('reports multiple distinct namespace dependencies as separate findings', function () {
    $old = '<?php class Foo {}';
    $new = '<?php class Foo { public function handle() { config("services.stripe"); Config::get("mail.from"); } }';

    $file = phpFileDiff();
    $comparison = (new AstComparer)->compare($old, $new);

    $changes = (new LaravelConfigDependencyRule(null))->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Added dependency')));
    $namespaces = array_map(fn ($c) => $c->description, $added);

    expect($added)->toHaveCount(2)
        ->and(implode(' ', $namespaces))->toContain("config 'services'")
        ->and(implode(' ', $namespaces))->toContain("config 'mail'");
});

it('ignores non-php files', function () {
    $file = new FileDiff('resources/js/app.js', 'resources/js/app.js', FileStatus::MODIFIED);
    $comparison = (new AstComparer)->compare('', '');

    $changes = (new LaravelConfigDependencyRule(null))->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
