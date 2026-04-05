<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class TypeSystemRule implements Rule
{
    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard;
    }

    public function shortDescription(): string
    {
        return 'Detects parameter, return, and property type changes';
    }

    public function description(): string
    {
        return 'Detects type system changes: parameter types, return types, and property types being added, removed, or modified (including nullability and union types).';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        // Check method return types and parameter types
        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareReturnType($key, $pair['old'], $pair['new'], $changes);
            $this->compareParamTypes($key, $pair['old'], $pair['new'], $changes);
        }

        // Check property types
        foreach ($comparison['properties'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldType = $this->typeToString($pair['old']->type);
            $newType = $this->typeToString($pair['new']->type);

            if ($oldType !== $newType) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::TYPE_SYSTEM,
                    severity: $this->typeSeverity($oldType, $newType),
                    description: "Property type changed on {$key}: {$this->formatTypeChange($oldType, $newType)}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareReturnType(string $key, Node\Stmt\ClassMethod $old, Node\Stmt\ClassMethod $new, array &$changes): void
    {
        $oldType = $this->typeToString($old->returnType);
        $newType = $this->typeToString($new->returnType);

        if ($oldType === $newType) {
            return;
        }

        $changes[] = new ClassifiedChange(
            category: ChangeCategory::TYPE_SYSTEM,
            severity: $this->typeSeverity($oldType, $newType),
            description: "Return type changed on {$key}: {$this->formatTypeChange($oldType, $newType)}",
            location: $key,
            line: $new->getStartLine(),
        );
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareParamTypes(string $key, Node\Stmt\ClassMethod $old, Node\Stmt\ClassMethod $new, array &$changes): void
    {
        $oldParams = $this->indexParams($old->params);
        $newParams = $this->indexParams($new->params);

        foreach ($newParams as $name => $newParam) {
            if (! isset($oldParams[$name])) {
                continue;
            }

            $oldType = $this->typeToString($oldParams[$name]->type);
            $newType = $this->typeToString($newParam->type);

            if ($oldType !== $newType) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::TYPE_SYSTEM,
                    severity: $this->typeSeverity($oldType, $newType),
                    description: "Parameter type changed for \${$name} in {$key}: {$this->formatTypeChange($oldType, $newType)}",
                    location: $key,
                    line: $newParam->getStartLine(),
                );
            }
        }
    }

    /**
     * @param  list<Node\Param>  $params
     * @return array<string, Node\Param>
     */
    private function indexParams(array $params): array
    {
        $indexed = [];

        foreach ($params as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $indexed[$param->var->name] = $param;
            }
        }

        return $indexed;
    }

    private function typeToString(?Node $type): string
    {
        if ($type === null) {
            return 'none';
        }

        return $this->printer->prettyPrint([$type]);
    }

    private function formatTypeChange(string $from, string $to): string
    {
        return "{$from} -> {$to}";
    }

    private function typeSeverity(string $oldType, string $newType): Severity
    {
        // Adding a type hint is usually safe
        if ($oldType === 'none') {
            return Severity::INFO;
        }

        // Removing a type hint is concerning
        if ($newType === 'none') {
            return Severity::MEDIUM;
        }

        // Changing type is potentially breaking
        return Severity::MEDIUM;
    }
}
