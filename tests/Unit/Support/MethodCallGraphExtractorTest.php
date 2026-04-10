<?php

use Vistik\LaravelCodeAnalytics\Support\MethodCallGraphExtractor;

// ── Helpers ───────────────────────────────────────────────────────────────────

function tempPhp(string $code): string
{
    $file = tempnam(sys_get_temp_dir(), 'mcge_').'.php';
    file_put_contents($file, "<?php\n{$code}");

    return $file;
}

/** Assert at least one edge matches [from, to, type] (ignoring the callLine 4th element). */
function expectEdge(array $edges, string $from, string $to, string $type): void
{
    $found = array_filter($edges, fn ($e) => $e[0] === $from && $e[1] === $to && $e[2] === $type);
    expect($found)->not->toBeEmpty("Expected edge [{$from} → {$to} ({$type})] not found");
}

// ── Basic extraction ──────────────────────────────────────────────────────────

test('extracts focal methods as nodes', function () {
    $file = tempPhp(<<<'PHP'
        class Foo {
            public function alpha(): void {}
            protected function beta(): void {}
            private function gamma(): void {}
        }
        PHP);

    $result = (new MethodCallGraphExtractor)->extract($file);
    $ids = array_column($result['nodes'], 'id');

    expect($ids)->toContain('Foo::alpha')
        ->toContain('Foo::beta')
        ->toContain('Foo::gamma');

    $alpha = collect($result['nodes'])->firstWhere('id', 'Foo::alpha');
    expect($alpha['focal'])->toBeTrue()
        ->and($alpha['isConnected'])->toBeFalse()
        ->and($alpha['visibility'])->toBe('public')
        ->and($alpha['group'])->toBe('vis_public');

    $gamma = collect($result['nodes'])->firstWhere('id', 'Foo::gamma');
    expect($gamma['visibility'])->toBe('private')
        ->and($gamma['group'])->toBe('vis_private');
});

test('sets displayLabel to method name and folder to short file path', function () {
    $file = tempPhp(<<<'PHP'
        class Bar {
            public function doSomething(): void {}
        }
        PHP);

    $result = (new MethodCallGraphExtractor)->extract($file);
    $node = $result['nodes'][0];

    $expectedFolder = basename(dirname($file)).'/'.basename($file, '.php');

    expect($node['displayLabel'])->toBe('doSomething')
        ->and($node['folder'])->toBe($expectedFolder);
});

// ── Intra-class call edges ────────────────────────────────────────────────────

test('detects $this->method() as this_call edge', function () {
    $file = tempPhp(<<<'PHP'
        class Svc {
            public function handle(): void { $this->process(); }
            private function process(): void {}
        }
        PHP);

    $result = (new MethodCallGraphExtractor)->extract($file);

    expectEdge($result['edges'], 'Svc::handle', 'Svc::process', 'this_call');
});

test('detects self::method() as this_call edge', function () {
    $file = tempPhp(<<<'PHP'
        class Svc {
            public function run(): void { self::boot(); }
            protected static function boot(): void {}
        }
        PHP);

    $result = (new MethodCallGraphExtractor)->extract($file);

    expectEdge($result['edges'], 'Svc::run', 'Svc::boot', 'this_call');
});

test('no self-loop edges', function () {
    $file = tempPhp(<<<'PHP'
        class Svc {
            public function foo(): void { $this->foo(); }
        }
        PHP);

    $result = (new MethodCallGraphExtractor)->extract($file);

    expect($result['edges'])->toBeEmpty();
});

test('deduplicates edges when same call appears multiple times', function () {
    $file = tempPhp(<<<'PHP'
        class Svc {
            public function run(): void {
                $this->helper();
                if (true) { $this->helper(); }
            }
            private function helper(): void {}
        }
        PHP);

    $result = (new MethodCallGraphExtractor)->extract($file);
    $matchingEdges = array_filter($result['edges'], fn ($e) => $e[0] === 'Svc::run' && $e[1] === 'Svc::helper');

    expect(count($matchingEdges))->toBe(1);
});

// ── External callee nodes ─────────────────────────────────────────────────────

test('creates external node for unresolved $this->call to inherited method', function () {
    $file = tempPhp(<<<'PHP'
        class Child {
            public function run(): void { $this->parentMethod(); }
        }
        PHP);

    $result = (new MethodCallGraphExtractor)->extract($file);

    $extNode = collect($result['nodes'])->firstWhere('id', 'Child::parentMethod');
    expect($extNode)->not->toBeNull()
        ->and($extNode['focal'])->toBeFalse()
        ->and($extNode['isConnected'])->toBeTrue()
        ->and($extNode['group'])->toBe('vis_external');
});

test('creates external node for property injection call', function () {
    $file = tempPhp(<<<'PHP'
        class OrderService {
            public function __construct(private PaymentGateway $gateway) {}
            public function charge(): void { $this->gateway->process(); }
        }
        PHP);

    $result = (new MethodCallGraphExtractor)->extract($file);

    $extNode = collect($result['nodes'])->firstWhere('id', 'PaymentGateway::process');
    expect($extNode)->not->toBeNull()
        ->and($extNode['focal'])->toBeFalse()
        ->and($extNode['class'])->toBe('PaymentGateway');

    expectEdge($result['edges'], 'OrderService::charge', 'PaymentGateway::process', 'external_call');
});

test('resolves explicit static call to external class', function () {
    $file = tempPhp(<<<'PHP'
        class MyCommand {
            public function handle(): void { Cache::forget('key'); }
        }
        PHP);

    $result = (new MethodCallGraphExtractor)->extract($file);

    $extNode = collect($result['nodes'])->firstWhere('id', 'Cache::forget');
    expect($extNode)->not->toBeNull();

    expectEdge($result['edges'], 'MyCommand::handle', 'Cache::forget', 'static_call');
});

// ── Edge cases ────────────────────────────────────────────────────────────────

test('returns empty for unparseable file', function () {
    $file = tempPhp('this is not valid php ???');
    $result = (new MethodCallGraphExtractor)->extract($file);

    expect($result['nodes'])->toBeEmpty()
        ->and($result['edges'])->toBeEmpty();
});

test('returns empty for file with no classes', function () {
    $file = tempPhp('function helper() { return 1; }');
    $result = (new MethodCallGraphExtractor)->extract($file);

    expect($result['nodes'])->toBeEmpty();
});

test('node add field reflects cyclomatic complexity', function () {
    $file = tempPhp(<<<'PHP'
        class Calc {
            public function simple(): void {}
            public function complex(): void {
                if (true) { } if (false) { } if (null) { }
            }
        }
        PHP);

    $result = (new MethodCallGraphExtractor)->extract($file);
    $simple = collect($result['nodes'])->firstWhere('id', 'Calc::simple');
    $complex = collect($result['nodes'])->firstWhere('id', 'Calc::complex');

    expect($complex['add'])->toBeGreaterThan($simple['add']);
});

// ── Recursive extraction ──────────────────────────────────────────────────────

function tempRepoForExtractor(array $files): string
{
    $root = sys_get_temp_dir().'/mcge_repo_'.uniqid();
    mkdir($root, 0755, true);

    $psr4 = ['App\\' => 'app/'];
    file_put_contents($root.'/composer.json', json_encode(['autoload' => ['psr-4' => $psr4]]));

    foreach ($files as $relPath => $content) {
        $full = $root.'/'.$relPath;
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, "<?php\n{$content}");
    }

    return $root;
}

test('depth=0 behaves the same as extract()', function () {
    $file = tempPhp(<<<'PHP'
        class Alpha {
            public function go(): void { $this->helper(); }
            private function helper(): void {}
        }
        PHP);

    $e = new MethodCallGraphExtractor;
    $a = $e->extract($file);
    $b = $e->extractRecursive($file, depth: 0);

    expect($b['nodes'])->toHaveCount(count($a['nodes']))
        ->and($b['edges'])->toHaveCount(count($a['edges']));
});

test('extractRecursive follows PSR-4 dependency one hop', function () {
    $root = tempRepoForExtractor([
        'app/Entry.php' => <<<'PHP'
            namespace App;
            use App\Support\Helper;
            class Entry {
                public function __construct(private Helper $helper) {}
                public function run(): void { $this->helper->doWork(); }
            }
            PHP,
        'app/Support/Helper.php' => <<<'PHP'
            namespace App\Support;
            class Helper {
                public function doWork(): void {}
                private function internal(): void {}
            }
            PHP,
    ]);

    $result = (new MethodCallGraphExtractor)->extractRecursive(
        $root.'/app/Entry.php',
        repoRoot: $root,
        depth: 1,
    );

    $ids = array_column($result['nodes'], 'id');

    // Entry file methods
    expect($ids)->toContain('Entry::run')
        ->toContain('Entry::__construct');

    // Dependency file methods that are actually called should now be real nodes
    expect($ids)->toContain('Helper::doWork');
    // Helper::internal is never called so it is pruned from the graph
    expect($ids)->not->toContain('Helper::internal');

    // The resolved dependency node must NOT be a stub (isConnected=false)
    $helperNode = collect($result['nodes'])->firstWhere('id', 'Helper::doWork');
    expect($helperNode['isConnected'])->toBeFalse()
        ->and($helperNode['focal'])->toBeFalse();

    // Entry method focal=true
    $entryNode = collect($result['nodes'])->firstWhere('id', 'Entry::run');
    expect($entryNode['focal'])->toBeTrue();

    // Call edge exists
    expectEdge($result['edges'], 'Entry::run', 'Helper::doWork', 'external_call');
});

test('extractRecursive does not follow vendor classes', function () {
    $root = tempRepoForExtractor([
        'app/Cmd.php' => <<<'PHP'
            namespace App;
            use Illuminate\Support\Facades\Cache;
            class Cmd {
                public function handle(): void { Cache::forget('k'); }
            }
            PHP,
    ]);

    // Simulate a vendor classmap entry pointing inside vendor/
    mkdir($root.'/vendor/composer', 0755, true);
    $vendorFile = $root.'/vendor/laravel/framework/Cache.php';
    @mkdir(dirname($vendorFile), 0755, true);
    file_put_contents($vendorFile, '<?php class Cache {}');
    file_put_contents(
        $root.'/vendor/composer/autoload_classmap.php',
        '<?php return '.var_export(['Illuminate\\Support\\Facades\\Cache' => $vendorFile], true).';',
    );

    $result = (new MethodCallGraphExtractor)->extractRecursive(
        $root.'/app/Cmd.php',
        repoRoot: $root,
        depth: 1,
    );

    $ids = array_column($result['nodes'], 'id');

    // Cache::forget should remain a stub (isConnected=true), not expanded
    $cacheNode = collect($result['nodes'])->firstWhere('id', 'Cache::forget');
    expect($cacheNode)->not->toBeNull()
        ->and($cacheNode['isConnected'])->toBeTrue(); // still a stub, vendor was skipped
});

test('extractRecursive avoids visiting the same file twice', function () {
    $root = tempRepoForExtractor([
        'app/A.php' => <<<'PHP'
            namespace App;
            use App\B;
            class A {
                public function __construct(private B $b) {}
                public function run(): void { $this->b->go(); }
            }
            PHP,
        'app/B.php' => <<<'PHP'
            namespace App;
            use App\A;
            class B {
                public function __construct(private A $a) {}
                public function go(): void { $this->a->run(); }
            }
            PHP,
    ]);

    // Should not infinite-loop on circular dependencies
    $result = (new MethodCallGraphExtractor)->extractRecursive(
        $root.'/app/A.php',
        repoRoot: $root,
        depth: 2,
    );

    $ids = array_column($result['nodes'], 'id');
    expect($ids)->toContain('A::run')->toContain('B::go');
    // Each method ID should appear only once
    expect(count(array_unique($ids)))->toBe(count($ids));
});
