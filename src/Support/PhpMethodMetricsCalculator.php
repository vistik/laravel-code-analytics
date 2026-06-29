<?php

namespace Vistik\LaravelCodeAnalytics\Support;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class PhpMethodMetricsCalculator
{
    private Parser $parser;

    private NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->finder = new NodeFinder;
    }

    /**
     * Calculate per-method metrics for the given PHP files.
     *
     * @param  array<string, string|null>  $pathToContent  Relative file path → PHP source
     * @return array<string, list<PhpMethodMetrics>> File path → list of method metrics
     */
    public function calculate(array $pathToContent): array
    {
        $results = [];

        foreach ($pathToContent as $path => $content) {
            if ($content === null || $content === '') {
                continue;
            }

            $methods = $this->analyzeFile($content);

            if (! empty($methods)) {
                $results[$path] = $methods;
            }
        }

        return $results;
    }

    /**
     * Calculate per-class aggregate metrics for the given PHP files.
     *
     * @param  array<string, string|null>  $pathToContent  Relative file path → PHP source
     * @return array<string, list<PhpClassMetrics>> File path → list of class metrics
     */
    public function calculateClasses(array $pathToContent): array
    {
        $results = [];

        foreach ($pathToContent as $path => $content) {
            if ($content === null || $content === '') {
                continue;
            }

            $classes = $this->analyzeClassesInFile($content);

            if (! empty($classes)) {
                $results[$path] = $classes;
            }
        }

        return $results;
    }

    /**
     * Calculate both per-method and per-class metrics in a single parse pass.
     *
     * Equivalent to calling calculate() and calculateClasses() on the same input,
     * but parses each file (and computes its method metrics) only once.
     *
     * @param  array<string, string|null>  $pathToContent  Relative file path → PHP source
     * @return array<string, array{methods: list<PhpMethodMetrics>, classes: list<PhpClassMetrics>}>
     */
    public function calculateAll(array $pathToContent): array
    {
        $results = [];

        foreach ($pathToContent as $path => $content) {
            if ($content === null || $content === '') {
                continue;
            }

            $classLikes = $this->parseClassLikes($content);
            if ($classLikes === []) {
                continue;
            }

            $methods = [];
            $classes = [];

            foreach ($classLikes as $classLike) {
                $classMethods = $this->methodMetricsFor($classLike);

                foreach ($classMethods as $method) {
                    $methods[] = $method;
                }

                $name = $classLike->name?->toString();
                if ($name === null) {
                    continue;
                }

                $ccValues = array_map(fn (PhpMethodMetrics $m) => $m->cc, $classMethods);
                $wmc = (int) array_sum($ccValues);
                $count = count($classMethods);

                $classes[] = new PhpClassMetrics(
                    name: $name,
                    kind: $this->classKind($classLike),
                    line: max(0, $classLike->getStartLine()),
                    methods: $count,
                    wmc: $wmc,
                    ccAvg: $count > 0 ? round($wmc / $count, 1) : 0.0,
                    maxCc: $ccValues === [] ? 0 : max($ccValues),
                    lloc: $this->lineSpan($classLike),
                );
            }

            $results[$path] = ['methods' => $methods, 'classes' => $classes];
        }

        return $results;
    }

    /**
     * @return list<PhpMethodMetrics>
     */
    private function analyzeFile(string $content): array
    {
        $classLikes = $this->parseClassLikes($content);

        $methods = [];

        foreach ($classLikes as $classLike) {
            foreach ($this->methodMetricsFor($classLike) as $method) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * @return list<PhpClassMetrics>
     */
    private function analyzeClassesInFile(string $content): array
    {
        $classLikes = $this->parseClassLikes($content);

        $classes = [];

        foreach ($classLikes as $classLike) {
            $name = $classLike->name?->toString();

            if ($name === null) {
                continue;
            }

            $methods = $this->methodMetricsFor($classLike);
            $ccValues = array_map(fn (PhpMethodMetrics $m) => $m->cc, $methods);
            $wmc = (int) array_sum($ccValues);
            $count = count($methods);

            $classes[] = new PhpClassMetrics(
                name: $name,
                kind: $this->classKind($classLike),
                line: max(0, $classLike->getStartLine()),
                methods: $count,
                wmc: $wmc,
                ccAvg: $count > 0 ? round($wmc / $count, 1) : 0.0,
                maxCc: $ccValues === [] ? 0 : max($ccValues),
                lloc: $this->lineSpan($classLike),
            );
        }

        return $classes;
    }

    /**
     * @return list<Stmt\ClassLike>
     */
    private function parseClassLikes(string $content): array
    {
        $errors = new Collecting;
        $nodes = $this->parser->parse($content, $errors);

        if ($nodes === null) {
            return [];
        }

        return [
            ...$this->finder->findInstanceOf($nodes, Stmt\Class_::class),
            ...$this->finder->findInstanceOf($nodes, Stmt\Trait_::class),
            ...$this->finder->findInstanceOf($nodes, Stmt\Interface_::class),
            ...$this->finder->findInstanceOf($nodes, Stmt\Enum_::class),
        ];
    }

    /**
     * @return list<PhpMethodMetrics>
     */
    private function methodMetricsFor(Stmt\ClassLike $classLike): array
    {
        $methods = [];

        foreach ($classLike->getMethods() as $method) {
            if ($method->stmts === null) {
                continue;
            }

            $methods[] = new PhpMethodMetrics(
                name: $method->name->toString(),
                line: max(0, $method->getStartLine()),
                cc: $this->calculateCc($method),
                lloc: $this->calculateLloc($method),
                params: count($method->params),
                flog: $this->calculateFlog($method),
            );
        }

        return $methods;
    }

    private function classKind(Stmt\ClassLike $classLike): string
    {
        return match (true) {
            $classLike instanceof Stmt\Interface_ => 'interface',
            $classLike instanceof Stmt\Trait_ => 'trait',
            $classLike instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };
    }

    private function lineSpan(Node $node): int
    {
        $startLine = $node->getStartLine();
        $endLine = $node->getEndLine();

        if ($startLine <= 0 || $endLine <= 0) {
            return 0;
        }

        return $endLine - $startLine + 1;
    }

    private function calculateCc(Stmt\ClassMethod $method): int
    {
        $cc = 1;
        $this->traverseForCc($method, $cc);

        return $cc;
    }

    private function traverseForCc(Node $node, int &$cc): void
    {
        foreach (get_object_vars($node) as $member) {
            foreach (is_array($member) ? $member : [$member] as $item) {
                if (! $item instanceof Node) {
                    continue;
                }

                $this->traverseForCc($item, $cc);
            }
        }

        switch (true) {
            case $node instanceof Stmt\If_:
            case $node instanceof Stmt\ElseIf_:
            case $node instanceof Stmt\For_:
            case $node instanceof Stmt\Foreach_:
            case $node instanceof Stmt\While_:
            case $node instanceof Stmt\Do_:
            case $node instanceof BinaryOp\LogicalAnd:
            case $node instanceof BinaryOp\LogicalOr:
            case $node instanceof BinaryOp\LogicalXor:
            case $node instanceof BinaryOp\BooleanAnd:
            case $node instanceof BinaryOp\BooleanOr:
            case $node instanceof Stmt\Catch_:
            case $node instanceof Expr\Ternary:
            case $node instanceof BinaryOp\Coalesce:
                $cc++;
                break;
            case $node instanceof Stmt\Case_:
                if ($node->cond !== null) {
                    $cc++;
                }
                break;
            case $node instanceof BinaryOp\Spaceship:
                $cc += 2;
                break;
        }
    }

    private function calculateLloc(Stmt\ClassMethod $method): int
    {
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        if ($startLine <= 0 || $endLine <= 0) {
            return 0;
        }

        return $endLine - $startLine + 1;
    }

    private function calculateFlog(Stmt\ClassMethod $method): float
    {
        $a = 0;
        $b = 0;
        $c = 0;
        $this->traverseForAbc($method, $a, $b, $c);

        return round(sqrt($a ** 2 + $b ** 2 + $c ** 2), 1);
    }

    private function traverseForAbc(Node $node, int &$a, int &$b, int &$c): void
    {
        foreach (get_object_vars($node) as $member) {
            foreach (is_array($member) ? $member : [$member] as $item) {
                if (! $item instanceof Node) {
                    continue;
                }

                $this->traverseForAbc($item, $a, $b, $c);
            }
        }

        switch (true) {
            // Assignments
            case $node instanceof Expr\Assign:
            case $node instanceof Expr\AssignRef:
            case $node instanceof AssignOp\Plus:
            case $node instanceof AssignOp\Minus:
            case $node instanceof AssignOp\Mul:
            case $node instanceof AssignOp\Div:
            case $node instanceof AssignOp\Mod:
            case $node instanceof AssignOp\Pow:
            case $node instanceof AssignOp\Concat:
            case $node instanceof AssignOp\BitwiseAnd:
            case $node instanceof AssignOp\BitwiseOr:
            case $node instanceof AssignOp\BitwiseXor:
            case $node instanceof AssignOp\ShiftLeft:
            case $node instanceof AssignOp\ShiftRight:
            case $node instanceof AssignOp\Coalesce:
            case $node instanceof Expr\PreInc:
            case $node instanceof Expr\PostInc:
            case $node instanceof Expr\PreDec:
            case $node instanceof Expr\PostDec:
                $a++;
                break;

                // Branches: calls, instantiation, and operators
            case $node instanceof Expr\MethodCall:
            case $node instanceof Expr\NullsafeMethodCall:
            case $node instanceof Expr\StaticCall:
            case $node instanceof Expr\FuncCall:
            case $node instanceof Expr\New_:
            case $node instanceof BinaryOp\Plus:
            case $node instanceof BinaryOp\Minus:
            case $node instanceof BinaryOp\Mul:
            case $node instanceof BinaryOp\Div:
            case $node instanceof BinaryOp\Mod:
            case $node instanceof BinaryOp\Pow:
            case $node instanceof BinaryOp\Concat:
            case $node instanceof BinaryOp\Equal:
            case $node instanceof BinaryOp\NotEqual:
            case $node instanceof BinaryOp\Identical:
            case $node instanceof BinaryOp\NotIdentical:
            case $node instanceof BinaryOp\Smaller:
            case $node instanceof BinaryOp\Greater:
            case $node instanceof BinaryOp\SmallerOrEqual:
            case $node instanceof BinaryOp\GreaterOrEqual:
            case $node instanceof BinaryOp\Spaceship:
            case $node instanceof BinaryOp\ShiftLeft:
            case $node instanceof BinaryOp\ShiftRight:
            case $node instanceof BinaryOp\BitwiseAnd:
            case $node instanceof BinaryOp\BitwiseOr:
            case $node instanceof BinaryOp\BitwiseXor:
                $b++;
                break;

                // Conditions: branching control flow and boolean logic
            case $node instanceof Stmt\If_:
            case $node instanceof Stmt\ElseIf_:
            case $node instanceof Stmt\Else_:
            case $node instanceof Stmt\While_:
            case $node instanceof Stmt\For_:
            case $node instanceof Stmt\Foreach_:
            case $node instanceof Stmt\Do_:
            case $node instanceof Stmt\Switch_:
            case $node instanceof Stmt\Catch_:
            case $node instanceof Expr\Ternary:
            case $node instanceof BinaryOp\Coalesce:
            case $node instanceof BinaryOp\BooleanAnd:
            case $node instanceof BinaryOp\BooleanOr:
            case $node instanceof BinaryOp\LogicalAnd:
            case $node instanceof BinaryOp\LogicalOr:
            case $node instanceof BinaryOp\LogicalXor:
                $c++;
                break;
            case $node instanceof Stmt\Case_:
                if ($node->cond !== null) {
                    $c++;
                }
                break;
        }
    }
}
