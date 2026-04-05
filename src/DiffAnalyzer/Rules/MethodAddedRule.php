<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class MethodAddedRule implements Rule
{
    public function shortDescription(): string
    {
        return 'Detects methods added to classes';
    }

    public function description(): string
    {
        return 'Detects when a new method is introduced to a class.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::METHOD_ADDED,
                    severity: Severity::INFO,
                    description: "Method added: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }

        return $changes;
    }
}
