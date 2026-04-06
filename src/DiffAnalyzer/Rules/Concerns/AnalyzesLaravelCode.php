<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

trait AnalyzesLaravelCode
{
    private NodeFinder $finder;

    private Standard $printer;

    private function initializeAnalyzer(): void
    {
        $this->finder = new NodeFinder;
        $this->printer = new Standard;
    }

    /**
     * @return list<Expr\StaticCall>
     */
    private function findStaticCalls(Node $node, string $className): array
    {
        $calls = $this->finder->findInstanceOf([$node], Expr\StaticCall::class);

        return array_values(array_filter($calls, function (Expr\StaticCall $call) use ($className) {
            return $call->class instanceof Node\Name && $call->class->getLast() === $className;
        }));
    }

    /**
     * @return list<Expr\MethodCall>
     */
    private function findMethodCallsByName(Node $node, string $methodName): array
    {
        $calls = $this->finder->findInstanceOf([$node], Expr\MethodCall::class);

        return array_values(array_filter($calls, function (Expr\MethodCall $call) use ($methodName) {
            return $call->name instanceof Node\Identifier && $call->name->toString() === $methodName;
        }));
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

    /**
     * @return list<string>
     */
    private function extractStaticCallMethods(Node $node, string $className): array
    {
        $methods = [];

        foreach ($this->findStaticCalls($node, $className) as $call) {
            if ($call->name instanceof Node\Identifier) {
                $methods[] = $call->name->toString();
            }
        }

        return $methods;
    }

    private function getMethodName(string $key): string
    {
        return explode('::', $key)[1] ?? '';
    }

    private function getClassName(string $key): string
    {
        return explode('::', $key)[0];
    }

    private function pathContains(string $path, string $segment): bool
    {
        return str_contains($path, $segment);
    }

    private function pathStartsWith(string $path, string $prefix): bool
    {
        return str_starts_with($path, $prefix);
    }

    /**
     * @return list<string>
     */
    private function getImplementedInterfaces(array $comparison, string $className): array
    {
        foreach (['classes', 'interfaces', 'enums'] as $type) {
            foreach ($comparison[$type] ?? [] as $name => $pair) {
                if ($name === $className && $pair['new'] !== null && $pair['new'] instanceof Stmt\Class_) {
                    return array_map(fn (Node\Name $n) => $n->getLast(), $pair['new']->implements);
                }
            }
        }

        return [];
    }

    /**
     * Check if a class implements a specific interface in the new version.
     */
    private function classImplements(array $comparison, string $className, string $interface): bool
    {
        return in_array($interface, $this->getImplementedInterfaces($comparison, $className), true);
    }

    /**
     * Check if a class extends a specific parent in the new version.
     */
    private function classExtends(array $comparison, string $className, string $parent): bool
    {
        foreach ($comparison['classes'] ?? [] as $name => $pair) {
            if ($name === $className && $pair['new'] !== null && $pair['new'] instanceof Stmt\Class_) {
                return $pair['new']->extends?->getLast() === $parent;
            }
        }

        return false;
    }

    /**
     * Get the first string argument from a method/static call.
     */
    private function getFirstStringArg(Expr\StaticCall|Expr\MethodCall $call): ?string
    {
        if (isset($call->args[0]) && $call->args[0] instanceof Node\Arg) {
            $value = $call->args[0]->value;
            if ($value instanceof Node\Scalar\String_) {
                return $value->value;
            }
        }

        return null;
    }
}
