<?php

namespace Vistik\LaravelCodeAnalytics\ScheduledJobs;

class AffectedScheduledJobResolver
{
    public function __construct(private readonly int $maxDepth = 5) {}

    /**
     * @param  array<string, ScheduledJobDefinition[]>  $jobIndex  handler path => jobs
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $edges
     * @param  array<string, string>  $nodeIdToPath
     * @param  array<int, array<string, mixed>>  $diffNodes
     * @return AffectedScheduledJob[]
     */
    public function resolve(
        array $jobIndex,
        array $edges,
        array $nodeIdToPath,
        array $diffNodes,
    ): array {
        if (empty($jobIndex)) {
            return [];
        }

        $reverseEdges = $this->buildReverseEdges($edges);
        $forwardEdges = $this->buildForwardEdges($edges);
        $pathToNodeId = array_flip($nodeIdToPath);

        $affected = [];
        $seen = [];

        foreach ($diffNodes as $node) {
            if (empty($node['path'])) {
                continue;
            }

            $startPath = $node['path'];
            $startNodeId = $node['id'];

            $results = $this->bfsToHandlers(
                startNodeId: $startNodeId,
                startPath: $startPath,
                reverseEdges: $reverseEdges,
                nodeIdToPath: $nodeIdToPath,
                jobIndex: $jobIndex,
            );

            foreach ($results as [$handlerPath, $chain]) {
                foreach ($jobIndex[$handlerPath] as $job) {
                    $key = $job->handlerFqcn.':'.$job->scheduleExpression;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $jobNodeId = $pathToNodeId[$handlerPath] ?? null;
                    $reachableDepths = $jobNodeId !== null
                        ? $this->forwardReachable($jobNodeId, $forwardEdges)
                        : [];

                    $affected[] = new AffectedScheduledJob(
                        job: $job,
                        triggeredByPath: $startPath,
                        dependencyChain: $chain,
                        jobNodeId: $jobNodeId,
                        reachableNodeIds: array_keys($reachableDepths),
                        reachableDepths: $reachableDepths,
                    );
                }
            }
        }

        usort($affected, fn ($a, $b) => strcmp($a->job->handlerFqcn, $b->job->handlerFqcn));

        return $affected;
    }

    /** @return array<array{0: string, 1: string[]}> */
    private function bfsToHandlers(
        string $startNodeId,
        string $startPath,
        array $reverseEdges,
        array $nodeIdToPath,
        array $jobIndex,
    ): array {
        $results = [];
        $visited = [];
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

            if (isset($jobIndex[$currentPath])) {
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

    /** @return array<string, int> nodeId => depth */
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

    /** @return array<string, string[]> targetId => [sourceId, ...] */
    private function buildReverseEdges(array $edges): array
    {
        $reverse = [];
        foreach ($edges as [$sourceId, $targetId]) {
            $reverse[$targetId][] = $sourceId;
        }

        return $reverse;
    }

    /** @return array<string, string[]> sourceId => [targetId, ...] */
    private function buildForwardEdges(array $edges): array
    {
        $forward = [];
        foreach ($edges as [$sourceId, $targetId]) {
            $forward[$sourceId][] = $targetId;
        }

        return $forward;
    }
}
