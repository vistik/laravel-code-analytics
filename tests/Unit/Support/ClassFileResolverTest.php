<?php

use Vistik\LaravelCodeAnalytics\Support\ClassFileResolver;

// ── Helpers ───────────────────────────────────────────────────────────────────

function tempRepoWithPsr4(array $psr4, array $files = []): string
{
    $dir = sys_get_temp_dir().'/cfr_'.uniqid();
    mkdir($dir, 0755, true);

    file_put_contents($dir.'/composer.json', json_encode([
        'autoload' => ['psr-4' => $psr4],
    ]));

    foreach ($files as $relPath => $content) {
        $full = $dir.'/'.$relPath;
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, $content);
    }

    return $dir;
}

// ── PSR-4 resolution ──────────────────────────────────────────────────────────

test('resolves class via PSR-4 when file exists', function () {
    $root = tempRepoWithPsr4(
        ['App\\' => 'app/'],
        ['app/Services/OrderService.php' => '<?php class OrderService {}'],
    );

    $resolved = (new ClassFileResolver($root))->resolve('App\\Services\\OrderService');

    expect($resolved)->toBe($root.'/app/Services/OrderService.php');
});

test('returns null for PSR-4 class whose file does not exist', function () {
    $root = tempRepoWithPsr4(['App\\' => 'app/']);

    $resolved = (new ClassFileResolver($root))->resolve('App\\Services\\Missing');

    expect($resolved)->toBeNull();
});

test('longer PSR-4 prefix wins over shorter one', function () {
    $root = tempRepoWithPsr4(
        [
            'App\\' => 'app/',
            'App\\Admin\\' => 'admin/',
        ],
        ['admin/Dashboard.php' => '<?php class Dashboard {}'],
    );

    $resolved = (new ClassFileResolver($root))->resolve('App\\Admin\\Dashboard');

    expect($resolved)->toBe($root.'/admin/Dashboard.php');
});

test('strips leading backslash before resolving', function () {
    $root = tempRepoWithPsr4(
        ['App\\' => 'app/'],
        ['app/Foo.php' => '<?php class Foo {}'],
    );

    $resolved = (new ClassFileResolver($root))->resolve('\\App\\Foo');

    expect($resolved)->toBe($root.'/app/Foo.php');
});

// ── Classmap resolution ───────────────────────────────────────────────────────

test('resolves class via classmap when present', function () {
    $root = sys_get_temp_dir().'/cfr_cm_'.uniqid();
    mkdir($root.'/vendor/composer', 0755, true);
    $targetFile = $root.'/some/File.php';
    @mkdir(dirname($targetFile), 0755, true);
    file_put_contents($targetFile, '<?php class File {}');

    file_put_contents(
        $root.'/vendor/composer/autoload_classmap.php',
        '<?php return '.var_export(['Some\\File' => $targetFile], true).';',
    );
    file_put_contents($root.'/composer.json', '{}');

    $resolved = (new ClassFileResolver($root))->resolve('Some\\File');

    expect($resolved)->toBe($targetFile);
});

test('classmap takes priority over PSR-4', function () {
    $root = tempRepoWithPsr4(
        ['App\\' => 'app/'],
        ['app/Foo.php' => '<?php class Foo {}'],
    );

    $cmFile = $root.'/custom/Foo.php';
    @mkdir(dirname($cmFile), 0755, true);
    file_put_contents($cmFile, '<?php class Foo {}');

    mkdir($root.'/vendor/composer', 0755, true);
    file_put_contents(
        $root.'/vendor/composer/autoload_classmap.php',
        '<?php return '.var_export(['App\\Foo' => $cmFile], true).';',
    );

    $resolved = (new ClassFileResolver($root))->resolve('App\\Foo');

    expect($resolved)->toBe($cmFile);
});

// ── Vendor detection ─────────────────────────────────────────────────────────

test('isVendor returns true for paths inside vendor/', function () {
    $root = tempRepoWithPsr4([]);

    $r = new ClassFileResolver($root);
    expect($r->isVendor($root.'/vendor/laravel/framework/src/Foo.php'))->toBeTrue()
        ->and($r->isVendor($root.'/app/Foo.php'))->toBeFalse();
});
