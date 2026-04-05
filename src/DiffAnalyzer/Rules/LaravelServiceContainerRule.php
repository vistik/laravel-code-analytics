<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelServiceContainerRule implements Rule
{
    use AnalyzesLaravelCode;

    /** @var list<string> */
    private const BINDING_METHODS = ['bind', 'singleton', 'scoped', 'instance', 'extend'];

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects service container binding and provider changes';
    }

    public function description(): string
    {
        return 'Detects service container changes: new or modified bindings (bind, singleton, scoped), service provider boot() changes, and provider registration changes.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];
        $path = $file->effectivePath();

        if ($this->pathContains($path, 'Providers/')) {
            $this->analyzeProviderBindings($comparison, $changes);
            $this->analyzeProviderBoot($comparison, $changes);
        }

        if ($path === 'bootstrap/providers.php') {
            $this->analyzeProviderRegistration($comparison, $changes);
        }

        if ($path === 'bootstrap/app.php') {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::MEDIUM,
                description: 'Application bootstrap configuration changed',
            );
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeProviderBindings(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            if ($this->getMethodName($key) !== 'register') {
                continue;
            }

            if ($pair['new'] === null) {
                continue;
            }

            $newBindings = $this->extractBindings($pair['new']);

            if ($pair['old'] === null) {
                foreach ($newBindings as $binding) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: "Service binding added: {$binding['method']}({$binding['abstract']})",
                        location: $key,
                    );
                }

                continue;
            }

            $oldBindings = $this->extractBindings($pair['old']);

            $oldAbstracts = array_column($oldBindings, 'abstract');
            $newAbstracts = array_column($newBindings, 'abstract');

            $added = array_diff($newAbstracts, $oldAbstracts);
            $removed = array_diff($oldAbstracts, $newAbstracts);

            foreach ($added as $abstract) {
                $binding = $this->findBindingByAbstract($newBindings, $abstract);
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Service binding added: {$binding['method']}({$abstract})",
                    location: $key,
                );
            }

            foreach ($removed as $abstract) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::VERY_HIGH,
                    description: "Service binding removed: {$abstract}",
                    location: $key,
                );
            }

            // Check for changed binding type (e.g. bind -> singleton)
            foreach (array_intersect($oldAbstracts, $newAbstracts) as $abstract) {
                $oldBinding = $this->findBindingByAbstract($oldBindings, $abstract);
                $newBinding = $this->findBindingByAbstract($newBindings, $abstract);

                if ($oldBinding && $newBinding && $oldBinding['method'] !== $newBinding['method']) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: "Binding type changed for {$abstract}: {$oldBinding['method']} -> {$newBinding['method']}",
                        location: $key,
                    );
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeProviderBoot(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            if ($this->getMethodName($key) !== 'boot') {
                continue;
            }

            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldBody = $this->printer->prettyPrint($pair['old']->stmts ?? []);
            $newBody = $this->printer->prettyPrint($pair['new']->stmts ?? []);

            if ($oldBody !== $newBody) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Service provider boot() changed: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeProviderRegistration(array $comparison, array &$changes): void
    {
        if ($comparison['ast_identical']) {
            return;
        }

        $changes[] = new ClassifiedChange(
            category: ChangeCategory::LARAVEL,
            severity: Severity::MEDIUM,
            description: 'Service provider registration changed (bootstrap/providers.php)',
        );
    }

    /**
     * @return list<array{method: string, abstract: string}>
     */
    private function extractBindings(Node $node): array
    {
        $bindings = [];

        $methodCalls = $this->finder->findInstanceOf([$node], Expr\MethodCall::class);

        foreach ($methodCalls as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

            if (! in_array($name, self::BINDING_METHODS, true)) {
                continue;
            }

            $abstract = $this->getFirstStringArg($call);

            // Also handle ::class syntax
            if ($abstract === null && isset($call->args[0]) && $call->args[0] instanceof Node\Arg) {
                $value = $call->args[0]->value;
                if ($value instanceof Expr\ClassConstFetch && $value->name instanceof Node\Identifier && $value->name->toString() === 'class') {
                    $abstract = $value->class instanceof Node\Name ? $value->class->getLast() : null;
                }
            }

            if ($abstract !== null) {
                $bindings[] = ['method' => $name, 'abstract' => $abstract];
            }
        }

        return $bindings;
    }

    /**
     * @param  list<array{method: string, abstract: string}>  $bindings
     * @return ?array{method: string, abstract: string}
     */
    private function findBindingByAbstract(array $bindings, string $abstract): ?array
    {
        foreach ($bindings as $binding) {
            if ($binding['abstract'] === $abstract) {
                return $binding;
            }
        }

        return null;
    }
}
