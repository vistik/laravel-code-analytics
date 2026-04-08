<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node\Stmt\ClassMethod;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class MethodChangedRule implements Rule
{
    public function __construct(private readonly AstComparer $comparer) {}

    public function shortDescription(): string
    {
        return 'Detects methods modified in existing files';
    }

    public function description(): string
    {
        return 'Detects when an existing method body is changed in a modified file.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        if (! in_array($file->status, [FileStatus::MODIFIED, FileStatus::RENAMED], strict: true)) {
            return [];
        }

        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null || isset($pair['renamed_from'])) {
                continue;
            }

            /** @var ClassMethod $old */
            $old = $pair['old'];

            /** @var ClassMethod $new */
            $new = $pair['new'];

            if ($this->comparer->hashNode($old) !== $this->comparer->hashNode($new)) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::METHOD_CHANGED,
                    severity: Severity::MEDIUM,
                    description: "Method changed: {$key}",
                    location: $key,
                    line: $new->getStartLine(),
                );
            }
        }

        return $changes;
    }
}
