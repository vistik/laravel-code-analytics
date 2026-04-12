<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class StrictTypesRule implements Rule
{
    private NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder;
    }

    public function shortDescription(): string
    {
        return 'Detects strict_types declaration additions or removals';
    }

    public function description(): string
    {
        return 'Detects declare(strict_types) additions or removals, which change how PHP handles type coercion in the file.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        if (! $file->isPhp()) {
            return [];
        }

        $oldHasStrict = $this->hasStrictTypes($comparison['old_nodes']);
        $newHasStrict = $this->hasStrictTypes($comparison['new_nodes']);

        if ($oldHasStrict === $newHasStrict) {
            return [];
        }

        if (! $oldHasStrict && $newHasStrict) {
            return [new ClassifiedChange(
                category: ChangeCategory::TYPE_SYSTEM,
                severity: Severity::MEDIUM,
                description: 'declare(strict_types=1) added — type coercion is now strict',
                line: 1,
            )];
        }

        return [new ClassifiedChange(
            category: ChangeCategory::TYPE_SYSTEM,
            severity: Severity::MEDIUM,
            description: 'declare(strict_types=1) removed — type coercion is now loose',
            line: 1,
        )];
    }

    /**
     * @param  ?array<int, Node>  $nodes
     */
    private function hasStrictTypes(?array $nodes): bool
    {
        if ($nodes === null) {
            return false;
        }

        $declares = $this->finder->findInstanceOf($nodes, Stmt\Declare_::class);

        foreach ($declares as $declare) {
            foreach ($declare->declares as $item) {
                if ($item->key->toString() === 'strict_types') {
                    return true;
                }
            }
        }

        return false;
    }
}
