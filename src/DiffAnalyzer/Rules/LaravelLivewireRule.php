<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Modifiers;
use PhpParser\Node\Stmt;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelLivewireRule implements Rule
{
    use AnalyzesLaravelCode;

    /** @var list<string> Livewire lifecycle methods that are not "actions" */
    private const LIFECYCLE_METHODS = [
        'mount', 'hydrate', 'dehydrate', 'boot', 'booted',
        'render', 'rendered', 'updating', 'updated',
        'exception', 'placeholder',
    ];

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects Livewire component property, action, and lifecycle changes';
    }

    public function description(): string
    {
        return 'Detects Livewire component changes: public property additions/removals (wire:model bindings), action method changes, mount() parameter changes, computed property modifications, render() view changes, and component validation rules.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $path = $file->effectivePath();

        if (! $this->isLivewireComponent($path, $comparison)) {
            return [];
        }

        $changes = [];

        $this->analyzePublicProperties($comparison, $changes);
        $this->analyzeActions($comparison, $changes);
        $this->analyzeMount($comparison, $changes);
        $this->analyzeRender($comparison, $changes);
        $this->analyzeComputedProperties($comparison, $changes);
        $this->analyzeValidation($comparison, $changes);

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzePublicProperties(array $comparison, array &$changes): void
    {
        foreach ($comparison['properties'] as $key => $pair) {
            if ($pair['new'] !== null && ! ($pair['new']->flags & Modifiers::PUBLIC)) {
                continue;
            }

            if ($pair['old'] !== null && ! ($pair['old']->flags & Modifiers::PUBLIC)) {
                continue;
            }

            $propName = explode('::$', $key)[1] ?? '';

            if ($pair['old'] === null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Livewire public property added: \${$propName} — available for wire:model binding",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            } elseif ($pair['old'] !== null && $pair['new'] === null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::VERY_HIGH,
                    description: "Livewire public property removed: \${$propName} — may break wire:model bindings",
                    location: $key,
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeActions(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            $methodName = $this->getMethodName($key);

            // Skip lifecycle methods and non-public methods
            if (in_array($methodName, self::LIFECYCLE_METHODS, true)) {
                continue;
            }

            if ($pair['new'] !== null && ! ($pair['new']->flags & Modifiers::PUBLIC)) {
                continue;
            }

            if ($pair['old'] === null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::INFO,
                    description: "Livewire action added: {$methodName}() — callable from frontend",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            } elseif ($pair['old'] !== null && $pair['new'] === null) {
                if ($pair['old']->flags & Modifiers::PUBLIC) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::VERY_HIGH,
                        description: "Livewire action removed: {$methodName}() — may break wire:click bindings",
                        location: $key,
                    );
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeMount(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            if ($this->getMethodName($key) !== 'mount') {
                continue;
            }

            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            // Check parameter changes
            $oldParams = array_map(fn ($p) => $this->printer->prettyPrint([$p]), $pair['old']->params);
            $newParams = array_map(fn ($p) => $this->printer->prettyPrint([$p]), $pair['new']->params);

            if ($oldParams !== $newParams) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Livewire mount() parameters changed: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }

            // Check body changes
            $oldBody = $this->printer->prettyPrint($pair['old']->stmts ?? []);
            $newBody = $this->printer->prettyPrint($pair['new']->stmts ?? []);

            if ($oldBody !== $newBody) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Livewire mount() logic changed: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeRender(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            if ($this->getMethodName($key) !== 'render') {
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
                    description: "Livewire render() changed: {$key} — view or data may differ",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeComputedProperties(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            // Check for #[Computed] attribute
            $hasComputed = false;
            foreach ($pair['new']->attrGroups as $attrGroup) {
                foreach ($attrGroup->attrs as $attr) {
                    if ($attr->name->getLast() === 'Computed') {
                        $hasComputed = true;

                        break 2;
                    }
                }
            }

            if (! $hasComputed) {
                continue;
            }

            $oldBody = $this->printer->prettyPrint($pair['old']->stmts ?? []);
            $newBody = $this->printer->prettyPrint($pair['new']->stmts ?? []);

            if ($oldBody !== $newBody) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Livewire computed property changed: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeValidation(array $comparison, array &$changes): void
    {
        // Check $rules property
        foreach ($comparison['properties'] as $key => $pair) {
            if (! str_ends_with($key, '::$rules')) {
                continue;
            }

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $oldVal = $this->printer->prettyPrint([$pair['old']]);
                $newVal = $this->printer->prettyPrint([$pair['new']]);

                if ($oldVal !== $newVal) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: 'Livewire validation rules changed on '.$this->getClassName($key),
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            }
        }

        // Check rules() method
        foreach ($comparison['methods'] as $key => $pair) {
            if ($this->getMethodName($key) !== 'rules') {
                continue;
            }

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Livewire validation rules() changed: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }
    }

    private function isLivewireComponent(string $path, array $comparison): bool
    {
        if ($this->pathContains($path, 'Livewire/') || $this->pathContains($path, 'Http/Livewire/')) {
            return true;
        }

        // Check if any class extends Component
        foreach ($comparison['classes'] ?? [] as $pair) {
            if ($pair['new'] !== null && $pair['new'] instanceof Stmt\Class_) {
                $extends = $pair['new']->extends?->getLast();
                if ($extends === 'Component') {
                    return true;
                }
            }
        }

        return false;
    }
}
