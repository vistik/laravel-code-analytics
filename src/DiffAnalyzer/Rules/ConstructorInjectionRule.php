<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\PrettyPrinter\Standard;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class ConstructorInjectionRule implements Rule
{
    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard;
    }

    public function shortDescription(): string
    {
        return 'Detects constructor dependency injection changes';
    }

    public function description(): string
    {
        return 'Detects constructor dependency injection changes: new type-hinted constructor parameters indicate new service dependencies, removed parameters indicate removed dependencies.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if (! str_ends_with($key, '::__construct')) {
                continue;
            }

            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $className = explode('::', $key)[0];

            $oldDeps = $this->extractTypedParams($pair['old']);
            $newDeps = $this->extractTypedParams($pair['new']);

            $added = array_diff_key($newDeps, $oldDeps);
            $removed = array_diff_key($oldDeps, $newDeps);

            foreach ($added as $paramName => $type) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::METHOD_SIGNATURE,
                    severity: Severity::MEDIUM,
                    description: "New dependency injected into {$className}: {$type} \${$paramName}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }

            foreach ($removed as $paramName => $type) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::METHOD_SIGNATURE,
                    severity: Severity::MEDIUM,
                    description: "Dependency removed from {$className}: {$type} \${$paramName}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }

            // Check for changed types on existing params
            $common = array_intersect_key($oldDeps, $newDeps);
            foreach ($common as $paramName => $oldType) {
                $newType = $newDeps[$paramName];
                if ($oldType !== $newType) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::METHOD_SIGNATURE,
                        severity: Severity::MEDIUM,
                        description: "Dependency type changed in {$className}: \${$paramName} ({$oldType} -> {$newType})",
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            }
        }

        return $changes;
    }

    /**
     * Extract type-hinted parameters (these represent injected dependencies).
     *
     * @return array<string, string>
     */
    private function extractTypedParams(Node\Stmt\ClassMethod $method): array
    {
        $params = [];

        foreach ($method->params as $param) {
            if ($param->type === null) {
                continue;
            }

            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $params[$param->var->name] = $this->printer->prettyPrint([$param->type]);
            }
        }

        return $params;
    }
}
