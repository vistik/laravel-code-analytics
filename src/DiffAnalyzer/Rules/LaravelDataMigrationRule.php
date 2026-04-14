<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelDataMigrationRule implements Rule
{
    use AnalyzesLaravelCode;

    /** @var list<string> */
    private const DB_DATA_METHODS = ['insert', 'update', 'delete', 'statement', 'unprepared'];

    /** @var list<string> */
    private const ELOQUENT_DML_METHODS = [
        'create', 'insert', 'update', 'delete', 'destroy',
        'forceDelete', 'firstOrCreate', 'updateOrCreate', 'upsert', 'truncate',
    ];

    /** @var list<string> */
    private const FRAMEWORK_CLASSES = [
        'Schema', 'DB', 'Blueprint', 'Migration', 'Artisan',
        'Event', 'Log', 'Cache', 'Queue', 'Bus', 'Gate',
    ];

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects data manipulation inside migration files';
    }

    public function description(): string
    {
        return 'Flags any data manipulation (DB queries, Eloquent model calls, raw SQL) inside migration files. '
            .'Data migrations mixed with schema migrations can cause issues with rollbacks and deployment order.';
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

            $this->detectDbDataMethods($key, $pair['new'], $changes);
            $this->detectDbTableCalls($key, $pair['new'], $changes);
            $this->detectEloquentDml($key, $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * Detect direct DB DML calls: DB::insert(), DB::update(), DB::delete(), DB::statement(), DB::unprepared()
     *
     * @param  list<ClassifiedChange>  $changes
     */
    private function detectDbDataMethods(string $key, Node $node, array &$changes): void
    {
        foreach ($this->findStaticCalls($node, 'DB') as $call) {
            if (! ($call->name instanceof Node\Identifier)) {
                continue;
            }

            $method = $call->name->toString();

            if (! in_array($method, self::DB_DATA_METHODS, true)) {
                continue;
            }

            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL_MIGRATION,
                severity: Severity::VERY_HIGH,
                description: "Uses DB::{$method}() for data manipulation in migration — data should be seeded separately",
                location: $key,
                line: $call->getStartLine(),
            );
        }
    }

    /**
     * Detect DB::table() calls indicating query builder data operations.
     *
     * @param  list<ClassifiedChange>  $changes
     */
    private function detectDbTableCalls(string $key, Node $node, array &$changes): void
    {
        foreach ($this->findStaticCalls($node, 'DB') as $call) {
            if (! ($call->name instanceof Node\Identifier)) {
                continue;
            }

            if ($call->name->toString() !== 'table') {
                continue;
            }

            $tableName = $this->getFirstStringArg($call);
            $tableLabel = $tableName ? " on '{$tableName}'" : '';

            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL_MIGRATION,
                severity: Severity::MEDIUM,
                description: "Uses DB::table(){$tableLabel} query builder in migration — data manipulation present",
                location: $key,
                line: $call->getStartLine(),
            );
        }
    }

    /**
     * Detect Eloquent model DML static calls: User::create(), User::insert(), etc.
     *
     * @param  list<ClassifiedChange>  $changes
     */
    private function detectEloquentDml(string $key, Node $node, array &$changes): void
    {
        $allStaticCalls = $this->finder->findInstanceOf([$node], Expr\StaticCall::class);

        foreach ($allStaticCalls as $call) {
            if (! ($call->class instanceof Node\Name) || ! ($call->name instanceof Node\Identifier)) {
                continue;
            }

            $className = $call->class->getLast();
            $method = $call->name->toString();

            if (in_array($className, self::FRAMEWORK_CLASSES, true)) {
                continue;
            }

            if (! in_array($method, self::ELOQUENT_DML_METHODS, true)) {
                continue;
            }

            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL_MIGRATION,
                severity: Severity::VERY_HIGH,
                description: "Eloquent model {$className}::{$method}() used in migration — data manipulation present",
                location: $key,
                line: $call->getStartLine(),
            );
        }
    }
}
