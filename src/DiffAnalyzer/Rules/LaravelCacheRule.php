<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class LaravelCacheRule implements Rule
{
    /** @var list<string> Cache methods that write/store values */
    private const WRITE_METHODS = [
        'put',
        'set',
        'add',
        'forever',
        'remember',
        'rememberForever',
        'putMany',
        'setMultiple',
    ];

    /** @var list<string> Cache methods that invalidate or clear values */
    private const INVALIDATE_METHODS = [
        'forget',
        'delete',
        'deleteMultiple',
        'flush',
        'pull',
    ];

    /** @var list<string> Cache methods that read values */
    private const READ_METHODS = [
        'get',
        'has',
        'missing',
        'many',
        'getMultiple',
        'increment',
        'decrement',
    ];

    /** @var list<string> All tracked Cache facade methods */
    private const ALL_METHODS = [
        ...self::WRITE_METHODS,
        ...self::INVALIDATE_METHODS,
        ...self::READ_METHODS,
    ];

    private NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder;
    }

    public function shortDescription(): string
    {
        return 'Detects cache operation additions, modifications, and removals';
    }

    public function description(): string
    {
        return 'Detects changes to Laravel cache usage: added cache operations (Cache::put, Cache::remember, etc.) are CACHE_ADDED, removed operations are CACHE_REMOVED, and changes to cache keys, TTLs, or operation types are CACHE_MODIFIED. Covers the Cache facade, cache() helper, and chained store/tags calls.';
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

        // When the same method base appears in both added and removed, it's a modification.
        $addedByMethod = $this->groupByMethod($added);
        $removedByMethod = $this->groupByMethod($removed);
        $modifiedMethods = array_intersect_key($addedByMethod, $removedByMethod);

        foreach ($modifiedMethods as $method => $_) {
            unset($addedByMethod[$method], $removedByMethod[$method]);

            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CACHE_MODIFIED,
                severity: $this->severityForMethod($method),
                description: "Cache operation modified in {$key}: Cache::{$method}()",
                location: $key,
            );
        }

        foreach ($addedByMethod as $method => $_) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CACHE_ADDED,
                severity: $this->severityForMethod($method),
                description: "Cache operation added in {$key}: Cache::{$method}()",
                location: $key,
            );
        }

        foreach ($removedByMethod as $method => $_) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CACHE_REMOVED,
                severity: $this->severityForMethod($method),
                description: "Cache operation removed in {$key}: Cache::{$method}()",
                location: $key,
            );
        }
    }

    /**
     * Extract cache call signatures as "method|arg0:arg1" strings.
     * String/int literals are preserved; dynamic expressions become "$".
     *
     * @return list<string>
     */
    private function extractSignatures(Node $node): array
    {
        $signatures = [];

        // Cache:: static calls
        $staticCalls = $this->finder->findInstanceOf([$node], Expr\StaticCall::class);

        foreach ($staticCalls as $call) {
            if (! ($call->class instanceof Node\Name) || ! ($call->name instanceof Node\Identifier)) {
                continue;
            }

            if ($call->class->getLast() !== 'Cache') {
                continue;
            }

            $method = $call->name->toString();

            if (! in_array($method, self::ALL_METHODS)) {
                continue;
            }

            $signatures[] = $method.'|'.$this->argSignature($call->args);
        }

        // Method calls on a cache instance returned by Cache::store(), Cache::tags(), etc.
        $methodCalls = $this->finder->findInstanceOf([$node], Expr\MethodCall::class);

        foreach ($methodCalls as $call) {
            if (! ($call->name instanceof Node\Identifier)) {
                continue;
            }

            $method = $call->name->toString();

            if (! in_array($method, self::ALL_METHODS)) {
                continue;
            }

            // Only track if the call chain originates from Cache facade or cache()
            if ($this->originatesFromCache($call->var)) {
                $signatures[] = $method.'|'.$this->argSignature($call->args);
            }
        }

        // cache() helper: cache('key') for reads, cache(['key' => 'val'], ttl) for writes
        $funcCalls = $this->finder->findInstanceOf([$node], Expr\FuncCall::class);

        foreach ($funcCalls as $call) {
            if (! ($call->name instanceof Node\Name) || $call->name->toString() !== 'cache') {
                continue;
            }

            $signatures[] = 'cache()|'.$this->argSignature($call->args);
        }

        return array_unique($signatures);
    }

    /**
     * Check whether an expression originates from the Cache facade or cache() helper.
     */
    private function originatesFromCache(Expr $expr): bool
    {
        if ($expr instanceof Expr\StaticCall) {
            return $expr->class instanceof Node\Name && $expr->class->getLast() === 'Cache';
        }

        if ($expr instanceof Expr\MethodCall) {
            return $this->originatesFromCache($expr->var);
        }

        if ($expr instanceof Expr\FuncCall) {
            return $expr->name instanceof Node\Name && $expr->name->toString() === 'cache';
        }

        return false;
    }

    /**
     * Build a short argument signature from the first two args (key + TTL).
     *
     * @param  array<Arg>  $args
     */
    private function argSignature(array $args): string
    {
        $parts = [];

        foreach (array_slice($args, 0, 2) as $arg) {
            $value = $arg->value;

            if ($value instanceof Node\Scalar\String_) {
                $parts[] = $value->value;
            } elseif ($value instanceof Node\Scalar\LNumber) {
                $parts[] = (string) $value->value;
            } else {
                $parts[] = '$';
            }
        }

        return implode(':', $parts);
    }

    /**
     * Group signatures by their method name.
     *
     * @param  array<string>  $signatures
     * @return array<string, list<string>>
     */
    private function groupByMethod(array $signatures): array
    {
        $grouped = [];

        foreach ($signatures as $sig) {
            [$method] = explode('|', $sig, 2);
            $grouped[$method][] = $sig;
        }

        return $grouped;
    }

    private function severityForMethod(string $method): Severity
    {
        if (in_array($method, self::INVALIDATE_METHODS)) {
            return Severity::HIGH;
        }

        if (in_array($method, self::WRITE_METHODS)) {
            return Severity::MEDIUM;
        }

        return Severity::LOW;
    }
}
