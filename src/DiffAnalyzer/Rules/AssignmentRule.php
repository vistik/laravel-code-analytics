<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class AssignmentRule implements Rule
{
    private NodeFinder $finder;

    private Standard $printer;

    public function __construct()
    {
        $this->finder = new NodeFinder;
        $this->printer = new Standard;
    }

    public function shortDescription(): string
    {
        return 'Detects assignment and data flow changes';
    }

    public function description(): string
    {
        return 'Detects assignment and data flow changes: new or removed variable assignments and compound assignment operator changes (e.g. += to -=).';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareAssignments($key, $pair['old'], $pair['new'], $changes);
            $this->compareCompoundAssignments($key, $pair['old'], $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareAssignments(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldAssigns = $this->extractAssignTargets($old);
        $newAssigns = $this->extractAssignTargets($new);

        $added = array_diff($newAssigns, $oldAssigns);
        $removed = array_diff($oldAssigns, $newAssigns);

        foreach ($added as $target) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::ASSIGNMENT,
                severity: Severity::INFO,
                description: "New assignment target in {$key}: {$target}",
                location: $key,
            );
        }

        foreach ($removed as $target) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::ASSIGNMENT,
                severity: Severity::INFO,
                description: "Assignment target removed in {$key}: {$target}",
                location: $key,
            );
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareCompoundAssignments(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldOps = $this->extractCompoundAssignOps($old);
        $newOps = $this->extractCompoundAssignOps($new);

        $minCount = min(count($oldOps), count($newOps));

        for ($i = 0; $i < $minCount; $i++) {
            if ($oldOps[$i]['type'] !== $newOps[$i]['type']) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::ASSIGNMENT,
                    severity: Severity::VERY_HIGH,
                    description: "Compound assignment operator changed in {$key}: {$oldOps[$i]['type']} -> {$newOps[$i]['type']}",
                    location: $key,
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function extractAssignTargets(Node $node): array
    {
        $targets = [];

        $assigns = $this->finder->findInstanceOf([$node], Expr\Assign::class);

        foreach ($assigns as $assign) {
            $targets[] = $this->printer->prettyPrintExpr($assign->var);
        }

        return array_unique($targets);
    }

    /**
     * @return list<array{type: string, target: string}>
     */
    private function extractCompoundAssignOps(Node $node): array
    {
        $ops = [];

        $assignOps = $this->finder->findInstanceOf([$node], Expr\AssignOp::class);

        foreach ($assignOps as $op) {
            $ops[] = [
                'type' => class_basename($op::class),
                'target' => $this->printer->prettyPrintExpr($op->var),
            ];
        }

        return $ops;
    }
}
