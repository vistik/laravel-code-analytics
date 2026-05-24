<?php

namespace Vistik\LaravelCodeAnalytics\Endpoints;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\NodeVisitor\ParentConnectingVisitor;

/**
 * Parses Laravel route files and builds an index of controller file paths to their routes.
 *
 * Handles: Route::get/post/etc, Route::resource, Route::apiResource,
 * group() with prefix/middleware/name chains, and route-level ->middleware()->name() chains.
 */
class RouteIndexBuilder
{

    /**
     * @param  array<string, string|null>  $routeFileContents  path => source
     * @param  callable(string): ?string  $fqcnToPath  resolves FQCN to file path
     * @return array<string, RouteDefinition[]>  controller file path => routes handled by that file
     */
    public function build(array $routeFileContents, callable $fqcnToPath): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $index = [];

        foreach ($routeFileContents as $routeFilePath => $source) {
            if ($source === null || $source === '') {
                continue;
            }

            try {
                $stmts = $parser->parse($source);
            } catch (\Throwable) {
                continue;
            }

            if ($stmts === null) {
                continue;
            }

            $traverser = new NodeTraverser;
            $traverser->addVisitor(new ParentConnectingVisitor);
            $visitor = new RouteCollectorVisitor;
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            foreach ($visitor->routes as $route) {
                if ($route->handlerFqcn === null) {
                    continue;
                }

                $path = $fqcnToPath($route->handlerFqcn);
                if ($path === null) {
                    continue;
                }

                $index[$path][] = $route;
            }
        }

        return $index;
    }
}

/**
 * @internal
 */
class RouteCollectorVisitor extends NodeVisitorAbstract
{
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match'];

    private const API_RESOURCE_METHODS = [
        'index'   => ['GET',    ''],
        'store'   => ['POST',   ''],
        'show'    => ['GET',    '/{id}'],
        'update'  => ['PUT',    '/{id}'],
        'destroy' => ['DELETE', '/{id}'],
    ];

    private const RESOURCE_METHODS = [
        'index'   => ['GET',    ''],
        'create'  => ['GET',    '/create'],
        'store'   => ['POST',   ''],
        'show'    => ['GET',    '/{id}'],
        'edit'    => ['GET',    '/{id}/edit'],
        'update'  => ['PUT',    '/{id}'],
        'destroy' => ['DELETE', '/{id}'],
    ];

    /** @var array<array{prefix: string, middleware: string[], name: string}> */
    private array $contextStack = [];

    /** @var array<string, string>  short alias => FQCN */
    private array $useStatements = [];

    /** @var RouteDefinition[] */
    public array $routes = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $alias = $use->alias !== null ? $use->alias->name : $use->name->getLast();
                $this->useStatements[$alias] = $use->name->toString();
            }
        }

        if ($node instanceof Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && $node->name->name === 'group'
        ) {
            $this->contextStack[] = $this->extractGroupContext($node->var);
        }

        if ($node instanceof Expr\StaticCall
            && $node->class instanceof Node\Name
            && $node->class->getLast() === 'Route'
            && $node->name instanceof Node\Identifier
        ) {
            $methodName = $node->name->name;

            if (in_array($methodName, self::HTTP_METHODS, true)) {
                $this->collectHttpRoute($node, $methodName);
            } elseif ($methodName === 'apiResource') {
                $this->collectResource($node, onlyApi: true);
            } elseif ($methodName === 'resource') {
                $this->collectResource($node, onlyApi: false);
            }
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && $node->name->name === 'group'
        ) {
            array_pop($this->contextStack);
        }

        return null;
    }

    private function extractGroupContext(Expr $chain): array
    {
        $prefix = '';
        $middleware = [];
        $name = '';

        $node = $chain;
        while ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) {
            $methodName = $node->name instanceof Node\Identifier ? $node->name->name : '';

            if ($methodName === 'prefix') {
                $val = $this->firstStringArg($node);
                if ($val !== null) {
                    $prefix = $val;
                }
            } elseif ($methodName === 'middleware') {
                $middleware = array_merge($middleware, $this->middlewareArgs($node));
            } elseif ($methodName === 'name') {
                $val = $this->firstStringArg($node);
                if ($val !== null) {
                    $name = $val;
                }
            }

            $node = $node instanceof Expr\MethodCall ? $node->var : null;
        }

        return compact('prefix', 'middleware', 'name');
    }

    private function collectHttpRoute(Expr\StaticCall $call, string $methodName): void
    {
        $uri = $this->firstStringArg($call);
        [$handlerFqcn, $handlerMethod] = $this->resolveHandler($call->args[1] ?? null);

        [$routeMiddleware, $routeName] = $this->extractRouteChain($call);

        $this->addRoute(
            httpMethod: strtoupper($methodName),
            uri: $uri ?? '',
            name: $routeName,
            middleware: $routeMiddleware,
            handlerFqcn: $handlerFqcn,
            handlerMethod: $handlerMethod ?? '__invoke',
        );
    }

    private function collectResource(Expr\StaticCall $call, bool $onlyApi): void
    {
        $uri = $this->firstStringArg($call);
        $arg1 = $call->args[1] ?? null;
        $fqcn = null;

        if ($arg1 instanceof Node\Arg) {
            $fqcn = $this->resolveClassConst($arg1->value);
        }

        if ($uri === null || $fqcn === null) {
            return;
        }

        [$routeMiddleware, $routeName] = $this->extractRouteChain($call);

        $map = $onlyApi ? self::API_RESOURCE_METHODS : self::RESOURCE_METHODS;

        foreach ($map as $action => [$httpMethod, $suffix]) {
            $this->addRoute(
                httpMethod: $httpMethod,
                uri: $uri.$suffix,
                name: $routeName ? $routeName.$uri.'.'.$action : null,
                middleware: $routeMiddleware,
                handlerFqcn: $fqcn,
                handlerMethod: $action,
            );
        }
    }

    /**
     * Walk the parent MethodCall chain above a route call to collect ->middleware() and ->name().
     *
     * @return array{0: string[], 1: ?string}
     */
    private function extractRouteChain(Expr\StaticCall $call): array
    {
        $middleware = [];
        $name = null;

        $parent = $call->getAttribute('parent');
        while ($parent instanceof Expr\MethodCall) {
            $parentMethod = $parent->name instanceof Node\Identifier ? $parent->name->name : '';

            if ($parentMethod === 'middleware') {
                $middleware = array_merge($middleware, $this->middlewareArgs($parent));
            } elseif ($parentMethod === 'name') {
                $val = $this->firstStringArg($parent);
                if ($val !== null) {
                    $name = $val;
                }
            }

            $parent = $parent->getAttribute('parent');
        }

        return [$middleware, $name];
    }

    private function addRoute(
        string $httpMethod,
        string $uri,
        ?string $name,
        array $middleware,
        ?string $handlerFqcn,
        string $handlerMethod,
    ): void {
        $fullPrefix = '';
        $allMiddleware = [];
        $namePrefix = '';

        foreach ($this->contextStack as $ctx) {
            if ($ctx['prefix'] !== '') {
                $fullPrefix .= '/'.trim($ctx['prefix'], '/');
            }
            $allMiddleware = array_merge($allMiddleware, $ctx['middleware']);
            $namePrefix .= $ctx['name'];
        }

        $segment = trim($uri, '/');
        $fullUri = $segment !== '' ? $fullPrefix.'/'.$segment : ($fullPrefix !== '' ? $fullPrefix : '/');
        $fullUri = '/'.ltrim($fullUri, '/');

        $allMiddleware = array_values(array_unique(array_merge($allMiddleware, $middleware)));
        $fullName = $name !== null ? $namePrefix.$name : null;

        $this->routes[] = new RouteDefinition(
            method: $httpMethod,
            uri: $fullUri,
            name: $fullName,
            middleware: $allMiddleware,
            handlerFqcn: $handlerFqcn,
            handlerMethod: $handlerMethod,
        );
    }

    /** @return array{0: ?string, 1: ?string} */
    private function resolveHandler(?Node\Arg $arg): array
    {
        if ($arg === null) {
            return [null, null];
        }

        $value = $arg->value;

        if ($value instanceof Expr\Array_ && count($value->items) >= 2) {
            $fqcn = $this->resolveClassConst($value->items[0]->value ?? null);
            $method = $value->items[1]->value instanceof Node\Scalar\String_
                ? $value->items[1]->value->value
                : null;

            return [$fqcn, $method];
        }

        if ($value instanceof Expr\ClassConstFetch) {
            return [$this->resolveClassConst($value), '__invoke'];
        }

        return [null, null];
    }

    private function resolveClassConst(?Node $node): ?string
    {
        if (! $node instanceof Expr\ClassConstFetch) {
            return null;
        }
        if (! $node->class instanceof Node\Name) {
            return null;
        }

        $shortName = $node->class->getLast();

        return $this->useStatements[$shortName] ?? $node->class->toString();
    }

    private function firstStringArg(Expr\StaticCall|Expr\MethodCall $call): ?string
    {
        $arg = $call->args[0] ?? null;
        if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }

        return null;
    }

    /** @return string[] */
    private function middlewareArgs(Expr\StaticCall|Expr\MethodCall $call): array
    {
        $arg = $call->args[0] ?? null;
        if (! $arg instanceof Node\Arg) {
            return [];
        }

        $value = $arg->value;

        if ($value instanceof Node\Scalar\String_) {
            return [$value->value];
        }

        if ($value instanceof Expr\Array_) {
            $result = [];
            foreach ($value->items as $item) {
                if ($item->value instanceof Node\Scalar\String_) {
                    $result[] = $item->value->value;
                }
            }

            return $result;
        }

        return [];
    }
}
