<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class ValueRule implements Rule
{
    private NodeFinder $finder;

    private Standard $printer;

    public function __construct()
    {
        $this->finder = new NodeFinder;
        $this->printer = new Standard;
    }

    public function shortDescription(): string
    {
        return 'Detects constant, default value, and boolean literal changes';
    }

    public function description(): string
    {
        return 'Detects value and constant changes: class constant values, default parameter values, property defaults, and boolean literal flips (true to false).';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        // Check class constant values
        foreach ($comparison['class_constants'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareConstValues($key, $pair['old'], $pair['new'], $changes);
        }

        // Check default parameter values in methods
        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareDefaultValues($key, $pair['old'], $pair['new'], $changes);
            $this->compareLiterals($key, $pair['old'], $pair['new'], $changes);
        }

        // Check property default values
        foreach ($comparison['properties'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->comparePropertyDefaults($key, $pair['old'], $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareConstValues(string $key, Stmt\ClassConst $old, Stmt\ClassConst $new, array &$changes): void
    {
        foreach ($old->consts as $i => $oldConst) {
            if (! isset($new->consts[$i])) {
                continue;
            }

            $oldVal = $this->printer->prettyPrintExpr($oldConst->value);
            $newVal = $this->printer->prettyPrintExpr($new->consts[$i]->value);

            if ($oldVal !== $newVal) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::VALUES,
                    severity: Severity::HIGH,
                    description: "Constant value changed for {$key}: `{$this->summarizeExpr($oldConst->value)}` -> `{$this->summarizeExpr($new->consts[$i]->value)}`",
                    location: $key,
                    line: $new->consts[$i]->getStartLine(),
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareDefaultValues(string $key, Stmt\ClassMethod $old, Stmt\ClassMethod $new, array &$changes): void
    {
        $oldParams = $this->indexParams($old->params);
        $newParams = $this->indexParams($new->params);

        foreach ($newParams as $name => $newParam) {
            if (! isset($oldParams[$name])) {
                continue;
            }

            $oldDefault = $oldParams[$name]->default;
            $newDefault = $newParam->default;

            $oldStr = $oldDefault ? $this->printer->prettyPrintExpr($oldDefault) : 'none';
            $newStr = $newDefault ? $this->printer->prettyPrintExpr($newDefault) : 'none';

            if ($oldStr !== $newStr) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::VALUES,
                    severity: Severity::MEDIUM,
                    description: "Default value changed for \${$name} in {$key}: `{$this->summarizeExpr($oldDefault, 'none')}` -> `{$this->summarizeExpr($newDefault, 'none')}`",
                    location: $key,
                    line: $newParam->getStartLine(),
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareLiterals(string $key, Stmt\ClassMethod $old, Stmt\ClassMethod $new, array &$changes): void
    {
        $oldBooleans = $this->extractBooleanLiterals($old);
        $newBooleans = $this->extractBooleanLiterals($new);

        // Detect boolean flips (true -> false or vice versa)
        $minCount = min(count($oldBooleans), count($newBooleans));
        for ($i = 0; $i < $minCount; $i++) {
            if ($oldBooleans[$i] !== $newBooleans[$i]) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::VALUES,
                    severity: Severity::HIGH,
                    description: "Boolean literal flipped in {$key}: `{$oldBooleans[$i]}` -> `{$newBooleans[$i]}`",
                    location: $key,
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function comparePropertyDefaults(string $key, Stmt\Property $old, Stmt\Property $new, array &$changes): void
    {
        foreach ($old->props as $i => $oldProp) {
            if (! isset($new->props[$i])) {
                continue;
            }

            $oldDefault = $oldProp->default;
            $newDefault = $new->props[$i]->default;

            $oldStr = $oldDefault ? $this->printer->prettyPrintExpr($oldDefault) : 'none';
            $newStr = $newDefault ? $this->printer->prettyPrintExpr($newDefault) : 'none';

            if ($oldStr !== $newStr) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::VALUES,
                    severity: Severity::MEDIUM,
                    description: "Property default changed for {$key}: `{$this->summarizeExpr($oldDefault, 'none')}` -> `{$this->summarizeExpr($newDefault, 'none')}`",
                    location: $key,
                    line: $new->props[$i]->getStartLine(),
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function extractBooleanLiterals(Node $node): array
    {
        $booleans = [];

        $constFetches = $this->finder->findInstanceOf([$node], Expr\ConstFetch::class);

        foreach ($constFetches as $fetch) {
            $name = strtolower($fetch->name->toString());
            if ($name === 'true' || $name === 'false') {
                $booleans[] = $name;
            }
        }

        return $booleans;
    }

    /**
     * @param  list<Node\Param>  $params
     * @return array<string, Node\Param>
     */
    private function indexParams(array $params): array
    {
        $indexed = [];

        foreach ($params as $param) {
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $indexed[$param->var->name] = $param;
            }
        }

        return $indexed;
    }

    private function summarizeExpr(?Expr $expr, string $fallback = 'void'): string
    {
        if ($expr === null) {
            return $fallback;
        }

        $full = preg_replace('/\s+/', ' ', $this->printer->prettyPrintExpr($expr));

        if (mb_strlen($full) <= 60) {
            return $full;
        }

        if ($expr instanceof Expr\MethodCall) {
            $last = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '…';
            $root = $expr->var;
            while ($root instanceof Expr\MethodCall) {
                $root = $root->var;
            }
            $rootStr = $this->summarizeExpr($root);

            return "{$rootStr}->…->{$last}()";
        }

        if ($expr instanceof Expr\StaticCall) {
            $class = $expr->class instanceof Node\Name ? $expr->class->toString() : '…';
            $method = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '…';

            return "{$class}::{$method}(…)";
        }

        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name) {
            return $expr->name->toString().'(…)';
        }

        if ($expr instanceof Expr\Array_) {
            return '[…'.count($expr->items).' items]';
        }

        return Str::limit($full, 60);
    }
}
