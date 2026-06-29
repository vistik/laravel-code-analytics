<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class LaravelQueryBuilderRule implements Rule
{
    private const WHERE_METHODS = [
        'where', 'orWhere', 'whereNot', 'orWhereNot',
        'whereNull', 'whereNotNull', 'orWhereNull', 'orWhereNotNull',
        'whereIn', 'whereNotIn', 'orWhereIn', 'orWhereNotIn',
        'whereBetween', 'whereNotBetween', 'orWhereBetween', 'orWhereNotBetween',
        'whereColumn', 'whereRaw', 'orWhereRaw',
        'whereDate', 'whereTime', 'whereYear', 'whereMonth', 'whereDay',
        'whereHas', 'whereDoesntHave', 'orWhereHas', 'orWhereDoesntHave',
        'whereMorphedTo', 'whereMorphRelatedTo',
    ];

    private const ORDER_METHODS = [
        'orderBy', 'orderByDesc', 'orderByRaw',
        'latest', 'oldest', 'reorder',
    ];

    private const JOIN_METHODS = [
        'join', 'leftJoin', 'rightJoin', 'crossJoin',
        'joinWhere', 'leftJoinWhere',
        'joinSub', 'leftJoinSub', 'rightJoinSub',
    ];

    private NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder;
    }

    public function shortDescription(): string
    {
        return 'Detects structural changes within DB query builder chains';
    }

    public function description(): string
    {
        return 'Compares DB query builder chains between old and new code to detect added/removed select fields, where conditions, order-by clauses, and joins for the same table.';
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
        $oldProfiles = $this->extractProfiles($old);
        $newProfiles = $this->extractProfiles($new);

        foreach ($oldProfiles as $table => $oldProfile) {
            if (! isset($newProfiles[$table])) {
                continue; // Entire query removed — LaravelDbFacadeRule covers this
            }

            $newProfile = $newProfiles[$table];

            $this->diffList(
                $key, $table,
                $oldProfile['selects'], $newProfile['selects'],
                ChangeCategory::DB_SELECT_FIELD_ADDED, Severity::INFO,
                ChangeCategory::DB_SELECT_FIELD_REMOVED, Severity::LOW,
                $changes
            );

            $this->diffList(
                $key, $table,
                $oldProfile['conditions'], $newProfile['conditions'],
                ChangeCategory::DB_CONDITION_ADDED, Severity::LOW,
                ChangeCategory::DB_CONDITION_REMOVED, Severity::MEDIUM,
                $changes
            );

            $this->diffList(
                $key, $table,
                $oldProfile['orders'], $newProfile['orders'],
                ChangeCategory::DB_ORDER_ADDED, Severity::INFO,
                ChangeCategory::DB_ORDER_REMOVED, Severity::INFO,
                $changes
            );

            $this->diffList(
                $key, $table,
                $oldProfile['joins'], $newProfile['joins'],
                ChangeCategory::DB_JOIN_ADDED, Severity::LOW,
                ChangeCategory::DB_JOIN_REMOVED, Severity::MEDIUM,
                $changes
            );
        }
    }

    /**
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @param  list<ClassifiedChange>  $changes
     */
    private function diffList(
        string $key,
        string $table,
        array $old,
        array $new,
        ChangeCategory $addedCategory,
        Severity $addedSeverity,
        ChangeCategory $removedCategory,
        Severity $removedSeverity,
        array &$changes
    ): void {
        foreach (array_diff($new, $old) as $item) {
            $changes[] = new ClassifiedChange(
                category: $addedCategory,
                severity: $addedSeverity,
                description: $this->formatDescription($addedCategory, $table, $item),
                location: $key,
            );
        }

        foreach (array_diff($old, $new) as $item) {
            $changes[] = new ClassifiedChange(
                category: $removedCategory,
                severity: $removedSeverity,
                description: $this->formatDescription($removedCategory, $table, $item),
                location: $key,
            );
        }
    }

    private function formatDescription(ChangeCategory $category, string $table, string $item): string
    {
        return match ($category) {
            ChangeCategory::DB_SELECT_FIELD_ADDED => "Select field added to '{$table}' query: {$item}",
            ChangeCategory::DB_SELECT_FIELD_REMOVED => "Select field removed from '{$table}' query: {$item}",
            ChangeCategory::DB_CONDITION_ADDED => "Condition added to '{$table}' query: {$item}",
            ChangeCategory::DB_CONDITION_REMOVED => "Condition removed from '{$table}' query: {$item}",
            ChangeCategory::DB_ORDER_ADDED => "Ordering added to '{$table}' query: {$item}",
            ChangeCategory::DB_ORDER_REMOVED => "Ordering removed from '{$table}' query: {$item}",
            ChangeCategory::DB_JOIN_ADDED => "Join added to '{$table}' query: {$item}",
            ChangeCategory::DB_JOIN_REMOVED => "Join removed from '{$table}' query: {$item}",
            default => $item,
        };
    }

    /**
     * Extract query chain profiles keyed by table name.
     * When multiple chains target the same table, the first one found wins.
     *
     * @return array<string, array{selects: list<string>, conditions: list<string>, orders: list<string>, joins: list<string>}>
     */
    private function extractProfiles(Node $node): array
    {
        $profiles = [];

        foreach ($this->findOutermostDbCalls($node) as $call) {
            $chain = $this->flattenChain($call);
            $table = $this->extractTableName($chain);

            if ($table === null || isset($profiles[$table])) {
                continue;
            }

            $profiles[$table] = [
                'selects' => $this->extractSelects($chain),
                'conditions' => $this->extractConditions($chain),
                'orders' => $this->extractOrders($chain),
                'joins' => $this->extractJoins($chain),
            ];
        }

        return $profiles;
    }

    /**
     * Find the outermost DB method call nodes in the given subtree.
     *
     * A node is "outermost" if it originates from DB but is not the var of any
     * other DB-originating call — i.e. it is the terminal call in the chain.
     *
     * @return list<Expr\MethodCall>
     */
    private function findOutermostDbCalls(Node $node): array
    {
        $allDbCalls = [];

        foreach ($this->finder->findInstanceOf([$node], Expr\MethodCall::class) as $call) {
            if ($this->originatesFromDb($call->var)) {
                $allDbCalls[spl_object_id($call)] = $call;
            }
        }

        $innerIds = [];
        foreach ($allDbCalls as $call) {
            if ($call->var instanceof Expr\MethodCall) {
                $innerIds[spl_object_id($call->var)] = true;
            }
        }

        return array_values(array_filter(
            $allDbCalls,
            fn ($call) => ! isset($innerIds[spl_object_id($call)])
        ));
    }

    /**
     * Flatten a method call chain into an ordered list of [method, args] pairs, outermost first.
     *
     * @return list<array{method: string, args: list<Node\Arg>}>
     */
    private function flattenChain(Expr\MethodCall $call): array
    {
        $chain = [];
        $current = $call;

        while ($current instanceof Expr\MethodCall) {
            if ($current->name instanceof Node\Identifier) {
                $chain[] = [
                    'method' => $current->name->toString(),
                    'args' => $current->args,
                ];
            }
            $current = $current->var;
        }

        return $chain;
    }

    private function extractTableName(array $chain): ?string
    {
        foreach ($chain as $call) {
            if ($call['method'] === 'table') {
                $name = $this->firstStringArg($call['args']);

                return $name !== '' && $name !== '$' ? $name : null;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractSelects(array $chain): array
    {
        $fields = [];

        foreach ($chain as $call) {
            if ($call['method'] !== 'select' && $call['method'] !== 'addSelect') {
                continue;
            }

            foreach ($call['args'] as $arg) {
                $this->collectStringValues($arg->value, $fields);
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Recursively collect string literal values, handling both scalar strings and arrays.
     *
     * @param  list<string>  $out
     */
    private function collectStringValues(Expr $expr, array &$out): void
    {
        if ($expr instanceof Node\Scalar\String_) {
            $out[] = $expr->value;

            return;
        }

        if ($expr instanceof Expr\Array_) {
            foreach ($expr->items as $item) {
                if ($item !== null) {
                    $this->collectStringValues($item->value, $out);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function extractConditions(array $chain): array
    {
        $conditions = [];

        foreach ($chain as $call) {
            if (! in_array($call['method'], self::WHERE_METHODS)) {
                continue;
            }

            $args = array_map(fn ($a) => $this->exprToString($a->value), $call['args']);
            $conditions[] = $call['method'].'('.implode(', ', $args).')';
        }

        return array_values(array_unique($conditions));
    }

    /**
     * @return list<string>
     */
    private function extractOrders(array $chain): array
    {
        $orders = [];

        foreach ($chain as $call) {
            if (! in_array($call['method'], self::ORDER_METHODS)) {
                continue;
            }

            $args = array_map(fn ($a) => $this->exprToString($a->value), $call['args']);
            $orders[] = $call['method'].'('.implode(', ', $args).')';
        }

        return array_values(array_unique($orders));
    }

    /**
     * @return list<string>
     */
    private function extractJoins(array $chain): array
    {
        $joins = [];

        foreach ($chain as $call) {
            if (! in_array($call['method'], self::JOIN_METHODS)) {
                continue;
            }

            $args = array_map(fn ($a) => $this->exprToString($a->value), $call['args']);
            $joins[] = $call['method'].'('.implode(', ', $args).')';
        }

        return array_values(array_unique($joins));
    }

    private function exprToString(Expr $expr): string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return "'{$expr->value}'";
        }

        if ($expr instanceof Node\Scalar\LNumber) {
            return (string) $expr->value;
        }

        if ($expr instanceof Expr\Array_) {
            $items = [];
            foreach ($expr->items as $item) {
                $items[] = $item !== null ? $this->exprToString($item->value) : '?';
            }

            return '['.implode(', ', $items).']';
        }

        return '$';
    }

    /**
     * @param  list<Node\Arg>  $args
     */
    private function firstStringArg(array $args): string
    {
        if (empty($args)) {
            return '';
        }

        $value = $args[0]->value;

        return $value instanceof Node\Scalar\String_ ? $value->value : '$';
    }

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
}
