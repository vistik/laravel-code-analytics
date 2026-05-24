<?php

namespace Vistik\LaravelCodeAnalytics\Endpoints;

/**
 * Walks the dependency graph in reverse to find which HTTP endpoints are reachable
 * from the set of files changed in a PR.
 *
 * An endpoint is "affected" if the changed file is either the controller itself, or
 * a dependency (at any depth up to $maxDepth) that the controller depends on.
 */
class AffectedEndpointResolver
{
    public function __construct(private readonly int $maxDepth = 5) {}

    /**
     * @param  array<string, RouteDefinition[]>  $routeIndex  controller path => routes
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $edges  [sourceId, targetId, type?]
     * @param  array<string, string>  $nodeIdToPath  node ID => file path
     * @param  array<int, array<string, mixed>>  $diffNodes  changed file nodes
     * @return AffectedEndpoint[]
     */
    public function resolve(
        array $routeIndex,
        array $edges,
        array $nodeIdToPath,
        array $diffNodes,
    ): array {
        if (empty($routeIndex)) {
            return [];
        }

        $reverseEdges = $this->buildReverseEdges($edges);
        $forwardEdges = $this->buildForwardEdges($edges);
        $pathToNodeId = array_flip($nodeIdToPath);

        $affectedEndpoints = [];
        $seenEndpoints = [];

        foreach ($diffNodes as $node) {
            if (empty($node['path'])) {
                continue;
            }

            $startPath = $node['path'];
            $startNodeId = $node['id'];

            $results = $this->bfsToControllers(
                startNodeId: $startNodeId,
                startPath: $startPath,
                reverseEdges: $reverseEdges,
                nodeIdToPath: $nodeIdToPath,
                routeIndex: $routeIndex,
            );

            foreach ($results as [$controllerPath, $chain]) {
                foreach ($routeIndex[$controllerPath] as $route) {
                    $key = $route->method.':'.$route->uri.':'.$route->handlerMethod;
                    if (isset($seenEndpoints[$key])) {
                        continue;
                    }
                    $seenEndpoints[$key] = true;

                    $controllerNodeId = $pathToNodeId[$controllerPath] ?? null;
                    $reachableDepths = $controllerNodeId !== null
                        ? $this->forwardReachable($controllerNodeId, $forwardEdges)
                        : [];

                    $affectedEndpoints[] = new AffectedEndpoint(
                        route: $route,
                        triggeredByPath: $startPath,
                        dependencyChain: $chain,
                        controllerNodeId: $controllerNodeId,
                        reachableNodeIds: array_keys($reachableDepths),
                        reachableDepths: $reachableDepths,
                    );
                }
            }
        }

        usort($affectedEndpoints, fn ($a, $b) => strcmp($a->route->uri.$a->route->method, $b->route->uri.$b->route->method));

        return $affectedEndpoints;
    }

    /**
     * BFS from a changed node through reverse edges, collecting any controller paths hit.
     *
     * @return array<array{0: string, 1: string[]}>  [[controllerPath, chain], ...]
     */
    private function bfsToControllers(
        string $startNodeId,
        string $startPath,
        array $reverseEdges,
        array $nodeIdToPath,
        array $routeIndex,
    ): array {
        $results = [];
        $visited = [];

        // [nodeId, chain of paths from start to this node]
        $queue = [[$startNodeId, [$startPath]]];

        while (! empty($queue)) {
            [$currentId, $chain] = array_shift($queue);

            if (isset($visited[$currentId])) {
                continue;
            }
            $visited[$currentId] = true;

            $currentPath = $nodeIdToPath[$currentId] ?? null;
            if ($currentPath === null) {
                continue;
            }

            if (isset($routeIndex[$currentPath])) {
                $results[] = [$currentPath, $chain];
            }

            if (count($chain) >= $this->maxDepth) {
                continue;
            }

            foreach ($reverseEdges[$currentId] ?? [] as $parentId) {
                if (isset($visited[$parentId])) {
                    continue;
                }
                $parentPath = $nodeIdToPath[$parentId] ?? null;
                $queue[] = [$parentId, $parentPath ? [...$chain, $parentPath] : $chain];
            }
        }

        return $results;
    }

    /**
     * BFS forward from a controller node, returning each reachable node ID mapped to its depth.
     * Depth 0 = the controller itself; depth N = N hops away.
     *
     * @return array<string, int>  nodeId => depth
     */
    private function forwardReachable(string $startId, array $forwardEdges): array
    {
        $visited = [$startId => 0];
        $queue = [[$startId, 0]];

        while (! empty($queue)) {
            [$current, $depth] = array_shift($queue);
            foreach ($forwardEdges[$current] ?? [] as $targetId) {
                if (! isset($visited[$targetId])) {
                    $visited[$targetId] = $depth + 1;
                    $queue[] = [$targetId, $depth + 1];
                }
            }
        }

        return $visited;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $edges
     * @return array<string, string[]>  targetId => [sourceId, ...]
     */
    private function buildReverseEdges(array $edges): array
    {
        $reverse = [];
        foreach ($edges as [$sourceId, $targetId]) {
            $reverse[$targetId][] = $sourceId;
        }

        return $reverse;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $edges
     * @return array<string, string[]>  sourceId => [targetId, ...]
     */
    private function buildForwardEdges(array $edges): array
    {
        $forward = [];
        foreach ($edges as [$sourceId, $targetId]) {
            $forward[$sourceId][] = $targetId;
        }

        return $forward;
    }
}
