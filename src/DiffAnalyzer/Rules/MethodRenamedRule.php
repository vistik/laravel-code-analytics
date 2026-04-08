<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class MethodRenamedRule implements Rule
{
    public function shortDescription(): string
    {
        return 'Detects method renames within a class';
    }

    public function description(): string
    {
        return 'Detects when a method is renamed within a class, identified by matching parameter signatures.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if (! isset($pair['renamed_from'])) {
                continue;
            }

            $oldName = $pair['renamed_from'];
            $newName = explode('::', $key)[1];
            $className = $pair['class'];

            $changes[] = new ClassifiedChange(
                category: ChangeCategory::METHOD_RENAMED,
                severity: Severity::HIGH,
                description: "Method renamed: {$className}::{$oldName} -> {$newName}",
                location: $key,
                line: $pair['new']->getStartLine(),
            );
        }

        return $changes;
    }
}
