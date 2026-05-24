<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class ControlFlowRule implements Rule
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
        return 'Detects control flow changes like conditions, branches, loops, and returns';
    }

    public function description(): string
    {
        return 'Detects control flow changes: modified if/elseif/else conditions, added/removed branches, loop changes, try-catch modifications, return statement additions/removals, return value changes, and switch/match arm changes.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareControlFlow($key, $pair['old'], $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareControlFlow(string $key, Stmt\ClassMethod $old, Stmt\ClassMethod $new, array &$changes): void
    {
        $this->compareIfStatements($key, $old, $new, $changes);
        $this->compareLoops($key, $old, $new, $changes);
        $this->compareTryCatch($key, $old, $new, $changes);
        $this->compareEarlyReturns($key, $old, $new, $changes);
        $this->compareSwitchMatch($key, $old, $new, $changes);
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareIfStatements(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldIfs = $this->finder->findInstanceOf([$old], Stmt\If_::class);
        $newIfs = $this->finder->findInstanceOf([$new], Stmt\If_::class);

        $countDiff = count($newIfs) - count($oldIfs);

        if ($countDiff > 0) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CONDITIONAL,
                severity: Severity::LOW,
                description: "{$countDiff} if statement(s) added in {$key}",
                location: $key,
            );
        } elseif ($countDiff < 0) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CONDITIONAL,
                severity: Severity::HIGH,
                description: abs($countDiff).' if statement(s) removed in '.$key,
                location: $key,
            );
        }

        // Check for changed conditions in existing if statements
        $minCount = min(count($oldIfs), count($newIfs));
        for ($i = 0; $i < $minCount; $i++) {
            $oldCond = $this->printer->prettyPrintExpr($oldIfs[$i]->cond);
            $newCond = $this->printer->prettyPrintExpr($newIfs[$i]->cond);

            if ($oldCond !== $newCond) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CONDITIONAL,
                    severity: Severity::MEDIUM,
                    description: "If condition changed in {$key}: `{$this->summarizeExpr($oldIfs[$i]->cond)}` -> `{$this->summarizeExpr($newIfs[$i]->cond)}`",
                    location: $key,
                    line: $newIfs[$i]->getStartLine(),
                );
            }

            // Check for added/removed elseif/else branches
            $oldElseifs = count($oldIfs[$i]->elseifs);
            $newElseifs = count($newIfs[$i]->elseifs);

            if ($oldElseifs !== $newElseifs) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CONDITIONAL,
                    severity: Severity::HIGH,
                    description: "Elseif branches changed in {$key}: {$oldElseifs} -> {$newElseifs}",
                    location: $key,
                    line: $newIfs[$i]->getStartLine(),
                );
            }

            $hadElse = $oldIfs[$i]->else !== null;
            $hasElse = $newIfs[$i]->else !== null;

            if ($hadElse !== $hasElse) {
                $action = $hasElse ? 'added' : 'removed';
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CONDITIONAL,
                    severity: Severity::HIGH,
                    description: "Else branch {$action} in {$key}",
                    location: $key,
                    line: $newIfs[$i]->getStartLine(),
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareLoops(string $key, Node $old, Node $new, array &$changes): void
    {
        $loopTypes = [Stmt\For_::class, Stmt\Foreach_::class, Stmt\While_::class, Stmt\Do_::class];

        foreach ($loopTypes as $loopType) {
            $oldLoops = $this->finder->findInstanceOf([$old], $loopType);
            $newLoops = $this->finder->findInstanceOf([$new], $loopType);

            $shortName = class_basename($loopType);
            $diff = count($newLoops) - count($oldLoops);

            if ($diff > 0) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LOOP,
                    severity: Severity::MEDIUM,
                    description: "{$diff} {$shortName} loop(s) added in {$key}",
                    location: $key,
                );
            } elseif ($diff < 0) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LOOP,
                    severity: Severity::HIGH,
                    description: abs($diff)." {$shortName} loop(s) removed in {$key}",
                    location: $key,
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareTryCatch(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldTry = $this->finder->findInstanceOf([$old], Stmt\TryCatch::class);
        $newTry = $this->finder->findInstanceOf([$new], Stmt\TryCatch::class);

        $diff = count($newTry) - count($oldTry);

        if ($diff > 0) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::TRY_CATCH,
                severity: Severity::HIGH,
                description: "Try-catch block added in {$key}",
                location: $key,
            );
        } elseif ($diff < 0) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::TRY_CATCH,
                severity: Severity::HIGH,
                description: 'Try-catch block removed in '.$key,
                location: $key,
            );
        }

        // Compare catch types
        $minCount = min(count($oldTry), count($newTry));
        for ($i = 0; $i < $minCount; $i++) {
            $oldCatches = array_map(fn ($c) => $this->catchTypesToString($c), $oldTry[$i]->catches);
            $newCatches = array_map(fn ($c) => $this->catchTypesToString($c), $newTry[$i]->catches);

            if ($oldCatches !== $newCatches) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::TRY_CATCH,
                    severity: Severity::HIGH,
                    description: "Catch types changed in {$key}",
                    location: $key,
                    line: $newTry[$i]->getStartLine(),
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareEarlyReturns(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldReturns = $this->finder->findInstanceOf([$old], Stmt\Return_::class);
        $newReturns = $this->finder->findInstanceOf([$new], Stmt\Return_::class);

        $diff = count($newReturns) - count($oldReturns);

        if ($diff > 0) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::RETURN,
                severity: Severity::HIGH,
                description: "{$diff} return statement(s) added in {$key}",
                location: $key,
            );
        } elseif ($diff < 0) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::RETURN,
                severity: Severity::HIGH,
                description: abs($diff).' return statement(s) removed in '.$key,
                location: $key,
            );
        }

        // Check if return values changed
        $minCount = min(count($oldReturns), count($newReturns));
        for ($i = 0; $i < $minCount; $i++) {
            $oldExpr = $oldReturns[$i]->expr;
            $newExpr = $newReturns[$i]->expr;

            $oldStr = $oldExpr ? $this->printer->prettyPrintExpr($oldExpr) : 'void';
            $newStr = $newExpr ? $this->printer->prettyPrintExpr($newExpr) : 'void';

            if ($oldStr !== $newStr) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::RETURN,
                    severity: Severity::MEDIUM,
                    description: "Return value changed in {$key}: `{$this->summarizeExpr($oldExpr)}` -> `{$this->summarizeExpr($newExpr)}`",
                    location: $key,
                    line: $newReturns[$i]->getStartLine(),
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareSwitchMatch(string $key, Node $old, Node $new, array &$changes): void
    {
        // Switch statements
        $oldSwitch = $this->finder->findInstanceOf([$old], Stmt\Switch_::class);
        $newSwitch = $this->finder->findInstanceOf([$new], Stmt\Switch_::class);

        $minCount = min(count($oldSwitch), count($newSwitch));
        for ($i = 0; $i < $minCount; $i++) {
            $oldCases = count($oldSwitch[$i]->cases);
            $newCases = count($newSwitch[$i]->cases);

            if ($oldCases !== $newCases) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::SWITCH_MATCH,
                    severity: Severity::HIGH,
                    description: "Switch cases changed in {$key}: {$oldCases} -> {$newCases}",
                    location: $key,
                    line: $newSwitch[$i]->getStartLine(),
                );
            }
        }

        // Match expressions
        $oldMatch = $this->finder->findInstanceOf([$old], Expr\Match_::class);
        $newMatch = $this->finder->findInstanceOf([$new], Expr\Match_::class);

        $minCount = min(count($oldMatch), count($newMatch));
        for ($i = 0; $i < $minCount; $i++) {
            $oldArms = count($oldMatch[$i]->arms);
            $newArms = count($newMatch[$i]->arms);

            if ($oldArms !== $newArms) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::SWITCH_MATCH,
                    severity: Severity::MEDIUM,
                    description: "Match arms changed in {$key}: {$oldArms} -> {$newArms}",
                    location: $key,
                    line: $newMatch[$i]->getStartLine(),
                );
            }
        }
    }

    private function catchTypesToString(Stmt\Catch_ $catch): string
    {
        return implode('|', array_map(fn (Node\Name $n) => $n->toString(), $catch->types));
    }

    private function summarizeExpr(?Expr $expr): string
    {
        if ($expr === null) {
            return 'void';
        }

        $full = preg_replace('/\s+/', ' ', $this->printer->prettyPrintExpr($expr));

        if (mb_strlen($full) <= 60) {
            return $full;
        }

        if ($expr instanceof Expr\MethodCall) {
            $last = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '…';
            $root = $expr->var;
            while ($root instanceof Expr\MethodCall) {
                $root = $root->var;
            }
            $rootStr = $this->summarizeExpr($root);

            return "{$rootStr}->…->{$last}()";
        }

        if ($expr instanceof Expr\StaticCall) {
            $class = $expr->class instanceof Node\Name ? $expr->class->toString() : '…';
            $method = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '…';

            return "{$class}::{$method}(…)";
        }

        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name) {
            return $expr->name->toString().'(…)';
        }

        if ($expr instanceof Expr\Array_) {
            return '[…'.count($expr->items).' items]';
        }

        return Str::limit($full, 60);
    }
}
