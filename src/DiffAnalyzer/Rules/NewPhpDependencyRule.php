<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\Support\PhpDependencyExtractor;

class NewPhpDependencyRule implements Rule
{
    /** @var array<string, Severity> */
    private const SEVERITY_MAP = [
        PhpDependencyExtractor::EXTENDS_REFERENCE => Severity::MEDIUM,
        PhpDependencyExtractor::CONTAINER_RESOLVED => Severity::MEDIUM,
        PhpDependencyExtractor::METHOD_INJECTION => Severity::LOW,
        PhpDependencyExtractor::NEW_INSTANCE => Severity::LOW,
        PhpDependencyExtractor::STATIC_CALL => Severity::LOW,
        PhpDependencyExtractor::IMPLEMENTS_REFERENCE => Severity::LOW,
        PhpDependencyExtractor::PROPERTY_TYPE => Severity::LOW,
    ];

    /** @var array<string, string> */
    private const LABEL_MAP = [
        PhpDependencyExtractor::EXTENDS_REFERENCE => 'extends',
        PhpDependencyExtractor::CONTAINER_RESOLVED => 'container resolved',
        PhpDependencyExtractor::METHOD_INJECTION => 'method injection',
        PhpDependencyExtractor::NEW_INSTANCE => 'new instance',
        PhpDependencyExtractor::STATIC_CALL => 'static call',
        PhpDependencyExtractor::IMPLEMENTS_REFERENCE => 'implements',
        PhpDependencyExtractor::PROPERTY_TYPE => 'property type',
    ];

    public function shortDescription(): string
    {
        return 'Detects newly introduced PHP class dependencies';
    }

    public function description(): string
    {
        return 'Detects when a PHP file introduces a new runtime dependency on another class via static calls, new instances, container resolution, extends/implements, method injection, or property types.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        if (! $file->isPhp()) {
            return [];
        }

        $oldSource = $comparison['old_source'] ?? null;
        $newSource = $comparison['new_source'] ?? null;

        if ($oldSource === null || $newSource === null) {
            return [];
        }

        $extractor = new PhpDependencyExtractor;
        $oldDeps = $extractor->extract($oldSource);
        $newDeps = $extractor->extract($newSource);

        $added = array_diff_key($newDeps, $oldDeps);

        $changes = [];

        foreach ($added as $className => $type) {
            if (! isset(self::SEVERITY_MAP[$type])) {
                // Skip USE (handled by ImportRule) and RETURN_TYPE (structural only)
                // Also skip CONSTRUCTOR_INJECTION (handled by ConstructorInjectionRule)
                continue;
            }

            $label = self::LABEL_MAP[$type];
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::DEPENDENCY,
                severity: self::SEVERITY_MAP[$type],
                description: "New dependency introduced: {$className} ({$label})",
            );
        }

        return $changes;
    }
}
