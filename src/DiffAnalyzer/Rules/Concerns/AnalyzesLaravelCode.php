<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
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
            if ($value instanceof Scalar\String_) {
                return $value->value;
            }
        }

        return null;
    }

    private function extractPropertyStringValue(Stmt\Property $prop): ?string
    {
        foreach ($prop->props as $propertyProp) {
            if ($propertyProp->default instanceof Scalar\String_) {
                return $propertyProp->default->value;
            }
        }

        return null;
    }

    /**
     * Parse a Laravel command signature and return its arguments and options.
     *
     * @return array<string, array{type: 'argument'|'option', default: ?string, required: bool}>
     */
    private function parseSignatureTokens(string $signature): array
    {
        $tokens = [];

        if (! preg_match_all('/\{([^}]+)\}/', $signature, $matches)) {
            return $tokens;
        }

        foreach ($matches[1] as $raw) {
            // Strip description (everything after first colon)
            $token = trim((string) preg_replace('/\s*:.*$/s', '', $raw));

            if (str_starts_with($token, '--')) {
                // Named option: --name, --name=default, --N|name, --N|name=default
                $token = substr($token, 2);

                if (str_contains($token, '|')) {
                    $token = explode('|', $token, 2)[1];
                }

                $default = null;
                $required = false;

                if (str_contains($token, '=')) {
                    [$name, $default] = explode('=', $token, 2);
                    $required = $default === '';
                    $default = $default !== '' ? $default : null;
                } else {
                    $name = $token;
                }

                $tokens['--'.trim($name)] = ['type' => 'option', 'default' => $default, 'required' => $required];
            } else {
                // Positional argument: name, name?, name=default, name*, name?*
                $default = null;

                if (str_contains($token, '=')) {
                    [$name, $default] = explode('=', $token, 2);
                } else {
                    $name = $token;
                }

                $optional = str_contains($name, '?');
                $cleanName = rtrim(trim($name), '?*');

                $tokens[$cleanName] = [
                    'type' => 'argument',
                    'default' => $default,
                    'required' => ! $optional && $default === null,
                ];
            }
        }

        return $tokens;
    }
}
