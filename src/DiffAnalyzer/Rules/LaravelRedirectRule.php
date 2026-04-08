<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelRedirectRule implements Rule
{
    use AnalyzesLaravelCode;

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects redirect destination changes';
    }

    public function description(): string
    {
        return 'Detects changes to redirect destinations using to_route() and view() helpers returned from methods.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->analyzeToRouteCalls($pair['old'], $pair['new'], $key, $changes);
            $this->analyzeViewCalls($pair['old'], $pair['new'], $key, $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeToRouteCalls(Node $oldMethod, Node $newMethod, string $key, array &$changes): void
    {
        $oldRoutes = $this->extractFuncCallNames($oldMethod, 'to_route');
        $newRoutes = $this->extractFuncCallNames($newMethod, 'to_route');

        $added = array_diff($newRoutes, $oldRoutes);
        $removed = array_diff($oldRoutes, $newRoutes);

        if (count($oldRoutes) === count($newRoutes) && ! empty($added) && ! empty($removed)) {
            foreach (array_values($removed) as $i => $oldRoute) {
                $newRoute = array_values($added)[$i] ?? null;
                if ($newRoute !== null) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::HIGH,
                        description: "Redirect route changed in {$key}: '{$oldRoute}' -> '{$newRoute}'",
                        location: $key,
                    );
                }
            }
        } else {
            foreach ($added as $route) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Redirect to route added in {$key}: '{$route}'",
                    location: $key,
                );
            }

            foreach ($removed as $route) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::HIGH,
                    description: "Redirect to route removed in {$key}: '{$route}'",
                    location: $key,
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeViewCalls(Node $oldMethod, Node $newMethod, string $key, array &$changes): void
    {
        $oldViews = $this->extractFuncCallNames($oldMethod, 'view');
        $newViews = $this->extractFuncCallNames($newMethod, 'view');

        $added = array_diff($newViews, $oldViews);
        $removed = array_diff($oldViews, $newViews);

        if (count($oldViews) === count($newViews) && ! empty($added) && ! empty($removed)) {
            foreach (array_values($removed) as $i => $oldView) {
                $newView = array_values($added)[$i] ?? null;
                if ($newView !== null) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: "View changed in {$key}: '{$oldView}' -> '{$newView}'",
                        location: $key,
                    );
                }
            }
        } else {
            foreach ($added as $view) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "View added in {$key}: '{$view}'",
                    location: $key,
                );
            }

            foreach ($removed as $view) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "View removed in {$key}: '{$view}'",
                    location: $key,
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function extractFuncCallNames(Node $node, string $funcName): array
    {
        $calls = $this->finder->findInstanceOf([$node], Expr\FuncCall::class);
        $names = [];

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Name || $call->name->getLast() !== $funcName) {
                continue;
            }

            if (isset($call->args[0]) && $call->args[0] instanceof Node\Arg) {
                $value = $call->args[0]->value;
                if ($value instanceof Scalar\String_) {
                    $names[] = $value->value;
                }
            }
        }

        return $names;
    }
}
