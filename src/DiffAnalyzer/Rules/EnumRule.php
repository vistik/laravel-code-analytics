<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class EnumRule implements Rule
{
    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard;
    }

    public function shortDescription(): string
    {
        return 'Detects enum case and backed type changes';
    }

    public function description(): string
    {
        return 'Detects enum changes: cases added/removed/value changed, backed type changed, and enum method modifications.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['enums'] ?? [] as $name => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareBackedType($name, $pair['old'], $pair['new'], $changes);
            $this->compareCases($name, $pair['old'], $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareBackedType(string $name, Stmt\Enum_ $old, Stmt\Enum_ $new, array &$changes): void
    {
        $oldType = $old->scalarType?->toString();
        $newType = $new->scalarType?->toString();

        if ($oldType !== $newType) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CLASS_STRUCTURE,
                severity: Severity::VERY_HIGH,
                description: "Enum backed type changed on {$name}: ".($oldType ?? 'none').' -> '.($newType ?? 'none'),
                location: $name,
                line: $new->getStartLine(),
            );
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareCases(string $name, Stmt\Enum_ $old, Stmt\Enum_ $new, array &$changes): void
    {
        $oldCases = $this->extractCases($old);
        $newCases = $this->extractCases($new);

        $added = array_diff_key($newCases, $oldCases);
        $removed = array_diff_key($oldCases, $newCases);
        $common = array_intersect_key($oldCases, $newCases);

        foreach ($added as $caseName => $value) {
            $valueStr = $value !== null ? " = {$value}" : '';
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CLASS_STRUCTURE,
                severity: Severity::MEDIUM,
                description: "Enum case added: {$name}::{$caseName}{$valueStr}",
                location: $name,
                line: $new->getStartLine(),
            );
        }

        foreach ($removed as $caseName => $value) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CLASS_STRUCTURE,
                severity: Severity::HIGH,
                description: "Enum case removed: {$name}::{$caseName} — may break code referencing this case",
                location: $name,
            );
        }

        foreach ($common as $caseName => $oldValue) {
            $newValue = $newCases[$caseName];

            if ($oldValue !== $newValue) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::VALUES,
                    severity: Severity::VERY_HIGH,
                    description: "Enum case value changed: {$name}::{$caseName} ({$oldValue} -> {$newValue})",
                    location: $name,
                    line: $new->getStartLine(),
                );
            }
        }
    }

    /**
     * @return array<string, ?string>
     */
    private function extractCases(Stmt\Enum_ $enum): array
    {
        $cases = [];

        foreach ($enum->stmts as $stmt) {
            if ($stmt instanceof Stmt\EnumCase) {
                $value = $stmt->expr !== null ? $this->printer->prettyPrintExpr($stmt->expr) : null;
                $cases[$stmt->name->toString()] = $value;
            }
        }

        return $cases;
    }
}
