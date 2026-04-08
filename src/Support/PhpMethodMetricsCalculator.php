<?php

namespace Vistik\LaravelCodeAnalytics\Support;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Expr;
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
     * @return array<string, list<PhpMethodMetrics>>  File path → list of method metrics
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
     * @return list<PhpMethodMetrics>
     */
    private function analyzeFile(string $content): array
    {
        $errors = new Collecting;
        $nodes = $this->parser->parse($content, $errors);

        if ($nodes === null) {
            return [];
        }

        $classLikes = [
            ...$this->finder->findInstanceOf($nodes, Stmt\Class_::class),
            ...$this->finder->findInstanceOf($nodes, Stmt\Trait_::class),
            ...$this->finder->findInstanceOf($nodes, Stmt\Interface_::class),
            ...$this->finder->findInstanceOf($nodes, Stmt\Enum_::class),
        ];

        $methods = [];

        foreach ($classLikes as $classLike) {
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
                );
            }
        }

        return $methods;
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
            case $node instanceof Expr\BinaryOp\LogicalAnd:
            case $node instanceof Expr\BinaryOp\LogicalOr:
            case $node instanceof Expr\BinaryOp\LogicalXor:
            case $node instanceof Expr\BinaryOp\BooleanAnd:
            case $node instanceof Expr\BinaryOp\BooleanOr:
            case $node instanceof Stmt\Catch_:
            case $node instanceof Expr\Ternary:
            case $node instanceof Expr\BinaryOp\Coalesce:
                $cc++;
                break;
            case $node instanceof Stmt\Case_:
                if ($node->cond !== null) {
                    $cc++;
                }
                break;
            case $node instanceof Expr\BinaryOp\Spaceship:
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
}
