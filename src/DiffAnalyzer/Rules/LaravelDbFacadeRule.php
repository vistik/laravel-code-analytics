<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class LaravelDbFacadeRule implements Rule
{
    /** @var list<string> Methods that read data */
    private const READ_METHODS = [
        'select',
        'selectOne',
        'selectFromWriteConnection',
        'scalar',
        'cursor',
    ];

    /** @var list<string> Methods that write or mutate data */
    private const WRITE_METHODS = [
        'insert',
        'insertOrIgnore',
        'insertGetId',
        'update',
        'upsert',
        'delete',
        'statement',
        'unprepared',
        'affectingStatement',
    ];

    /** @var list<string> Methods that control transactions */
    private const TRANSACTION_METHODS = [
        'transaction',
        'beginTransaction',
        'commit',
        'rollBack',
        'savepoint',
        'rollbackToSavepoint',
    ];

    /** @var list<string> Methods that start a query chain */
    private const ENTRY_METHODS = [
        'table',
        'connection',
        'raw',
        'query',
    ];

    private const ALL_METHODS = [
        ...self::READ_METHODS,
        ...self::WRITE_METHODS,
        ...self::TRANSACTION_METHODS,
        ...self::ENTRY_METHODS,
    ];

    private NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder;
    }

    public function shortDescription(): string
    {
        return 'Detects DB facade query additions, modifications, and removals';
    }

    public function description(): string
    {
        return 'Detects changes to Laravel DB facade usage: DB::table(), DB::select(), DB::insert(), DB::connection()->table()->select(), etc. Classifies as DB_QUERY_ADDED, DB_QUERY_MODIFIED, or DB_QUERY_REMOVED based on whether the call appeared, changed, or disappeared.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareNodes($key, $pair['old'], $pair['new'], $changes);
        }

        foreach ($comparison['functions'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareNodes($key, $pair['old'], $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareNodes(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldSigs = $this->extractSignatures($old);
        $newSigs = $this->extractSignatures($new);

        $added = array_diff($newSigs, $oldSigs);
        $removed = array_diff($oldSigs, $newSigs);

        $addedByOp = $this->groupByOperation($added);
        $removedByOp = $this->groupByOperation($removed);
        $modifiedOps = array_intersect_key($addedByOp, $removedByOp);

        foreach ($modifiedOps as $op => $_) {
            unset($addedByOp[$op], $removedByOp[$op]);

            $changes[] = new ClassifiedChange(
                category: ChangeCategory::DB_QUERY_MODIFIED,
                severity: $this->severityForOperation($op),
                description: "DB query modified in {$key}: DB::{$op}()",
                location: $key,
            );
        }

        foreach ($addedByOp as $op => $_) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::DB_QUERY_ADDED,
                severity: $this->severityForOperation($op),
                description: "DB query added in {$key}: DB::{$op}()",
                location: $key,
            );
        }

        foreach ($removedByOp as $op => $_) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::DB_QUERY_REMOVED,
                severity: $this->severityForOperation($op),
                description: "DB query removed in {$key}: DB::{$op}()",
                location: $key,
            );
        }
    }

    /**
     * Extract DB call signatures as "operation|table_or_arg" strings.
     * String literals are preserved; dynamic expressions become "$".
     *
     * @return list<string>
     */
    private function extractSignatures(Node $node): array
    {
        $signatures = [];

        // DB:: static calls
        $staticCalls = $this->finder->findInstanceOf([$node], Expr\StaticCall::class);

        foreach ($staticCalls as $call) {
            if (! ($call->class instanceof Node\Name) || ! ($call->name instanceof Node\Identifier)) {
                continue;
            }

            if ($call->class->getLast() !== 'DB') {
                continue;
            }

            $method = $call->name->toString();

            if (! in_array($method, self::ALL_METHODS)) {
                continue;
            }

            $signatures[] = $method.'|'.$this->firstStringArg($call->args);
        }

        // Method calls chained off the DB facade (e.g. DB::connection()->table()->select())
        $methodCalls = $this->finder->findInstanceOf([$node], Expr\MethodCall::class);

        foreach ($methodCalls as $call) {
            if (! ($call->name instanceof Node\Identifier)) {
                continue;
            }

            $method = $call->name->toString();

            if (! in_array($method, self::ALL_METHODS)) {
                continue;
            }

            if ($this->originatesFromDb($call->var)) {
                $tableHint = $this->resolveTableHint($call);
                $signatures[] = $method.'|'.$tableHint;
            }
        }

        return array_unique($signatures);
    }

    /**
     * Walk back up the call chain to find a ->table('x') call, returning the table name if found.
     */
    private function resolveTableHint(Expr\MethodCall $call): string
    {
        $current = $call->var;

        while ($current instanceof Expr\MethodCall) {
            if ($current->name instanceof Node\Identifier && $current->name->toString() === 'table') {
                return $this->firstStringArg($current->args);
            }
            $current = $current->var;
        }

        return $this->firstStringArg($call->args);
    }

    /**
     * Check whether an expression originates from the DB facade.
     */
    private function originatesFromDb(Expr $expr): bool
    {
        if ($expr instanceof Expr\StaticCall) {
            return $expr->class instanceof Node\Name && $expr->class->getLast() === 'DB';
        }

        if ($expr instanceof Expr\MethodCall) {
            return $this->originatesFromDb($expr->var);
        }

        return false;
    }

    /**
     * Return the first string literal argument, or "$" for dynamic values, or "" if no args.
     *
     * @param  array<Node\Arg>  $args
     */
    private function firstStringArg(array $args): string
    {
        if (empty($args)) {
            return '';
        }

        $value = $args[0]->value;

        if ($value instanceof Node\Scalar\String_) {
            return $value->value;
        }

        return '$';
    }

    /**
     * Group signatures by their operation name.
     *
     * @param  array<string>  $signatures
     * @return array<string, list<string>>
     */
    private function groupByOperation(array $signatures): array
    {
        $grouped = [];

        foreach ($signatures as $sig) {
            [$op] = explode('|', $sig, 2);
            $grouped[$op][] = $sig;
        }

        return $grouped;
    }

    private function severityForOperation(string $op): Severity
    {
        if (in_array($op, ['delete', 'rollBack', 'unprepared', 'affectingStatement'])) {
            return Severity::HIGH;
        }

        if (in_array($op, self::WRITE_METHODS) || in_array($op, self::TRANSACTION_METHODS)) {
            return Severity::MEDIUM;
        }

        return Severity::LOW;
    }
}
