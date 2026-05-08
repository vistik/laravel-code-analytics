<?php

use Vistik\LaravelCodeAnalytics\Support\PhpMethodMetricsCalculator;

function calcFlog(string $methodBody): float
{
    $code = "<?php\nclass Foo {\n    public function bar() {\n{$methodBody}\n    }\n}";
    $results = (new PhpMethodMetricsCalculator)->calculate(['Foo.php' => $code]);

    return $results['Foo.php'][0]->flog;
}

it('scores zero for an empty method', function () {
    $code = '<?php class Foo { public function bar() {} }';
    $results = (new PhpMethodMetricsCalculator)->calculate(['Foo.php' => $code]);

    expect($results['Foo.php'][0]->flog)->toBe(0.0);
});

it('counts assignments in A', function () {
    // A=3 (three assignments), B=0, C=0 → sqrt(9) = 3.0
    $flog = calcFlog('$x = 1; $y = 2; $z = 3;');

    expect($flog)->toBe(3.0);
});

it('counts compound assignments as A', function () {
    // A=2 (+=, -=), B=0, C=0 → sqrt(4) = 2.0
    $flog = calcFlog('$x += 1; $y -= 1;');

    expect($flog)->toBe(2.0);
});

it('counts increment/decrement as A', function () {
    // A=2 (++, --), B=0, C=0 → sqrt(4) = 2.0
    $flog = calcFlog('$x++; $y--;');

    expect($flog)->toBe(2.0);
});

it('counts method calls and operators as B', function () {
    // B=2 (method call + == operator), A=0, C=0 → sqrt(4) = 2.0
    $flog = calcFlog('$this->foo(); $a == $b;');

    expect($flog)->toBe(2.0);
});

it('counts if and else as C', function () {
    // C=2 (if + else), B=0, A=0 → sqrt(4) = 2.0
    $flog = calcFlog('if ($x) { } else { }');

    expect($flog)->toBe(2.0);
});

it('counts foreach as C', function () {
    // C=1 (foreach), B=0, A=0 → sqrt(1) = 1.0
    $flog = calcFlog('foreach ($items as $item) { }');

    expect($flog)->toBe(1.0);
});

it('combines A, B, C into pythagorean distance', function () {
    // A=1 ($x = 1), B=1 (+ operator), C=1 (if)
    // sqrt(1 + 1 + 1) ≈ 1.7
    $flog = calcFlog('$x = 1; $a + $b; if ($x) {}');

    expect($flog)->toBe(1.7);
});

it('counts new instantiation as B', function () {
    // B=1 (new), A=1 (=), C=0 → sqrt(1+1) ≈ 1.4
    $flog = calcFlog('$obj = new stdClass();');

    expect($flog)->toBe(1.4);
});

it('counts boolean operators as C not B', function () {
    // C=2 (&&, ||), B=0, A=0 → sqrt(4) = 2.0
    $flog = calcFlog('$a && $b; $c || $d;');

    expect($flog)->toBe(2.0);
});

it('counts switch cases as C', function () {
    // C=3 (switch + 2 cases with conditions), B=0, A=0 → sqrt(9) = 3.0
    $flog = calcFlog('switch ($x) { case 1: break; case 2: break; default: break; }');

    expect($flog)->toBe(3.0);
});

it('includes flog in toArray output', function () {
    $code = '<?php class Foo { public function bar() { $x = 1; } }';
    $results = (new PhpMethodMetricsCalculator)->calculate(['Foo.php' => $code]);
    $array = $results['Foo.php'][0]->toArray();

    expect($array)->toHaveKey('flog')
        ->and($array['flog'])->toBe(1.0);
});
