<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelTableMigrationRule implements Rule
{
    use AnalyzesLaravelCode;

    /** @var list<string> */
    private const INDEX_ADD_METHODS = ['index', 'unique', 'primary', 'fullText', 'spatialIndex'];

    /** @var list<string> */
    private const INDEX_DROP_METHODS = ['dropIndex', 'dropUnique', 'dropPrimary', 'dropFullText', 'dropSpatialIndex'];

    /** @param list<string> $criticalTables */
    public function __construct(private readonly array $criticalTables = [])
    {
        $this->initializeAnalyzer();
    }

    private function isCriticalTable(?string $tableName): bool
    {
        return $tableName !== null && in_array($tableName, $this->criticalTables, true);
    }

    public function shortDescription(): string
    {
        return 'Detects index additions and removals in migrations';
    }

    public function description(): string
    {
        return 'Flags index additions on existing tables (can lock table in MySQL/MariaDB during migration) '
            .'and index removals that may break queries relying on that index.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        if (! $this->pathContains($file->effectivePath(), 'database/migrations/')) {
            return [];
        }

        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['new'] === null) {
                continue;
            }

            foreach ($this->findStaticCalls($pair['new'], 'Schema') as $schemaCall) {
                $methodName = $schemaCall->name instanceof Node\Identifier ? $schemaCall->name->toString() : '';

                if (! in_array($methodName, ['create', 'table'], true)) {
                    continue;
                }

                $tableName = $this->getFirstStringArg($schemaCall);
                $tableLabel = $tableName ? " ({$tableName})" : '';
                $isExistingTable = $methodName === 'table';

                $closure = $this->getClosureArg($schemaCall);

                if ($closure === null) {
                    continue;
                }

                $isCriticalTable = $this->isCriticalTable($tableName);
                $this->detectIndexAdditions($key, $closure, $tableLabel, $isExistingTable, $isCriticalTable, $changes);
                $this->detectIndexRemovals($key, $closure, $tableLabel, $isCriticalTable, $changes);
            }
        }

        return $changes;
    }

    private function getClosureArg(Expr\StaticCall $call): ?Node
    {
        if (! isset($call->args[1]) || ! ($call->args[1] instanceof Node\Arg)) {
            return null;
        }

        $value = $call->args[1]->value;

        if ($value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction) {
            return $value;
        }

        return null;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function detectIndexAdditions(
        string $key,
        Node $closure,
        string $tableLabel,
        bool $isExistingTable,
        bool $isCriticalTable,
        array &$changes,
    ): void {
        foreach (self::INDEX_ADD_METHODS as $indexMethod) {
            foreach ($this->findMethodCallsByName($closure, $indexMethod) as $call) {
                if ($isExistingTable) {
                    $criticalNote = $isCriticalTable ? ' (critical table — high lock risk)' : '';
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: $isCriticalTable ? Severity::VERY_HIGH : Severity::MEDIUM,
                        description: "Adds {$indexMethod} index to existing table{$tableLabel}{$criticalNote} — may lock table during migration",
                        location: $key,
                        line: $call->getStartLine(),
                    );
                } else {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::INFO,
                        description: "Adds {$indexMethod} index to new table{$tableLabel}",
                        location: $key,
                        line: $call->getStartLine(),
                    );
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function detectIndexRemovals(
        string $key,
        Node $closure,
        string $tableLabel,
        bool $isCriticalTable,
        array &$changes,
    ): void {
        foreach (self::INDEX_DROP_METHODS as $dropMethod) {
            foreach ($this->findMethodCallsByName($closure, $dropMethod) as $call) {
                $criticalNote = $isCriticalTable ? ' (critical table)' : '';
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: $isCriticalTable ? Severity::VERY_HIGH : Severity::MEDIUM,
                    description: "Removes index ({$dropMethod}) from table{$tableLabel}{$criticalNote} — may break queries using that index",
                    location: $key,
                    line: $call->getStartLine(),
                );
            }
        }
    }
}
