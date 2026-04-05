<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Modifiers;
use PhpParser\Node\Expr;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class MethodSignatureRule implements Rule
{
    public function shortDescription(): string
    {
        return 'Detects method signature, visibility, and parameter changes';
    }

    public function description(): string
    {
        return 'Detects method signature changes: added/removed methods, visibility changes, static/final/abstract modifiers, and parameter additions, removals, reordering, variadic, and pass-by-reference changes.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareSignature($key, $pair['old'], $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareSignature(string $key, ClassMethod $old, ClassMethod $new, array &$changes): void
    {
        $this->compareVisibility($key, $old, $new, $changes);
        $this->compareModifiers($key, $old, $new, $changes);
        $this->compareParameters($key, $old, $new, $changes);
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareVisibility(string $key, ClassMethod $old, ClassMethod $new, array &$changes): void
    {
        $oldVis = $this->getVisibility($old);
        $newVis = $this->getVisibility($new);

        if ($oldVis !== $newVis) {
            $severity = $this->visibilityChangeSeverity($oldVis, $newVis);
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::METHOD_SIGNATURE,
                severity: $severity,
                description: "Visibility changed on {$key}: {$oldVis} -> {$newVis}",
                location: $key,
                line: $new->getStartLine(),
            );
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareModifiers(string $key, ClassMethod $old, ClassMethod $new, array &$changes): void
    {
        $wasStatic = (bool) ($old->flags & Modifiers::STATIC);
        $isStatic = (bool) ($new->flags & Modifiers::STATIC);

        if ($wasStatic !== $isStatic) {
            $action = $isStatic ? 'made static' : 'made non-static';
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::METHOD_SIGNATURE,
                severity: Severity::VERY_HIGH,
                description: "Method {$action}: {$key}",
                location: $key,
                line: $new->getStartLine(),
            );
        }

        $wasFinal = (bool) ($old->flags & Modifiers::FINAL);
        $isFinal = (bool) ($new->flags & Modifiers::FINAL);

        if ($wasFinal !== $isFinal) {
            $action = $isFinal ? 'made final' : 'final removed';
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::METHOD_SIGNATURE,
                severity: Severity::MEDIUM,
                description: "Method {$action}: {$key}",
                location: $key,
                line: $new->getStartLine(),
            );
        }

        $wasAbstract = (bool) ($old->flags & Modifiers::ABSTRACT);
        $isAbstract = (bool) ($new->flags & Modifiers::ABSTRACT);

        if ($wasAbstract !== $isAbstract) {
            $action = $isAbstract ? 'made abstract' : 'abstract removed';
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::METHOD_SIGNATURE,
                severity: Severity::VERY_HIGH,
                description: "Method {$action}: {$key}",
                location: $key,
                line: $new->getStartLine(),
            );
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareParameters(string $key, ClassMethod $old, ClassMethod $new, array &$changes): void
    {
        $oldNames = $this->paramNames($old);
        $newNames = $this->paramNames($new);

        $added = array_diff($newNames, $oldNames);
        $removed = array_diff($oldNames, $newNames);

        foreach ($added as $name) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::METHOD_SIGNATURE,
                severity: Severity::MEDIUM,
                description: "Parameter added to {$key}: \${$name}",
                location: $key,
                line: $new->getStartLine(),
            );
        }

        foreach ($removed as $name) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::METHOD_SIGNATURE,
                severity: Severity::VERY_HIGH,
                description: "Parameter removed from {$key}: \${$name}",
                location: $key,
                line: $new->getStartLine(),
            );
        }

        // Check for reordering
        $commonOld = array_values(array_intersect($oldNames, $newNames));
        $commonNew = array_values(array_intersect($newNames, $oldNames));

        if ($commonOld !== $commonNew && count($commonOld) > 1) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::METHOD_SIGNATURE,
                severity: Severity::VERY_HIGH,
                description: "Parameters reordered in {$key}",
                location: $key,
                line: $new->getStartLine(),
            );
        }

        // Check variadic and by-reference changes
        $oldParams = $this->indexParams($old);
        $newParams = $this->indexParams($new);

        foreach ($newParams as $name => $newParam) {
            if (! isset($oldParams[$name])) {
                continue;
            }

            $oldParam = $oldParams[$name];

            if ($oldParam->variadic !== $newParam->variadic) {
                $action = $newParam->variadic ? 'made variadic' : 'variadic removed';
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::METHOD_SIGNATURE,
                    severity: Severity::VERY_HIGH,
                    description: "Parameter \${$name} {$action} in {$key}",
                    location: $key,
                    line: $newParam->getStartLine(),
                );
            }

            if ($oldParam->byRef !== $newParam->byRef) {
                $action = $newParam->byRef ? 'made pass-by-reference' : 'pass-by-reference removed';
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::METHOD_SIGNATURE,
                    severity: Severity::VERY_HIGH,
                    description: "Parameter \${$name} {$action} in {$key}",
                    location: $key,
                    line: $newParam->getStartLine(),
                );
            }
        }
    }

    private function getVisibility(ClassMethod $method): string
    {
        if ($method->flags & Modifiers::PUBLIC) {
            return 'public';
        }

        if ($method->flags & Modifiers::PROTECTED) {
            return 'protected';
        }

        if ($method->flags & Modifiers::PRIVATE) {
            return 'private';
        }

        return 'public'; // Default in PHP
    }

    private function visibilityChangeSeverity(string $old, string $new): Severity
    {
        $order = ['public' => 3, 'protected' => 2, 'private' => 1];

        // Narrowing visibility is a breaking change
        if ($order[$new] < $order[$old]) {
            return Severity::VERY_HIGH;
        }

        // Widening is usually safe but notable
        return Severity::MEDIUM;
    }

    /**
     * @return list<string>
     */
    private function paramNames(ClassMethod $method): array
    {
        $names = [];

        foreach ($method->params as $param) {
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $names[] = $param->var->name;
            }
        }

        return $names;
    }

    /**
     * @return array<string, Param>
     */
    private function indexParams(ClassMethod $method): array
    {
        $indexed = [];

        foreach ($method->params as $param) {
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $indexed[$param->var->name] = $param;
            }
        }

        return $indexed;
    }
}
