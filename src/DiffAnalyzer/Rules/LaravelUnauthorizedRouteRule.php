<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelUnauthorizedRouteRule implements Rule
{
    use AnalyzesLaravelCode;

    /** @var list<string> */
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match'];

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects routes without authentication middleware';
    }

    public function description(): string
    {
        return 'Flags newly added routes that have no authentication middleware and existing routes where auth middleware was removed, which may expose endpoints without authorization.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        if (! $this->pathStartsWith($file->effectivePath(), 'routes/')) {
            return [];
        }

        $oldNodes = $comparison['old_nodes'] ?? null;
        $newNodes = $comparison['new_nodes'] ?? null;

        if ($newNodes === null) {
            return [];
        }

        $oldRoutes = $oldNodes ? $this->extractAllRoutes($oldNodes) : [];
        $newRoutes = $this->extractAllRoutes($newNodes);

        $oldProtected = $oldNodes ? $this->extractProtectedUris($oldNodes) : [];
        $newProtected = $this->extractProtectedUris($newNodes);

        $oldUris = array_column($oldRoutes, 'uri');
        $changes = [];

        foreach ($newRoutes as $route) {
            $uri = $route['uri'];
            if ($uri === null) {
                continue;
            }

            $isNew = ! in_array($uri, $oldUris, true);
            $isProtected = in_array($uri, $newProtected, true);

            if ($isNew && ! $isProtected) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::HIGH,
                    description: "New route without authentication middleware: {$route['method']} {$uri}",
                    line: $route['line'],
                );
            }
        }

        // Routes where auth middleware was removed
        $newUris = array_column($newRoutes, 'uri');

        foreach ($oldProtected as $uri) {
            if (in_array($uri, $newUris, true) && ! in_array($uri, $newProtected, true)) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::VERY_HIGH,
                    description: "Authentication middleware removed from route: {$uri}",
                );
            }
        }

        return $changes;
    }

    /**
     * Extract all route definitions (method, uri, line) from nodes.
     *
     * @param  array<int, Node>  $nodes
     * @return list<array{method: string, uri: ?string, line: int}>
     */
    private function extractAllRoutes(array $nodes): array
    {
        $routes = [];

        foreach ($this->finder->findInstanceOf($nodes, Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || $call->class->getLast() !== 'Route') {
                continue;
            }

            $method = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

            if (! in_array($method, self::HTTP_METHODS, true)) {
                continue;
            }

            $routes[] = [
                'method' => strtoupper($method),
                'uri' => $this->getFirstStringArg($call),
                'line' => $call->getStartLine(),
            ];
        }

        return $routes;
    }

    /**
     * Extract URIs of routes that are protected by auth middleware, either via:
     * - Route::middleware('auth')->group(fn() => Route::get('/foo', ...))
     * - Route::get('/foo', ...)->middleware('auth')
     *
     * @param  array<int, Node>  $nodes
     * @return list<string>
     */
    private function extractProtectedUris(array $nodes): array
    {
        $protected = [];

        // Group-level protection: Route::middleware('auth')->group(closure)
        foreach ($this->finder->findInstanceOf($nodes, Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'group') {
                continue;
            }

            if (! $this->chainHasAuthMiddleware($call->var)) {
                continue;
            }

            foreach ($call->args as $arg) {
                if (! $arg instanceof Node\Arg) {
                    continue;
                }

                $value = $arg->value;

                if (! ($value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction)) {
                    continue;
                }

                foreach ($this->finder->findInstanceOf([$value], Expr\StaticCall::class) as $inner) {
                    if (! $inner->class instanceof Node\Name || $inner->class->getLast() !== 'Route') {
                        continue;
                    }

                    $innerMethod = $inner->name instanceof Node\Identifier ? $inner->name->toString() : '';

                    if (! in_array($innerMethod, self::HTTP_METHODS, true)) {
                        continue;
                    }

                    $uri = $this->getFirstStringArg($inner);

                    if ($uri !== null) {
                        $protected[] = $uri;
                    }
                }
            }
        }

        // Route-level protection: Route::get(...)->middleware('auth')
        foreach ($this->finder->findInstanceOf($nodes, Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'middleware') {
                continue;
            }

            if (! $this->argsContainAuthMiddleware($call->args)) {
                continue;
            }

            $routeCall = $this->findRouteCallInChain($call->var);

            if ($routeCall !== null) {
                $uri = $this->getFirstStringArg($routeCall);

                if ($uri !== null) {
                    $protected[] = $uri;
                }
            }
        }

        return array_unique($protected);
    }

    /**
     * Walk a method-call chain looking for ->middleware([...auth...]) or ::middleware([...auth...]).
     */
    private function chainHasAuthMiddleware(Node $node): bool
    {
        if ($node instanceof Expr\MethodCall) {
            if ($node->name instanceof Node\Identifier && $node->name->toString() === 'middleware') {
                if ($this->argsContainAuthMiddleware($node->args)) {
                    return true;
                }
            }

            return $this->chainHasAuthMiddleware($node->var);
        }

        if ($node instanceof Expr\StaticCall) {
            if ($node->name instanceof Node\Identifier && $node->name->toString() === 'middleware') {
                return $this->argsContainAuthMiddleware($node->args);
            }
        }

        return false;
    }

    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function argsContainAuthMiddleware(array $args): bool
    {
        foreach ($args as $arg) {
            if (! $arg instanceof Node\Arg) {
                continue;
            }

            $value = $arg->value;

            if ($value instanceof Node\Scalar\String_ && $this->isAuthMiddleware($value->value)) {
                return true;
            }

            if ($value instanceof Expr\Array_) {
                foreach ($value->items as $item) {
                    if ($item->value instanceof Node\Scalar\String_
                        && $this->isAuthMiddleware($item->value->value)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function isAuthMiddleware(string $name): bool
    {
        return $name === 'auth' || str_starts_with($name, 'auth:');
    }

    /**
     * Walk a method-call chain to find the underlying Route::get/post/... static call.
     */
    private function findRouteCallInChain(Node $node): ?Expr\StaticCall
    {
        if ($node instanceof Expr\StaticCall) {
            if ($node->class instanceof Node\Name && $node->class->getLast() === 'Route') {
                $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';

                if (in_array($method, self::HTTP_METHODS, true)) {
                    return $node;
                }
            }

            return null;
        }

        if ($node instanceof Expr\MethodCall) {
            return $this->findRouteCallInChain($node->var);
        }

        return null;
    }
}
