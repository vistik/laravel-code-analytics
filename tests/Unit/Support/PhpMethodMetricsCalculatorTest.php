<?php

use Vistik\LaravelCodeAnalytics\Support\PhpMethodMetricsCalculator;

it('returns empty array when no content given', function () {
    expect((new PhpMethodMetricsCalculator)->calculateClasses([]))->toBe([]);
});

it('skips null and empty sources', function () {
    expect((new PhpMethodMetricsCalculator)->calculateClasses([
        'a.php' => null,
        'b.php' => '',
    ]))->toBe([]);
});

it('aggregates per-class metrics from method metrics', function () {
    $classes = (new PhpMethodMetricsCalculator)->calculateClasses([
        'app/Foo.php' => <<<'PHP'
            <?php
            class Foo
            {
                public function simple(): int
                {
                    return 1;
                }

                public function branchy(int $n): int
                {
                    if ($n > 0) {
                        return $n;
                    }

                    return $n > -5 ? 0 : -1;
                }
            }
            PHP,
    ]);

    expect($classes)->toHaveKey('app/Foo.php');

    $foo = $classes['app/Foo.php'][0];

    expect($foo->name)->toBe('Foo')
        ->and($foo->kind)->toBe('class')
        ->and($foo->line)->toBe(2)
        ->and($foo->methods)->toBe(2)
        // simple() cc=1, branchy() cc=1 + if + ternary = 3 → wmc=4
        ->and($foo->wmc)->toBe(4)
        ->and($foo->ccAvg)->toBe(2.0)
        ->and($foo->maxCc)->toBe(3)
        ->and($foo->lloc)->toBeGreaterThan(0);
});

it('reports the class kind for interfaces, traits, and enums', function () {
    $classes = (new PhpMethodMetricsCalculator)->calculateClasses([
        'src/Contract.php' => "<?php\ninterface Contract { public function run(): void; }",
        'src/Helper.php' => "<?php\ntrait Helper { public function help(): void {} }",
        'src/Status.php' => "<?php\nenum Status { case Active; }",
    ]);

    expect($classes['src/Contract.php'][0]->kind)->toBe('interface')
        ->and($classes['src/Helper.php'][0]->kind)->toBe('trait')
        ->and($classes['src/Status.php'][0]->kind)->toBe('enum');
});

it('reports every class in a multi-class file', function () {
    $classes = (new PhpMethodMetricsCalculator)->calculateClasses([
        'app/Pair.php' => "<?php\nclass A { public function a() {} }\nclass B { public function b() {} }",
    ]);

    expect($classes['app/Pair.php'])->toHaveCount(2)
        ->and(array_map(fn ($c) => $c->name, $classes['app/Pair.php']))->toBe(['A', 'B']);
});

it('skips anonymous classes', function () {
    $classes = (new PhpMethodMetricsCalculator)->calculateClasses([
        'app/Anon.php' => "<?php\n\$x = new class { public function run() {} };",
    ]);

    expect($classes)->toBe([]);
});
