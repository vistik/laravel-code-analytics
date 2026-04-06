<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class SideEffectRule implements Rule
{
    /** @var list<string> Functions that produce side effects */
    private const SIDE_EFFECT_FUNCTIONS = [
        'dispatch',
        'dispatch_sync',
        'event',
        'broadcast',
        'mail',
        'notification',
        'abort',
        'abort_if',
        'abort_unless',
        'redirect',
        'session',
        'cookie',
        'cache',
        'logger',
        'info',
        'report',
        'rescue',
        'throw_if',
        'throw_unless',
    ];

    /** @var list<string> Static call targets with side effects */
    private const SIDE_EFFECT_CLASSES = [
        'Log',
        'Mail',
        'Notification',
        'Event',
        'Bus',
        'Queue',
        'Cache',
        'DB',
        'Session',
        'Cookie',
        'Http',
        'Storage',
        'Gate',
        'Artisan',
    ];

    private NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder;
    }

    public function shortDescription(): string
    {
        return 'Detects side-effect producing function and facade call changes';
    }

    public function description(): string
    {
        return 'Detects side-effect changes: added/removed calls to dispatch, event, Mail, Log, Cache, DB, Http, and other side-effect-producing functions and facades, plus exception throw additions/removals.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareFunctionCalls($key, $pair['old'], $pair['new'], $changes);
            $this->compareStaticCalls($key, $pair['old'], $pair['new'], $changes);
            $this->compareMethodCalls($key, $pair['old'], $pair['new'], $changes);
            $this->compareThrows($key, $pair['old'], $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareFunctionCalls(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldCalls = $this->extractFuncCallNames($old);
        $newCalls = $this->extractFuncCallNames($new);

        $oldSideEffects = array_intersect($oldCalls, self::SIDE_EFFECT_FUNCTIONS);
        $newSideEffects = array_intersect($newCalls, self::SIDE_EFFECT_FUNCTIONS);

        $added = array_diff($newSideEffects, $oldSideEffects);
        $removed = array_diff($oldSideEffects, $newSideEffects);

        foreach ($added as $func) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::SIDE_EFFECTS,
                severity: Severity::MEDIUM,
                description: "Side-effect function call added in {$key}: {$func}()",
                location: $key,
            );
        }

        foreach ($removed as $func) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::SIDE_EFFECTS,
                severity: Severity::MEDIUM,
                description: "Side-effect function call removed in {$key}: {$func}()",
                location: $key,
            );
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareStaticCalls(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldCalls = $this->extractStaticCallTargets($old);
        $newCalls = $this->extractStaticCallTargets($new);

        $oldSideEffects = array_intersect($oldCalls, self::SIDE_EFFECT_CLASSES);
        $newSideEffects = array_intersect($newCalls, self::SIDE_EFFECT_CLASSES);

        $added = array_diff($newSideEffects, $oldSideEffects);
        $removed = array_diff($oldSideEffects, $newSideEffects);

        foreach ($added as $class) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::SIDE_EFFECTS,
                severity: Severity::MEDIUM,
                description: "Side-effect static call added in {$key}: {$class}::",
                location: $key,
            );
        }

        foreach ($removed as $class) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::SIDE_EFFECTS,
                severity: Severity::MEDIUM,
                description: "Side-effect static call removed in {$key}: {$class}::",
                location: $key,
            );
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareMethodCalls(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldCalls = $this->extractMethodCallNames($old);
        $newCalls = $this->extractMethodCallNames($new);

        $added = array_diff($newCalls, $oldCalls);
        $removed = array_diff($oldCalls, $newCalls);

        foreach ($added as $method) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::SIDE_EFFECTS,
                severity: Severity::INFO,
                description: "Method call added in {$key}: ->{$method}()",
                location: $key,
            );
        }

        foreach ($removed as $method) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::SIDE_EFFECTS,
                severity: Severity::INFO,
                description: "Method call removed in {$key}: ->{$method}()",
                location: $key,
            );
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareThrows(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldThrows = $this->finder->findInstanceOf([$old], Expr\Throw_::class);
        $newThrows = $this->finder->findInstanceOf([$new], Expr\Throw_::class);

        $oldCount = count($oldThrows);
        $newCount = count($newThrows);

        if ($newCount > $oldCount) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::SIDE_EFFECTS,
                severity: Severity::MEDIUM,
                description: 'Exception throw added in '.$key,
                location: $key,
            );
        } elseif ($newCount < $oldCount) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::SIDE_EFFECTS,
                severity: Severity::MEDIUM,
                description: 'Exception throw removed in '.$key,
                location: $key,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function extractFuncCallNames(Node $node): array
    {
        $names = [];

        $calls = $this->finder->findInstanceOf([$node], Expr\FuncCall::class);

        foreach ($calls as $call) {
            if ($call->name instanceof Node\Name) {
                $names[] = $call->name->toString();
            }
        }

        return array_unique($names);
    }

    /**
     * @return list<string>
     */
    private function extractStaticCallTargets(Node $node): array
    {
        $targets = [];

        $calls = $this->finder->findInstanceOf([$node], Expr\StaticCall::class);

        foreach ($calls as $call) {
            if ($call->class instanceof Node\Name) {
                $targets[] = $call->class->getLast();
            }
        }

        return array_unique($targets);
    }

    /**
     * @return list<string>
     */
    private function extractMethodCallNames(Node $node): array
    {
        $names = [];

        $calls = $this->finder->findInstanceOf([$node], Expr\MethodCall::class);

        foreach ($calls as $call) {
            if ($call->name instanceof Node\Identifier) {
                $names[] = $call->name->toString();
            }
        }

        return array_unique($names);
    }
}
