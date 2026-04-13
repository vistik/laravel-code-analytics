<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class MethodRemovedRule implements Rule
{
    public function shortDescription(): string
    {
        return 'Detects methods removed from classes';
    }

    public function description(): string
    {
        return 'Detects when an existing method is removed from a class.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] !== null && $pair['new'] === null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::METHOD_REMOVED,
                    severity: Severity::HIGH,
                    description: "Method removed: {$key}",
                    location: $key,
                );
            }
        }

        return $changes;
    }
}
