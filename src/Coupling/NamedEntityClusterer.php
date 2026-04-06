<?php

namespace Vistik\LaravelCodeAnalytics\Coupling;

class NamedEntityClusterer implements Clusterer
{
    /**
     * Suffixes stripped from the end of a class name to reveal the entity root.
     * Ordered longest-first to avoid partial matches (e.g. "ServiceProvider" before "Provider").
     *
     * @var list<string>
     */
    private const SUFFIXES = [
        'ServiceProvider', 'Transformer', 'Notification', 'Repository',
        'Controller', 'Formatter', 'ViewModel', 'Validator', 'Presenter',
        'Observer', 'Processor', 'Response', 'Resource', 'Listener',
        'Provider', 'Seeder', 'Request', 'Factory', 'Handler', 'Manager',
        'Builder', 'Command', 'Service', 'Policy', 'Action', 'Scope',
        'Event', 'Rule', 'Cast', 'Mail', 'Test', 'Form', 'Job',
        'DTO', 'Data',
    ];

    /**
     * CRUD/verb prefixes stripped from the front when followed by an uppercase letter.
     *
     * @var list<string>
     */
    private const PREFIXES = [
        'Generate', 'Validate', 'Process', 'Destroy', 'Dispatch',
        'Restore', 'Publish', 'Archive', 'Approve', 'Execute',
        'Create', 'Update', 'Delete', 'Handle', 'Attach', 'Detach',
        'Import', 'Export', 'Search', 'Filter', 'Notify', 'Resend',
        'Store', 'Build', 'Fetch', 'Check', 'Index', 'Force', 'Batch',
        'Send', 'Show', 'List', 'Edit', 'Make', 'Mark', 'Bulk', 'Sync',
        'Get', 'Set', 'Add', 'Run',
    ];

    /**
     * Find clusters by grouping changed nodes whose names share the same semantic
     * entity root after stripping known suffixes and CRUD verb prefixes.
     *
     * For example: User, UserController, CreateUserRequest, UpdateUserRequest,
     * CreateUserAction all reduce to "User" and are grouped together.
     *
     * Files that have no name match but have dependency edges to 2+ nodes in an
     * existing named group are pulled into that group as well.
     *
     * @param  list<array{0: string, 1: string}>  $edges
     * @param  list<string>  $changedNodeIds
     * @return array<int, array{files: list<string>, size: int}>
     */
    public function cluster(
        array $edges,
        array $changedNodeIds,
        int $minClusterSize = 3,
        int $limit = 10,
    ): array {
        if (empty($changedNodeIds)) {
            return [];
        }

        // 1. Extract semantic root for every changed node
        $nodeToRoot = [];
        foreach ($changedNodeIds as $id) {
            $root = $this->extractRoot($id);
            if ($root !== null) {
                $nodeToRoot[$id] = $root;
            }
        }

        // 2. Group by root — only keep roots that match 2+ nodes
        // (a single match is too weak a signal on its own)
        $rootGroups = [];
        foreach ($nodeToRoot as $id => $root) {
            $rootGroups[$root][] = $id;
        }
        $rootGroups = array_filter($rootGroups, fn ($g) => count($g) >= 2);

        if (empty($rootGroups)) {
            return [];
        }

        // 3. Expand groups with dependency-connected nodes that have no name match.
        // Build an undirected adjacency map restricted to changed nodes only.
        $changedSet = array_flip($changedNodeIds);
        $adjacency = [];
        foreach ($edges as [$source, $target]) {
            if (isset($changedSet[$source]) && isset($changedSet[$target])) {
                $adjacency[$source][] = $target;
                $adjacency[$target][] = $source;
            }
        }

        // Only treat a node as "named" when its root survived the 2+ member filter.
        // Nodes whose root was filtered out (e.g. "BaseController" → "Base" with 1 member)
        // are re-classified as ungrouped so they can still be pulled in via dependency edges.
        $validRoots = array_flip(array_keys($rootGroups));
        $namedNodes = array_flip(array_filter(
            array_keys($nodeToRoot),
            fn ($id) => isset($validRoots[$nodeToRoot[$id]])
        ));
        $ungrouped = array_values(array_filter($changedNodeIds, fn ($id) => ! isset($namedNodes[$id])));

        foreach ($ungrouped as $nodeId) {
            $neighbors = $adjacency[$nodeId] ?? [];
            if (empty($neighbors)) {
                continue;
            }

            // Count connections per named group
            $groupHits = [];
            foreach ($neighbors as $neighbor) {
                $root = $nodeToRoot[$neighbor] ?? null;
                if ($root !== null && isset($rootGroups[$root])) {
                    $groupHits[$root] = ($groupHits[$root] ?? 0) + 1;
                }
            }

            // Add to the group it connects to most (min 2 connections required)
            arsort($groupHits);
            $bestRoot = array_key_first($groupHits);
            if ($bestRoot !== null && $groupHits[$bestRoot] >= 2) {
                $rootGroups[$bestRoot][] = $nodeId;
                $nodeToRoot[$nodeId] = $bestRoot;
            }
        }

        return collect($rootGroups)
            ->map(fn (array $files) => ['files' => array_values($files), 'size' => count($files)])
            ->filter(fn (array $c) => $c['size'] >= $minClusterSize)
            ->sortByDesc('size')
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * Strip one known suffix and one CRUD verb prefix from a class name to
     * reveal the underlying entity root (e.g. "CreateUserRequest" → "User").
     */
    private function extractRoot(string $nodeId): ?string
    {
        // Node IDs may be prefixed with domain/folder on collision — use only the class name
        $name = str_contains($nodeId, '/')
            ? substr($nodeId, strrpos($nodeId, '/') + 1)
            : $nodeId;

        // Strip suffix
        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix) && strlen($name) > strlen($suffix)) {
                $name = substr($name, 0, -strlen($suffix));
                break;
            }
        }

        // Strip CRUD prefix only when followed by an uppercase letter (word boundary)
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $rest = substr($name, strlen($prefix));
                if ($rest !== '' && ctype_upper($rest[0])) {
                    $name = $rest;
                    break;
                }
            }
        }

        // Require at least 2 chars and that the root looks like a class name (starts uppercase)
        return (strlen($name) >= 2 && ctype_upper($name[0])) ? $name : null;
    }
}
