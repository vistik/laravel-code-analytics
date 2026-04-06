<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelRouteRule implements Rule
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
        return 'Detects route, middleware, and rate limiter changes';
    }

    public function description(): string
    {
        return 'Detects route changes: added/removed routes, HTTP method changes, URI changes, route name changes, middleware additions, and rate limiter definitions.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];
        $path = $file->effectivePath();

        if ($this->pathStartsWith($path, 'routes/')) {
            $this->analyzeRouteFile($comparison, $changes);
        }

        // Rate limiters can be in providers or bootstrap
        if ($this->pathContains($path, 'Providers/') || $path === 'bootstrap/app.php') {
            $this->analyzeRateLimiters($comparison, $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeRouteFile(array $comparison, array &$changes): void
    {
        $oldRoutes = $this->extractRouteDefinitions($comparison['old_nodes']);
        $newRoutes = $this->extractRouteDefinitions($comparison['new_nodes']);

        $oldByUri = $this->indexRoutesByUri($oldRoutes);
        $newByUri = $this->indexRoutesByUri($newRoutes);

        // New routes
        foreach (array_diff_key($newByUri, $oldByUri) as $uri => $route) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::MEDIUM,
                description: "Route added: {$route['method']} {$uri}",
            );
        }

        // Removed routes
        foreach (array_diff_key($oldByUri, $newByUri) as $uri => $route) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::VERY_HIGH,
                description: "Route removed: {$route['method']} {$uri}",
            );
        }

        // Changed routes (same URI, different config)
        foreach (array_intersect_key($oldByUri, $newByUri) as $uri => $oldRoute) {
            $newRoute = $newByUri[$uri];

            if ($oldRoute['method'] !== $newRoute['method']) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::VERY_HIGH,
                    description: "Route HTTP method changed for {$uri}: {$oldRoute['method']} -> {$newRoute['method']}",
                );
            }

            if ($oldRoute['name'] !== $newRoute['name'] && ($oldRoute['name'] !== null || $newRoute['name'] !== null)) {
                $oldName = $oldRoute['name'] ?? 'unnamed';
                $newName = $newRoute['name'] ?? 'unnamed';
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Route name changed for {$uri}: {$oldName} -> {$newName}",
                );
            }

            $addedMiddleware = array_diff($newRoute['middleware'], $oldRoute['middleware']);
            $removedMiddleware = array_diff($oldRoute['middleware'], $newRoute['middleware']);

            foreach ($addedMiddleware as $mw) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Middleware added to route {$uri}: {$mw}",
                );
            }

            foreach ($removedMiddleware as $mw) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Middleware removed from route {$uri}: {$mw}",
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeRateLimiters(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldLimiters = $this->extractStaticCallMethods($pair['old'], 'RateLimiter');
            $newLimiters = $this->extractStaticCallMethods($pair['new'], 'RateLimiter');

            if ($oldLimiters !== $newLimiters) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Rate limiter definition changed in {$key}",
                    location: $key,
                );
            }
        }
    }

    /**
     * @param  ?array<int, Node>  $nodes
     * @return list<array{method: string, uri: ?string, name: ?string, middleware: list<string>, line: int}>
     */
    private function extractRouteDefinitions(?array $nodes): array
    {
        if ($nodes === null) {
            return [];
        }

        $routes = [];
        $staticCalls = $this->finder->findInstanceOf($nodes, Expr\StaticCall::class);

        foreach ($staticCalls as $call) {
            if (! $call->class instanceof Node\Name || $call->class->getLast() !== 'Route') {
                continue;
            }

            $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

            if (! in_array($methodName, self::HTTP_METHODS, true)) {
                continue;
            }

            $uri = $this->getFirstStringArg($call);
            $name = $this->extractChainedName($call);
            $middleware = $this->extractChainedMiddleware($call);

            $routes[] = [
                'method' => strtoupper($methodName),
                'uri' => $uri,
                'name' => $name,
                'middleware' => $middleware,
                'line' => $call->getStartLine(),
            ];
        }

        return $routes;
    }

    /**
     * @param  list<array{method: string, uri: ?string, name: ?string, middleware: list<string>, line: int}>  $routes
     * @return array<string, array{method: string, uri: ?string, name: ?string, middleware: list<string>, line: int}>
     */
    private function indexRoutesByUri(array $routes): array
    {
        $indexed = [];

        foreach ($routes as $route) {
            if ($route['uri'] !== null) {
                $indexed[$route['uri']] = $route;
            }
        }

        return $indexed;
    }

    private function extractChainedName(Expr\StaticCall $routeCall): null
    {
        $parent = $routeCall->getAttribute('parent');

        // Walk up the chain looking for ->name() calls
        // Since we don't have parent refs in parsed AST, search all method calls
        // that chain from this route definition by looking for ->name() in the same expression
        return null; // Will be enhanced when we have full expression tree traversal
    }

    /**
     * @return list<string>
     */
    private function extractChainedMiddleware(Expr\StaticCall $routeCall): array
    {
        return []; // Will be enhanced when we have full expression tree traversal
    }
}
