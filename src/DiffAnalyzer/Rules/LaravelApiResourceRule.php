<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelApiResourceRule implements Rule
{
    use AnalyzesLaravelCode;

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects API Resource response shape changes';
    }

    public function description(): string
    {
        return 'Detects API Resource changes: toArray() modifications that change the API response shape, which can break API consumers.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $path = $file->effectivePath();

        if (! $this->isApiResource($path, $comparison)) {
            return [];
        }

        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            $methodName = $this->getMethodName($key);

            if ($methodName === 'toArray') {
                if ($pair['old'] === null || $pair['new'] === null) {
                    continue;
                }

                $oldBody = $this->printer->prettyPrint($pair['old']->stmts ?? []);
                $newBody = $this->printer->prettyPrint($pair['new']->stmts ?? []);

                if ($oldBody !== $newBody) {
                    array_push($changes, ...$this->toArrayChanges($key, $pair['old'], $pair['new']));
                }
            }

            if ($methodName === 'with') {
                if ($pair['old'] !== null && $pair['new'] !== null) {
                    $oldBody = $this->printer->prettyPrint($pair['old']->stmts ?? []);
                    $newBody = $this->printer->prettyPrint($pair['new']->stmts ?? []);

                    if ($oldBody !== $newBody) {
                        $changes[] = new ClassifiedChange(
                            category: ChangeCategory::LARAVEL,
                            severity: Severity::MEDIUM,
                            description: "API Resource with() metadata changed: {$key}",
                            location: $key,
                            line: $pair['new']->getStartLine(),
                        );
                    }
                }
            }
        }

        return $changes;
    }

    /** @return list<ClassifiedChange> */
    private function toArrayChanges(string $key, ClassMethod $old, ClassMethod $new): array
    {
        $oldKeys = $this->extractReturnArrayKeys($old);
        $newKeys = $this->extractReturnArrayKeys($new);

        if ($oldKeys === null || $newKeys === null) {
            return [new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::VERY_HIGH,
                description: "API Resource toArray() changed: {$key} — API response shape may have changed, can break consumers",
                location: $key,
                line: $new->getStartLine(),
            )];
        }

        $changes = [];

        $removed = array_values(array_diff($oldKeys, $newKeys));
        $added = array_values(array_diff($newKeys, $oldKeys));

        if ($removed !== []) {
            $list = implode(', ', array_map(fn ($k) => "'{$k}'", $removed));
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::VERY_HIGH,
                description: "API Resource toArray() removed keys: {$key} — removed: {$list}",
                location: $key,
                line: $new->getStartLine(),
            );
        }

        if ($added !== []) {
            $list = implode(', ', array_map(fn ($k) => "'{$k}'", $added));
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::MEDIUM,
                description: "API Resource toArray() added keys: {$key} — added: {$list}",
                location: $key,
                line: $new->getStartLine(),
            );
        }

        if ($changes === []) {
            // Keys are identical but the body changed (e.g. values/logic changed)
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::VERY_HIGH,
                description: "API Resource toArray() changed: {$key} — API response shape may have changed, can break consumers",
                location: $key,
                line: $new->getStartLine(),
            );
        }

        return $changes;
    }

    private function isApiResource(string $path, array $comparison): bool
    {
        // Check path
        if ($this->pathContains($path, 'Resources/') && $this->pathContains($path, 'Http/')) {
            return true;
        }

        // Check if class extends JsonResource or ResourceCollection
        foreach ($comparison['classes'] ?? [] as $pair) {
            if ($pair['new'] !== null && $pair['new'] instanceof Stmt\Class_) {
                $extends = $pair['new']->extends?->getLast();
                if (in_array($extends, ['JsonResource', 'ResourceCollection', 'Resource'], true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
