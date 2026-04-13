<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class OperatorRule implements Rule
{
    /** @var array<class-string, string> */
    private const OPERATOR_NAMES = [
        BinaryOp\Greater::class => '>',
        BinaryOp\GreaterOrEqual::class => '>=',
        BinaryOp\Smaller::class => '<',
        BinaryOp\SmallerOrEqual::class => '<=',
        BinaryOp\Equal::class => '==',
        BinaryOp\NotEqual::class => '!=',
        BinaryOp\Identical::class => '===',
        BinaryOp\NotIdentical::class => '!==',
        BinaryOp\BooleanAnd::class => '&&',
        BinaryOp\BooleanOr::class => '||',
        BinaryOp\Plus::class => '+',
        BinaryOp\Minus::class => '-',
        BinaryOp\Mul::class => '*',
        BinaryOp\Div::class => '/',
        BinaryOp\Mod::class => '%',
        BinaryOp\Coalesce::class => '??',
        BinaryOp\Spaceship::class => '<=>',
        BinaryOp\Concat::class => '.',
    ];

    /** @var list<class-string> */
    private const COMPARISON_OPS = [
        BinaryOp\Greater::class,
        BinaryOp\GreaterOrEqual::class,
        BinaryOp\Smaller::class,
        BinaryOp\SmallerOrEqual::class,
        BinaryOp\Equal::class,
        BinaryOp\NotEqual::class,
        BinaryOp\Identical::class,
        BinaryOp\NotIdentical::class,
    ];

    private NodeFinder $finder;

    private Standard $printer;

    public function __construct()
    {
        $this->finder = new NodeFinder;
        $this->printer = new Standard;
    }

    public function shortDescription(): string
    {
        return 'Detects comparison, equality, logical, and arithmetic operator changes';
    }

    public function description(): string
    {
        return 'Detects operator changes: comparison operators (> to <), equality strictness (== to ===), logical operators (&& to ||), arithmetic operators, negation additions/removals, and swapped operands.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareOperators($key, $pair['old'], $pair['new'], $changes);
            $this->compareNegations($key, $pair['old'], $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * Compare operators by matching expressions via their operands.
     *
     * Instead of positional comparison (which breaks when code is inserted/removed),
     * we fingerprint each binary expression by its operands and match old/new expressions
     * that share the same operands. Only report when the operator itself changed.
     *
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareOperators(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldOps = $this->extractBinaryOpsWithContext($old);
        $newOps = $this->extractBinaryOpsWithContext($new);

        // Build lookup of old expressions by operand fingerprint
        $oldByFingerprint = [];
        foreach ($oldOps as $op) {
            $oldByFingerprint[$op['fingerprint']][] = $op;
        }

        $newByFingerprint = [];
        foreach ($newOps as $op) {
            $newByFingerprint[$op['fingerprint']][] = $op;
        }

        // Find expressions with same operands but different operator
        foreach ($newByFingerprint as $fingerprint => $newExpressions) {
            if (! isset($oldByFingerprint[$fingerprint])) {
                continue;
            }

            $oldExpressions = $oldByFingerprint[$fingerprint];

            // Compare matching expressions
            $minCount = min(count($oldExpressions), count($newExpressions));
            for ($i = 0; $i < $minCount; $i++) {
                $oldOp = $oldExpressions[$i];
                $newOp = $newExpressions[$i];

                if ($oldOp['operator_class'] === $newOp['operator_class']) {
                    continue;
                }

                $oldName = self::OPERATOR_NAMES[$oldOp['operator_class']] ?? class_basename($oldOp['operator_class']);
                $newName = self::OPERATOR_NAMES[$newOp['operator_class']] ?? class_basename($newOp['operator_class']);
                $severity = $this->operatorChangeSeverity($oldOp['operator_class'], $newOp['operator_class']);

                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::OPERATORS,
                    severity: $severity,
                    description: "Operator changed in {$key}: `{$oldOp['left']} {$oldName} {$oldOp['right']}` -> `{$newOp['left']} {$newName} {$newOp['right']}`",
                    location: $key,
                    line: $newOp['line'],
                );
            }
        }

        // Find expressions where operands were swapped (same operator, reversed operands)
        foreach ($newByFingerprint as $fingerprint => $newExpressions) {
            if (isset($oldByFingerprint[$fingerprint])) {
                continue; // Already handled above
            }

            // Check if a reversed version exists in old
            foreach ($newExpressions as $newOp) {
                $reversedFingerprint = $newOp['reversed_fingerprint'];
                if ($reversedFingerprint !== $fingerprint && isset($oldByFingerprint[$reversedFingerprint])) {
                    $oldOp = $oldByFingerprint[$reversedFingerprint][0];

                    if ($oldOp['operator_class'] === $newOp['operator_class']) {
                        $opName = self::OPERATOR_NAMES[$oldOp['operator_class']] ?? class_basename($oldOp['operator_class']);
                        $changes[] = new ClassifiedChange(
                            category: ChangeCategory::OPERATORS,
                            severity: Severity::VERY_HIGH,
                            description: "Operands swapped in {$key}: `{$oldOp['left']} {$opName} {$oldOp['right']}` -> `{$newOp['left']} {$opName} {$newOp['right']}`",
                            location: $key,
                            line: $newOp['line'],
                        );
                    }
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareNegations(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldNots = $this->finder->findInstanceOf([$old], Expr\BooleanNot::class);
        $newNots = $this->finder->findInstanceOf([$new], Expr\BooleanNot::class);

        // Compare by the expression being negated, not by count
        $oldNegated = array_map(fn (Expr\BooleanNot $n) => $this->printer->prettyPrintExpr($n->expr), $oldNots);
        $newNegated = array_map(fn (Expr\BooleanNot $n) => $this->printer->prettyPrintExpr($n->expr), $newNots);

        $added = array_diff($newNegated, $oldNegated);
        $removed = array_diff($oldNegated, $newNegated);

        foreach ($added as $expr) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::OPERATORS,
                severity: Severity::HIGH,
                description: "Negation added in {$key}: `!({$expr})`",
                location: $key,
            );
        }

        foreach ($removed as $expr) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::OPERATORS,
                severity: Severity::VERY_HIGH,
                description: "Negation removed in {$key}: `!({$expr})`",
                location: $key,
            );
        }
    }

    /**
     * Extract binary operations with their operand context for fingerprinting.
     *
     * @return list<array{fingerprint: string, reversed_fingerprint: string, operator_class: class-string, left: string, right: string, line: int}>
     */
    private function extractBinaryOpsWithContext(Node $node): array
    {
        $ops = [];

        /** @var list<BinaryOp> $binaryOps */
        $binaryOps = $this->finder->findInstanceOf([$node], BinaryOp::class);

        foreach ($binaryOps as $op) {
            $left = $this->printer->prettyPrintExpr($op->left);
            $right = $this->printer->prettyPrintExpr($op->right);

            $ops[] = [
                'fingerprint' => md5($left.'|'.$right),
                'reversed_fingerprint' => md5($right.'|'.$left),
                'operator_class' => $op::class,
                'left' => $left,
                'right' => $right,
                'line' => $op->getStartLine(),
            ];
        }

        return $ops;
    }

    /**
     * @param  class-string  $oldClass
     * @param  class-string  $newClass
     */
    private function operatorChangeSeverity(string $oldClass, string $newClass): Severity
    {
        // Comparison operator changes are always critical (business logic)
        $isOldComparison = in_array($oldClass, self::COMPARISON_OPS, true);
        $isNewComparison = in_array($newClass, self::COMPARISON_OPS, true);

        if ($isOldComparison && $isNewComparison) {
            return Severity::VERY_HIGH;
        }

        // Loose to strict equality changes
        if (
            ($oldClass === BinaryOp\Equal::class && $newClass === BinaryOp\Identical::class)
            || ($oldClass === BinaryOp\NotEqual::class && $newClass === BinaryOp\NotIdentical::class)
        ) {
            return Severity::MEDIUM;
        }

        // Logical operator changes are critical
        if (
            ($oldClass === BinaryOp\BooleanAnd::class && $newClass === BinaryOp\BooleanOr::class)
            || ($oldClass === BinaryOp\BooleanOr::class && $newClass === BinaryOp\BooleanAnd::class)
        ) {
            return Severity::VERY_HIGH;
        }

        // Arithmetic operator changes
        return Severity::VERY_HIGH;
    }
}
