<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelAuthRule implements Rule
{
    use AnalyzesLaravelCode;

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects authorization and security changes';
    }

    public function description(): string
    {
        return 'Detects authorization and security changes: FormRequest authorize() modifications, Gate definitions, policy method changes, auth config changes, and route model binding.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];
        $path = $file->effectivePath();

        $this->analyzeFormRequestAuthorize($path, $comparison, $changes);
        $this->analyzeGateDefinitions($comparison, $changes);
        $this->analyzePolicies($path, $comparison, $changes);
        $this->analyzeRouteModelBinding($comparison, $changes);

        if ($path === 'config/auth.php') {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::VERY_HIGH,
                description: 'Authentication configuration changed (guards, providers, or passwords)',
            );
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeFormRequestAuthorize(string $path, array $comparison, array &$changes): void
    {
        if (! $this->pathContains($path, 'Requests/')) {
            return;
        }

        foreach ($comparison['methods'] as $key => $pair) {
            if ($this->getMethodName($key) !== 'authorize') {
                continue;
            }

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $oldBody = $this->printer->prettyPrint($pair['old']->stmts ?? []);
                $newBody = $this->printer->prettyPrint($pair['new']->stmts ?? []);

                if ($oldBody !== $newBody) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::VERY_HIGH,
                        description: "FormRequest authorize() logic changed: {$key} — security-critical",
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            } elseif ($pair['old'] === null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "FormRequest authorize() added: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            } elseif ($pair['old'] !== null && $pair['new'] === null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::VERY_HIGH,
                    description: "FormRequest authorize() removed: {$key} — authorization check removed",
                    location: $key,
                );
            }
        }

        // Also check validation rules in form requests
        foreach ($comparison['methods'] as $key => $pair) {
            if ($this->getMethodName($key) !== 'rules') {
                continue;
            }

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Validation rules changed: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            } elseif ($pair['old'] === null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::INFO,
                    description: "Validation rules added: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeGateDefinitions(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $gateMethods = ['define', 'before', 'after', 'policy'];
            $oldGates = $this->extractStaticCallMethods($pair['old'], 'Gate');
            $newGates = $this->extractStaticCallMethods($pair['new'], 'Gate');

            $oldGates = array_intersect($oldGates, $gateMethods);
            $newGates = array_intersect($newGates, $gateMethods);

            if ($oldGates !== $newGates) {
                $added = array_diff($newGates, $oldGates);
                $removed = array_diff($oldGates, $newGates);

                foreach ($added as $gate) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::VERY_HIGH,
                        description: "Gate::{$gate}() added in {$key}",
                        location: $key,
                    );
                }

                foreach ($removed as $gate) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::VERY_HIGH,
                        description: "Gate::{$gate}() removed in {$key}",
                        location: $key,
                    );
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzePolicies(string $path, array $comparison, array &$changes): void
    {
        if (! $this->pathContains($path, 'Policies/')) {
            return;
        }

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] !== null && $pair['new'] !== null) {
                $oldBody = $this->printer->prettyPrint($pair['old']->stmts ?? []);
                $newBody = $this->printer->prettyPrint($pair['new']->stmts ?? []);

                if ($oldBody !== $newBody) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::VERY_HIGH,
                        description: "Authorization policy changed: {$key}",
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeRouteModelBinding(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            $methodName = $this->getMethodName($key);

            if ($methodName !== 'resolveRouteBinding' && $methodName !== 'getRouteKeyName') {
                continue;
            }

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Route model binding changed: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }
    }
}
