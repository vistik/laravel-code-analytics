<?php

namespace Vistik\LaravelCodeAnalytics\GraphIndex;

class GraphIndexBuilder
{
    /**
     * Build all navigation indices used by the graph diff view.
     *
     * @param  array<int, array<string, mixed>>  $nodes  File nodes (path, id, ext, …)
     * @param  array<int, array{0: string, 1: string, 2: string, 3?: int|null}>  $edges  Dependency edges [source, target, type, line?]
     * @param  array<string, array<string, mixed>>  $metricsData  Per-file metrics (keyed by path)
     * @param  array<string, string>  $fileDiffs  Raw unified diffs (keyed by path)
     * @param  array<string, string>  $fileContents  Full file contents (keyed by path; optional)
     * @return array{
     *   methodNameIndex: array<string, string>,
     *   classNameIndex: array<string, string>,
     *   callersIndex: array<string, list<array{nodeId: string, line: int|null}>>,
     *   implementorsIndex: array<string, list<array{nodeId: string}>>,
     *   implementeeIndex: array<string, list<string>>,
     * }
     */
    public function build(
        array $nodes,
        array $edges,
        array $metricsData = [],
        array $fileDiffs = [],
        array $fileContents = [],
    ): array {
        $classNameIndex = $this->buildClassNameIndex($nodes);
        $methodNameIndex = $this->buildMethodNameIndex($nodes, $metricsData);
        $callersIndex = $this->buildCallersIndex($nodes, $classNameIndex, $fileDiffs, $fileContents);
        [$implementorsIndex, $implementeeIndex] = $this->buildImplementsIndices($nodes, $edges);

        return [
            'methodNameIndex' => $methodNameIndex,
            'classNameIndex' => $classNameIndex,
            'callersIndex' => $callersIndex,
            'implementorsIndex' => $implementorsIndex,
            'implementeeIndex' => $implementeeIndex,
        ];
    }

    /**
     * PHP UpperCamelCase file basename → node id.
     *
     * e.g. "app/Services/OrderService.php" → ["OrderService" => "app/Services/OrderService.php"]
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<string, string>
     */
    public function buildClassNameIndex(array $nodes): array
    {
        $index = [];

        foreach ($nodes as $node) {
            $path = $node['path'] ?? '';
            if (! str_ends_with($path, '.php')) {
                continue;
            }
            $basename = pathinfo($path, PATHINFO_FILENAME);
            if ($basename !== '' && ctype_upper($basename[0]) && ! isset($index[$basename])) {
                $index[$basename] = $node['id'];
            }
        }

        return $index;
    }

    /**
     * Method name → node id (first match wins on ambiguity).
     *
     * Source 1: code:file method nodes (nodes that have a 'code' property and 'name').
     * Source 2: metrics method_metrics lists (code:analyze, links to the file node).
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $metricsData
     * @return array<string, string>
     */
    public function buildMethodNameIndex(array $nodes, array $metricsData = []): array
    {
        $index = [];

        foreach ($nodes as $node) {
            if (array_key_exists('code', $node) && isset($node['name']) && ! isset($index[$node['name']])) {
                $index[$node['name']] = $node['id'];
            }
        }

        $pathToNodeId = [];
        foreach ($nodes as $node) {
            if (isset($node['path'])) {
                $pathToNodeId[$node['path']] = $node['id'];
            }
        }

        foreach ($metricsData as $path => $metrics) {
            $nodeId = $pathToNodeId[$path] ?? null;
            if ($nodeId === null) {
                continue;
            }
            foreach ($metrics['method_metrics'] ?? [] as $method) {
                if (isset($method['name']) && ! isset($index[$method['name']])) {
                    $index[$method['name']] = $nodeId;
                }
            }
        }

        return $index;
    }

    /**
     * Reverse caller index via type-hint resolution.
     *
     * callersIndex["targetNodeId:methodName"] = [{nodeId, line}, …]
     *
     * Scans PHP source of changed files for:
     *   - Static calls:   ClassName::method(
     *   - Instance calls: $prop->method(  (resolved via "TypeName $prop" type hints)
     *
     * When full file content is unavailable the diff context lines are used as a
     * best-effort fallback (line numbers are omitted in that case).
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, string>  $classNameIndex  Output of buildClassNameIndex()
     * @param  array<string, string>  $fileDiffs
     * @param  array<string, string>  $fileContents
     * @return array<string, list<array{nodeId: string, line: int|null}>>
     */
    public function buildCallersIndex(
        array $nodes,
        array $classNameIndex,
        array $fileDiffs = [],
        array $fileContents = [],
    ): array {
        $callersIndex = [];

        // TypeName $varName  — captures property/param type hints
        $typePat = '/([A-Z][a-zA-Z0-9_]*)\s+\$(\w+)/';
        // $this->prop->method(  or  $prop->method(
        $chainPat = '/\$(?:this->)?(\w+)->([a-zA-Z_]\w*)\s*\(/';
        // ClassName::method(  — static calls
        $staticPat = '/([A-Z][a-zA-Z0-9_]*)::([a-zA-Z_]\w*)\s*\(/';

        foreach ($nodes as $node) {
            $path = $node['path'] ?? '';
            if (! str_ends_with($path, '.php') || ! isset($fileDiffs[$path])) {
                continue;
            }

            $text = $fileContents[$path] ?? '';
            $hasFullContent = $text !== '';

            if (! $hasFullContent) {
                // Reconstruct from diff: keep context (+) lines and additions, skip deletions and hunk headers
                $text = implode("\n", array_map(
                    fn (string $line) => substr($line, 1),
                    array_filter(
                        explode("\n", $fileDiffs[$path]),
                        fn (string $line) => $line !== '' && $line[0] !== '-' && $line[0] !== '@',
                    ),
                ));
            }

            // Build property → class-node-id map from type hints in this file
            $propToClass = [];
            if (preg_match_all($typePat, $text, $typeMatches, PREG_SET_ORDER)) {
                foreach ($typeMatches as $tm) {
                    $typeName = $tm[1];
                    $propName = $tm[2];
                    if (isset($classNameIndex[$typeName]) && ! isset($propToClass[$propName])) {
                        $propToClass[$propName] = $classNameIndex[$typeName];
                    }
                }
            }

            $callerNodeId = $node['id'];
            $addCaller = function (string $targetNodeId, string $methodName, ?int $line) use (&$callersIndex, $callerNodeId): void {
                if ($targetNodeId === $callerNodeId) {
                    return;
                }
                $key = "{$targetNodeId}:{$methodName}";
                if (! isset($callersIndex[$key])) {
                    $callersIndex[$key] = [];
                }
                foreach ($callersIndex[$key] as $existing) {
                    if ($existing['nodeId'] === $callerNodeId) {
                        return;
                    }
                }
                $callersIndex[$key][] = ['nodeId' => $callerNodeId, 'line' => $line];
            };

            $lines = explode("\n", $text);
            foreach ($lines as $lineIndex => $lineText) {
                $lineNum = $hasFullContent ? $lineIndex + 1 : null;

                // Static calls
                if (preg_match_all($staticPat, $lineText, $staticMatches, PREG_SET_ORDER)) {
                    foreach ($staticMatches as $sm) {
                        $targetId = $classNameIndex[$sm[1]] ?? null;
                        if ($targetId !== null) {
                            $addCaller($targetId, $sm[2], $lineNum);
                        }
                    }
                }

                // Instance calls via typed properties
                if (preg_match_all($chainPat, $lineText, $chainMatches, PREG_SET_ORDER)) {
                    foreach ($chainMatches as $cm) {
                        $targetId = $propToClass[$cm[1]] ?? null;
                        if ($targetId !== null) {
                            $addCaller($targetId, $cm[2], $lineNum);
                        }
                    }
                }
            }
        }

        return $callersIndex;
    }

    /**
     * Implementors index (interface → implementors) and implementee index (concrete → interfaces).
     *
     * implementorsIndex["interfaceNodeId"] = [{nodeId}, …]
     * implementeeIndex["concreteNodeId"]   = ["interfaceNodeId", …]
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array{0: string, 1: string, 2: string, 3?: int|null}>  $edges
     * @return array{
     *   0: array<string, list<array{nodeId: string}>>,
     *   1: array<string, list<string>>,
     * }
     */
    public function buildImplementsIndices(array $nodes, array $edges): array
    {
        $knownIds = array_flip(array_column($nodes, 'id'));

        $implementorsIndex = [];
        $implementeeIndex = [];

        foreach ($edges as $edge) {
            [$source, $target, $type] = $edge;
            if ($type !== 'implements') {
                continue;
            }
            if (! isset($knownIds[$source]) || ! isset($knownIds[$target])) {
                continue;
            }

            // implementors: interface → [concrete implementors]
            if (! isset($implementorsIndex[$target])) {
                $implementorsIndex[$target] = [];
            }
            $alreadyIn = array_filter($implementorsIndex[$target], fn ($e) => $e['nodeId'] === $source);
            if (empty($alreadyIn)) {
                $implementorsIndex[$target][] = ['nodeId' => $source];
            }

            // implementee: concrete → [interfaces it implements]
            if (! isset($implementeeIndex[$source])) {
                $implementeeIndex[$source] = [];
            }
            if (! in_array($target, $implementeeIndex[$source], true)) {
                $implementeeIndex[$source][] = $target;
            }
        }

        return [$implementorsIndex, $implementeeIndex];
    }
}
