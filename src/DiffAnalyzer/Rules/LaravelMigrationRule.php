<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelMigrationRule implements Rule
{
    use AnalyzesLaravelCode;

    /** @var list<string> */
    private const COLUMN_METHODS = [
        'bigIncrements', 'bigInteger', 'binary', 'boolean', 'char', 'date', 'dateTime',
        'dateTimeTz', 'decimal', 'double', 'enum', 'float', 'foreignId', 'foreignIdFor',
        'foreignUlid', 'foreignUuid', 'id', 'increments', 'integer', 'ipAddress', 'json',
        'jsonb', 'longText', 'macAddress', 'mediumIncrements', 'mediumInteger', 'mediumText',
        'morphs', 'nullableMorphs', 'nullableTimestamps', 'nullableUlidMorphs',
        'nullableUuidMorphs', 'rememberToken', 'set', 'smallIncrements', 'smallInteger',
        'softDeletes', 'softDeletesTz', 'string', 'text', 'time', 'timeTz', 'timestamp',
        'timestampTz', 'timestamps', 'timestampsTz', 'tinyIncrements', 'tinyInteger',
        'tinyText', 'unsignedBigInteger', 'unsignedInteger', 'unsignedMediumInteger',
        'unsignedSmallInteger', 'unsignedTinyInteger', 'ulidMorphs', 'ulid', 'uuid',
        'uuidMorphs', 'year',
    ];

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
        return 'Detects migration table, column, index, and foreign key changes';
    }

    public function description(): string
    {
        return 'Detects migration operations: table create/drop/rename, column additions/removals/modifications, index changes, and foreign key changes.';
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

            $this->analyzeSchemaOperations($key, $pair['new'], $changes);
            $this->analyzeColumnOperations($key, $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeSchemaOperations(string $key, Node $method, array &$changes): void
    {
        foreach ($this->findStaticCalls($method, 'Schema') as $call) {
            $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            $tableName = $this->getFirstStringArg($call);
            $tableInfo = $tableName ? " ({$tableName})" : '';

            match ($methodName) {
                'create' => $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Migration creates table{$tableInfo}",
                    location: $key,
                    line: $call->getStartLine(),
                ),
                'drop', 'dropIfExists' => $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::VERY_HIGH,
                    description: "Migration drops table{$tableInfo}",
                    location: $key,
                    line: $call->getStartLine(),
                ),
                'table' => $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: $this->isCriticalTable($tableName) ? Severity::VERY_HIGH : Severity::MEDIUM,
                    description: 'Migration modifies table'.$tableInfo.($this->isCriticalTable($tableName) ? ' (critical table — high lock risk)' : ''),
                    location: $key,
                    line: $call->getStartLine(),
                ),
                'rename' => $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::VERY_HIGH,
                    description: "Migration renames table{$tableInfo}",
                    location: $key,
                    line: $call->getStartLine(),
                ),
                default => null,
            };
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeColumnOperations(string $key, Node $method, array &$changes): void
    {
        foreach ($this->findMethodCallsByName($method, 'dropColumn') as $call) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::VERY_HIGH,
                description: 'Migration drops column',
                location: $key,
                line: $call->getStartLine(),
            );
        }

        foreach ($this->findMethodCallsByName($method, 'dropColumns') as $call) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::VERY_HIGH,
                description: 'Migration drops columns',
                location: $key,
                line: $call->getStartLine(),
            );
        }

        foreach ($this->findMethodCallsByName($method, 'renameColumn') as $call) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::VERY_HIGH,
                description: 'Migration renames column',
                location: $key,
                line: $call->getStartLine(),
            );
        }

        foreach ($this->findMethodCallsByName($method, 'change') as $call) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::MEDIUM,
                description: 'Migration modifies column',
                location: $key,
                line: $call->getStartLine(),
            );
        }

        $indexMethods = ['index', 'unique', 'primary', 'fullText', 'spatialIndex'];
        $dropIndexMethods = ['dropIndex', 'dropUnique', 'dropPrimary', 'dropFullText', 'dropSpatialIndex'];

        $methodCalls = $this->extractMethodCallNames($method);

        foreach ($indexMethods as $indexMethod) {
            if (in_array($indexMethod, $methodCalls, true)) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::INFO,
                    description: "Migration adds {$indexMethod} index",
                    location: $key,
                );
            }
        }

        foreach ($dropIndexMethods as $dropMethod) {
            if (in_array($dropMethod, $methodCalls, true)) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Migration removes index ({$dropMethod})",
                    location: $key,
                );
            }
        }

        if (in_array('foreign', $methodCalls, true)) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::MEDIUM,
                description: 'Migration adds foreign key',
                location: $key,
            );
        }

        if (in_array('dropForeign', $methodCalls, true)) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::MEDIUM,
                description: 'Migration drops foreign key',
                location: $key,
            );
        }

        // Detect column additions
        foreach ($this->finder->findInstanceOf([$method], Node\Expr\MethodCall::class) as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            if (in_array($name, self::COLUMN_METHODS, true)) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::INFO,
                    description: "Migration adds column ({$name})",
                    location: $key,
                    line: $call->getStartLine(),
                );
            }
        }
    }
}
