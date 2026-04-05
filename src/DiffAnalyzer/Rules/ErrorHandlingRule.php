<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class ErrorHandlingRule implements Rule
{
    private NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder;
    }

    public function shortDescription(): string
    {
        return 'Detects error suppression and exit/die call changes';
    }

    public function description(): string
    {
        return 'Detects error handling changes: error suppression operator (@) additions/removals, set_error_handler changes, and exit/die calls.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareErrorSuppression($key, $pair['old'], $pair['new'], $changes);
            $this->compareExitCalls($key, $pair['old'], $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareErrorSuppression(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldCount = count($this->finder->findInstanceOf([$old], Expr\ErrorSuppress::class));
        $newCount = count($this->finder->findInstanceOf([$new], Expr\ErrorSuppress::class));

        if ($newCount > $oldCount) {
            $diff = $newCount - $oldCount;
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::SIDE_EFFECTS,
                severity: Severity::MEDIUM,
                description: "{$diff} error suppression operator(s) (@) added in {$key} — errors will be silenced",
                location: $key,
            );
        } elseif ($newCount < $oldCount) {
            $diff = $oldCount - $newCount;
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::SIDE_EFFECTS,
                severity: Severity::INFO,
                description: "{$diff} error suppression operator(s) (@) removed in {$key}",
                location: $key,
            );
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareExitCalls(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldExits = count($this->finder->findInstanceOf([$old], Expr\Exit_::class));
        $newExits = count($this->finder->findInstanceOf([$new], Expr\Exit_::class));

        if ($newExits > $oldExits) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::SIDE_EFFECTS,
                severity: Severity::MEDIUM,
                description: "exit/die call added in {$key} — process termination",
                location: $key,
            );
        } elseif ($newExits < $oldExits) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::SIDE_EFFECTS,
                severity: Severity::INFO,
                description: "exit/die call removed in {$key}",
                location: $key,
            );
        }
    }
}
