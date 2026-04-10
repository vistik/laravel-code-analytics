<?php

namespace Vistik\LaravelCodeAnalytics\Support;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\PatternBasedGroupResolver;

class MethodCallGraphExtractor
{
    private Parser $parser;

    private NodeFinder $finder;

    private PhpMethodMetricsCalculator $metricsCalc;

    private PatternBasedGroupResolver $groupResolver;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->finder = new NodeFinder;
        $this->metricsCalc = new PhpMethodMetricsCalculator;
        $this->groupResolver = new PatternBasedGroupResolver;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Extract method nodes and call edges from a single PHP file.
     * External callees that cannot be resolved appear as stub nodes (isConnected=true).
     *
     * @return array{nodes: list<array<string,mixed>>, edges: list<array{0:string,1:string,2:string}>}
     */
    public function extract(string $filePath, string $repoRoot = ''): array
    {
        $relPath = $this->relPath($filePath, $repoRoot);
        $result = $this->parseSingleFile($filePath, $relPath, focal: true, useFileGroup: false);

        $allNodes = array_values($result['nodes']);
        foreach ($result['externalStubs'] as $stub) {
            $allNodes[] = $stub;
        }

        return ['nodes' => $allNodes, 'edges' => $result['edges']];
    }

    /**
     * Recursively follow call dependencies starting from $entryFile.
     *
     * At depth=0 this behaves like extract(). At depth≥1 it resolves external
     * class names to their source files via Composer classmap / PSR-4 and parses
     * those files too, up to $depth hops away from the entry file.
     *
     * Nodes in the entry file are marked focal=true; all dependency file nodes
     * are focal=false. Unresolvable or vendor callees remain as stubs.
     *
     * @return array{nodes: list<array<string,mixed>>, edges: list<array{0:string,1:string,2:string}>}
     */
    public function extractRecursive(string $entryFile, string $repoRoot = '', int $depth = 1): array
    {
        if ($depth === 0) {
            return $this->extract($entryFile, $repoRoot);
        }

        $resolver = new ClassFileResolver($repoRoot);

        // BFS queue: [filePath, currentDepth, isFocal]
        $queue = [[$entryFile, 0, true]];
        /** @var array<string, true> $visited  realpath → seen */
        $visited = [];
        /** @var array<string, array<string,mixed>> $allNodes  methodId → node */
        $allNodes = [];
        /** @var array<string, array<string,mixed>> $stubNodes  methodId → stub node (placeholder until resolved) */
        $stubNodes = [];
        /** @var list<array{0:string,1:string,2:string}> $allEdges */
        $allEdges = [];
        $edgeSet = [];

        while (! empty($queue)) {
            [$filePath, $currentDepth, $isFocal] = array_shift($queue);

            $realPath = realpath($filePath);
            if ($realPath === false || isset($visited[$realPath])) {
                continue;
            }
            $visited[$realPath] = true;

            $relPath = $this->relPath($filePath, $repoRoot);
            $result = $this->parseSingleFile($filePath, $relPath, focal: $isFocal, useFileGroup: true);

            // Merge nodes — real parsed nodes replace stubs
            foreach ($result['nodes'] as $id => $node) {
                $allNodes[$id] = $node;
                unset($stubNodes[$id]); // no longer a stub
            }

            // Merge edges (dedup globally)
            foreach ($result['edges'] as $edge) {
                $key = $edge[0].'→'.$edge[1];
                if (! isset($edgeSet[$key])) {
                    $edgeSet[$key] = true;
                    $allEdges[] = $edge;
                }
            }

            // Collect stubs only for IDs we haven't resolved yet
            foreach ($result['externalStubs'] as $id => $stub) {
                if (! isset($allNodes[$id]) && ! isset($stubNodes[$id])) {
                    $stubNodes[$id] = $stub;
                }
            }

            // Follow dependency files if we haven't hit the depth limit
            if ($currentDepth < $depth) {
                foreach ($result['discoveredFqcns'] as $shortName => $fqcn) {
                    $depFile = $resolver->resolve($fqcn);
                    if ($depFile === null || $resolver->isVendor($depFile)) {
                        continue;
                    }
                    $depReal = realpath($depFile);
                    if ($depReal === false || isset($visited[$depReal])) {
                        continue;
                    }
                    $queue[] = [$depFile, $currentDepth + 1, false];
                }
            }
        }

        // Any stubs that were never replaced by real nodes go in as isConnected nodes
        $finalNodes = array_values($allNodes);
        foreach ($stubNodes as $stub) {
            $finalNodes[] = $stub;
        }

        // Remove non-focal nodes that have no edges (parsed from dependency files
        // but never actually called or calling anything in this graph).
        $connectedIds = [];
        foreach ($allEdges as [$src, $dst]) {
            $connectedIds[$src] = true;
            $connectedIds[$dst] = true;
        }
        $finalNodes = array_values(array_filter(
            $finalNodes,
            fn ($n) => $n['focal'] || isset($connectedIds[$n['id']]),
        ));

        return ['nodes' => $finalNodes, 'edges' => $allEdges];
    }

    /**
     * Palette used to assign a stable, distinct color to each file in multi-file mode.
     * Chosen to be visually distinguishable on the dark canvas background.
     */
    private const FILE_PALETTE = [
        '#79c0ff', // blue
        '#3fb950', // green
        '#f0883e', // orange
        '#d2a8ff', // lavender
        '#e3b341', // yellow
        '#f778ba', // pink
        '#56d4dd', // cyan
        '#7ee787', // mint
        '#ffa657', // amber
        '#8957e5', // purple
        '#f97583', // rose
        '#58a6ff', // sky
    ];

    private function fileColor(string $relPath): string
    {
        $idx = abs(crc32($relPath)) % count(self::FILE_PALETTE);

        return self::FILE_PALETTE[$idx];
    }

    // ── Core file parser ──────────────────────────────────────────────────────

    /**
     * Parse a single file and return:
     *  - nodes:          methodId → node array (all methods in this file)
     *  - edges:          call edges within/from this file
     *  - externalStubs:  methodId → stub node for unresolved callees
     *  - discoveredFqcns: shortName → FQCN for every external class referenced
     *
     * @return array{
     *   nodes: array<string,array<string,mixed>>,
     *   edges: list<array{0:string,1:string,2:string}>,
     *   externalStubs: array<string,array<string,mixed>>,
     *   discoveredFqcns: array<string,string>,
     * }
     */
    private function parseSingleFile(
        string $filePath,
        string $relPath,
        bool $focal,
        bool $useFileGroup,
    ): array {
        $content = @file_get_contents($filePath);
        if ($content === false || $content === '') {
            return ['nodes' => [], 'edges' => [], 'externalStubs' => [], 'discoveredFqcns' => []];
        }

        $errors = new Collecting;
        $ast = $this->parser->parse($content, $errors);
        if ($ast === null) {
            return ['nodes' => [], 'edges' => [], 'externalStubs' => [], 'discoveredFqcns' => []];
        }

        $fileLines = explode("\n", $content);

        $metricsResult = $this->metricsCalc->calculate([$relPath => $content]);
        $metricsMap = [];
        foreach ($metricsResult[$relPath] ?? [] as $m) {
            $metricsMap[$m->name] = $m;
        }

        /** @var list<Stmt\Class_|Stmt\Trait_|Stmt\Interface_|Stmt\Enum_> $classLikes */
        $classLikes = [
            ...$this->finder->findInstanceOf($ast, Stmt\Class_::class),
            ...$this->finder->findInstanceOf($ast, Stmt\Trait_::class),
            ...$this->finder->findInstanceOf($ast, Stmt\Interface_::class),
            ...$this->finder->findInstanceOf($ast, Stmt\Enum_::class),
        ];

        $useMap = $this->buildUseMap($ast);
        $namespace = $this->getNamespace($ast);
        $fileGroup = $useFileGroup ? $this->groupResolver->resolve($relPath)->value : null;
        $fileColor = $useFileGroup ? $this->fileColor($relPath) : null;

        // First pass: all method IDs defined in this file
        $fileMethodIds = [];
        foreach ($classLikes as $classLike) {
            $cn = $classLike->name?->toString() ?? 'anonymous';
            foreach ($classLike->getMethods() as $m) {
                $fileMethodIds[] = $cn.'::'.$m->name->toString();
            }
        }

        $nodes = [];
        $edges = [];
        $externalStubs = [];
        $discoveredFqcns = [];
        $edgeSet = [];

        foreach ($classLikes as $classLike) {
            $className = $classLike->name?->toString() ?? 'anonymous';
            $propTypeMap = $this->buildPropTypeMap($classLike, $useMap);

            foreach ($classLike->getMethods() as $method) {
                $methodName = $method->name->toString();
                $methodId = $className.'::'.$methodName;
                $visibility = $this->getVisibility($method);
                $metrics = $metricsMap[$methodName] ?? null;
                $cc = $metrics !== null ? $metrics->cc : 1;
                $params = count($method->params);

                $group = $fileGroup ?? 'vis_'.$visibility;

                $startLine = max(1, $method->getStartLine());
                $endLine = max($startLine, $method->getEndLine());
                $methodCode = implode("\n", array_slice($fileLines, $startLine - 1, $endLine - $startLine + 1));

                $node = [
                    'id' => $methodId,
                    'class' => $className,
                    'file' => $relPath,
                    'visibility' => $visibility,
                    'line' => $startLine,
                    'endLine' => $endLine,
                    'code' => $methodCode,
                    'focal' => $focal,
                    'cc' => $cc,
                    'params' => $params,
                    'isConnected' => false,
                    'add' => max(1, $cc),
                    'del' => $params,
                    'group' => $group,
                    'status' => 'modified',
                    'ext' => 'php',
                    'domain' => $this->shortPathLabel($relPath),
                    'displayLabel' => $methodName,
                    'folder' => $this->shortPathLabel($relPath),
                    'name' => $methodName,
                    'path' => $relPath,
                    'severity' => null,
                ];
                if ($fileColor !== null) {
                    $node['domainColor'] = $fileColor;
                }
                $nodes[$methodId] = $node;

                if ($method->stmts === null) {
                    continue;
                }

                // Merge class-level property map with per-method local `new` assignments
                $combinedTypeMap = array_merge($propTypeMap, $this->buildLocalVarTypeMap($method));

                $this->extractCallEdges(
                    method: $method,
                    callerClass: $className,
                    callerId: $methodId,
                    fileMethodIds: $fileMethodIds,
                    propTypeMap: $combinedTypeMap,
                    useMap: $useMap,
                    namespace: $namespace,
                    fileGroup: $fileGroup,
                    edges: $edges,
                    externalStubs: $externalStubs,
                    discoveredFqcns: $discoveredFqcns,
                    edgeSet: $edgeSet,
                );
            }
        }

        return compact('nodes', 'edges', 'externalStubs', 'discoveredFqcns');
    }

    // ── Call extraction ──────────────────────────────────────────────────────

    /**
     * @param  list<string>  $fileMethodIds
     * @param  array<string,string>  $propTypeMap  propName → shortClassName
     * @param  array<string,string>  $useMap       shortName → FQCN
     * @param  list<array{0:string,1:string,2:string,3:int}>  $edges
     * @param  array<string,array<string,mixed>>  $externalStubs
     * @param  array<string,string>  $discoveredFqcns  shortName → FQCN
     * @param  array<string,true>  $edgeSet
     */
    private function extractCallEdges(
        Stmt\ClassMethod $method,
        string $callerClass,
        string $callerId,
        array $fileMethodIds,
        array $propTypeMap,
        array $useMap,
        string $namespace,
        ?string $fileGroup,
        array &$edges,
        array &$externalStubs,
        array &$discoveredFqcns,
        array &$edgeSet,
    ): void {
        // ── $this->foo()  /  $this->prop->foo() ─────────────────────────────
        /** @var list<Expr\MethodCall> $methodCalls */
        $methodCalls = $this->finder->findInstanceOf([$method], Expr\MethodCall::class);
        foreach ($methodCalls as $call) {
            if (! ($call->name instanceof Node\Identifier)) {
                continue;
            }
            $calleeName = $call->name->toString();

            if ($call->var instanceof Expr\Variable && $call->var->name === 'this') {
                // $this->foo()
                $this->addEdge(
                    callerId: $callerId,
                    calleeId: $callerClass.'::'.$calleeName,
                    callType: 'this_call',
                    calleeClass: $callerClass,
                    calleeName: $calleeName,
                    calleeFqcn: null,
                    callLine: $call->getStartLine(),
                    fileMethodIds: $fileMethodIds,
                    fileGroup: $fileGroup,
                    edges: $edges,
                    externalStubs: $externalStubs,
                    discoveredFqcns: $discoveredFqcns,
                    edgeSet: $edgeSet,
                );
            } elseif (
                $call->var instanceof Expr\PropertyFetch
                && $call->var->var instanceof Expr\Variable
                && $call->var->var->name === 'this'
                && $call->var->name instanceof Node\Identifier
            ) {
                // $this->prop->foo()
                $propName = $call->var->name->toString();
                if (isset($propTypeMap[$propName])) {
                    $calleeClass = $propTypeMap[$propName];
                    $fqcn = $this->resolveFqcn($calleeClass, $useMap, $namespace);
                    $this->addEdge(
                        callerId: $callerId,
                        calleeId: $calleeClass.'::'.$calleeName,
                        callType: 'external_call',
                        calleeClass: $calleeClass,
                        calleeName: $calleeName,
                        calleeFqcn: $fqcn,
                        callLine: $call->getStartLine(),
                        fileMethodIds: $fileMethodIds,
                        fileGroup: $fileGroup,
                        edges: $edges,
                        externalStubs: $externalStubs,
                        discoveredFqcns: $discoveredFqcns,
                        edgeSet: $edgeSet,
                    );
                }
            } elseif ($call->var instanceof Expr\Variable && is_string($call->var->name)) {
                // $localVar->foo()
                $propName = $call->var->name;
                if (isset($propTypeMap[$propName])) {
                    $calleeClass = $propTypeMap[$propName];
                    $fqcn = $this->resolveFqcn($calleeClass, $useMap, $namespace);
                    $this->addEdge(
                        callerId: $callerId,
                        calleeId: $calleeClass.'::'.$calleeName,
                        callType: 'external_call',
                        calleeClass: $calleeClass,
                        calleeName: $calleeName,
                        calleeFqcn: $fqcn,
                        callLine: $call->getStartLine(),
                        fileMethodIds: $fileMethodIds,
                        fileGroup: $fileGroup,
                        edges: $edges,
                        externalStubs: $externalStubs,
                        discoveredFqcns: $discoveredFqcns,
                        edgeSet: $edgeSet,
                    );
                }
            }
        }

        // ── self::foo() / static::foo() / ClassName::foo() ──────────────────
        /** @var list<Expr\StaticCall> $staticCalls */
        $staticCalls = $this->finder->findInstanceOf([$method], Expr\StaticCall::class);
        foreach ($staticCalls as $call) {
            if (! ($call->name instanceof Node\Identifier)) {
                continue;
            }
            $calleeName = $call->name->toString();

            if (! ($call->class instanceof Node\Name)) {
                continue;
            }
            $classStr = $call->class->toString();

            if (in_array($classStr, ['self', 'static', 'parent'], true)) {
                $this->addEdge(
                    callerId: $callerId,
                    calleeId: $callerClass.'::'.$calleeName,
                    callType: 'this_call',
                    calleeClass: $callerClass,
                    calleeName: $calleeName,
                    calleeFqcn: null,
                    callLine: $call->getStartLine(),
                    fileMethodIds: $fileMethodIds,
                    fileGroup: $fileGroup,
                    edges: $edges,
                    externalStubs: $externalStubs,
                    discoveredFqcns: $discoveredFqcns,
                    edgeSet: $edgeSet,
                );
            } else {
                $calleeClass = $call->class->getLast();
                $fqcn = $this->resolveFqcn($classStr, $useMap, $namespace);
                $this->addEdge(
                    callerId: $callerId,
                    calleeId: $calleeClass.'::'.$calleeName,
                    callType: 'static_call',
                    calleeClass: $calleeClass,
                    calleeName: $calleeName,
                    calleeFqcn: $fqcn,
                    callLine: $call->getStartLine(),
                    fileMethodIds: $fileMethodIds,
                    fileGroup: $fileGroup,
                    edges: $edges,
                    externalStubs: $externalStubs,
                    discoveredFqcns: $discoveredFqcns,
                    edgeSet: $edgeSet,
                );
            }
        }
    }

    /**
     * @param  list<string>  $fileMethodIds
     * @param  array<string,array<string,mixed>>  $externalStubs
     * @param  array<string,string>  $discoveredFqcns
     * @param  list<array{0:string,1:string,2:string,3:int}>  $edges
     * @param  array<string,true>  $edgeSet
     */
    private function addEdge(
        string $callerId,
        string $calleeId,
        string $callType,
        string $calleeClass,
        string $calleeName,
        ?string $calleeFqcn,
        int $callLine,
        array $fileMethodIds,
        ?string $fileGroup,
        array &$edges,
        array &$externalStubs,
        array &$discoveredFqcns,
        array &$edgeSet,
    ): void {
        if ($calleeId === $callerId) {
            return;
        }
        $key = $callerId.'→'.$calleeId;
        if (isset($edgeSet[$key])) {
            return;
        }
        $edgeSet[$key] = true;

        // Track FQCN for recursive file resolution
        if ($calleeFqcn !== null && ! isset($discoveredFqcns[$calleeClass])) {
            $discoveredFqcns[$calleeClass] = $calleeFqcn;
        }

        // Create a stub node if the callee isn't in the current file
        if (! in_array($calleeId, $fileMethodIds, true) && ! isset($externalStubs[$calleeId])) {
            $externalStubs[$calleeId] = [
                'id' => $calleeId,
                'class' => $calleeClass,
                'file' => '',
                'visibility' => 'public',
                'line' => 0,
                'focal' => false,
                'cc' => 0,
                'params' => 0,
                'isConnected' => true,
                'add' => 0,
                'del' => 0,
                'group' => $fileGroup ?? 'vis_external',
                'status' => 'modified',
                'ext' => 'php',
                'domain' => $calleeClass,
                'displayLabel' => $calleeName,
                'folder' => $calleeClass,
                'name' => $calleeName,
                'path' => '',
                'severity' => null,
            ];
        }

        $edges[] = [$callerId, $calleeId, $callType, $callLine];
    }

    // ── AST helpers ──────────────────────────────────────────────────────────

    /**
     * Build propName → shortClassName map from constructor params + assignments.
     * Handles: promoted params, nullable types, `$this->p = $arg`, `$this->p = new Foo()`,
     * and `$this->p = $arg ?? new Foo()`.
     *
     * @param  Stmt\Class_|Stmt\Trait_|Stmt\Interface_|Stmt\Enum_  $classLike
     * @param  array<string,string>  $useMap
     * @return array<string,string>
     */
    private function buildPropTypeMap(Stmt\Class_|Stmt\Trait_|Stmt\Interface_|Stmt\Enum_ $classLike, array $useMap): array
    {
        $map = []; // paramOrPropName → shortClassName

        foreach ($classLike->getMethods() as $method) {
            if ($method->name->toString() !== '__construct') {
                continue;
            }

            // Capture all typed params (promoted or not, including nullable)
            foreach ($method->params as $param) {
                $type = $param->type;
                if ($type instanceof Node\NullableType) {
                    $type = $type->type; // unwrap ?Foo → Foo
                }
                if ($type instanceof Node\Name && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    $map[$param->var->name] = $type->getLast();
                }
            }

            if ($method->stmts === null) {
                break;
            }

            /** @var list<Expr\Assign> $assigns */
            $assigns = $this->finder->findInstanceOf($method->stmts, Expr\Assign::class);
            foreach ($assigns as $assign) {
                if (
                    ! ($assign->var instanceof Expr\PropertyFetch)
                    || ! ($assign->var->var instanceof Expr\Variable && $assign->var->var->name === 'this')
                    || ! ($assign->var->name instanceof Node\Identifier)
                ) {
                    continue;
                }
                $propName = $assign->var->name->toString();

                // $this->prop = $param
                if ($assign->expr instanceof Expr\Variable && is_string($assign->expr->name)) {
                    $paramName = $assign->expr->name;
                    if (isset($map[$paramName])) {
                        $map[$propName] = $map[$paramName];
                    }
                }

                // $this->prop = new Foo(...)
                if ($assign->expr instanceof Expr\New_ && $assign->expr->class instanceof Node\Name) {
                    $map[$propName] = $assign->expr->class->getLast();
                }

                // $this->prop = $param ?? new Foo(...)
                if (
                    $assign->expr instanceof Expr\BinaryOp\Coalesce
                    && $assign->expr->right instanceof Expr\New_
                    && $assign->expr->right->class instanceof Node\Name
                ) {
                    $map[$propName] = $assign->expr->right->class->getLast();
                }
            }

            break;
        }

        return $map;
    }

    /**
     * Build a map of local variable names → short class names for a single method body.
     * Captures: `$var = new ClassName(...)`.
     *
     * @return array<string,string>
     */
    private function buildLocalVarTypeMap(Stmt\ClassMethod $method): array
    {
        if ($method->stmts === null) {
            return [];
        }

        $map = [];

        /** @var list<Expr\Assign> $assigns */
        $assigns = $this->finder->findInstanceOf($method->stmts, Expr\Assign::class);
        foreach ($assigns as $assign) {
            if (! ($assign->var instanceof Expr\Variable) || ! is_string($assign->var->name)) {
                continue;
            }
            $varName = $assign->var->name;

            // $var = new Foo(...)
            if ($assign->expr instanceof Expr\New_ && $assign->expr->class instanceof Node\Name) {
                $map[$varName] = $assign->expr->class->getLast();
            }
        }

        return $map;
    }

    /**
     * Build use-statement alias map: shortName → FQCN.
     *
     * @param  list<Node>  $ast
     * @return array<string,string>
     */
    private function buildUseMap(array $ast): array
    {
        $map = [];
        /** @var list<Stmt\UseUse> $useUses */
        $useUses = $this->finder->findInstanceOf($ast, Stmt\UseUse::class);
        foreach ($useUses as $use) {
            $alias = $use->alias?->toString() ?? $use->name->getLast();
            $map[$alias] = $use->name->toString();
        }

        return $map;
    }

    /**
     * Resolve a class name (possibly short) to a FQCN.
     * Returns null for PHP built-ins (int, string, array, etc.).
     *
     * @param  array<string,string>  $useMap
     */
    private function resolveFqcn(string $name, array $useMap, string $namespace): ?string
    {
        // Already fully qualified
        if (str_contains($name, '\\')) {
            return ltrim($name, '\\');
        }

        // Explicitly imported
        if (isset($useMap[$name])) {
            return $useMap[$name];
        }

        // PHP built-in types — not a class
        static $builtins = ['self', 'static', 'parent', 'int', 'float', 'string', 'bool',
            'array', 'callable', 'iterable', 'object', 'void', 'null', 'never', 'mixed',
            'true', 'false'];
        if (in_array(strtolower($name), $builtins, true)) {
            return null;
        }

        // Assume same namespace
        return $namespace !== '' ? $namespace.'\\'.$name : $name;
    }

    private function getNamespace(array $ast): string
    {
        /** @var list<Stmt\Namespace_> $ns */
        $ns = $this->finder->findInstanceOf($ast, Stmt\Namespace_::class);

        return isset($ns[0]) ? ($ns[0]->name?->toString() ?? '') : '';
    }

    private function getVisibility(Stmt\ClassMethod $method): string
    {
        if ($method->isPrivate()) {
            return 'private';
        }
        if ($method->isProtected()) {
            return 'protected';
        }

        return 'public';
    }

    /**
     * Returns a short display label derived from a relative file path.
     * Shows at most the last two path segments without the .php extension.
     * e.g. "src/Actions/AnalyzeCode.php" → "Actions/AnalyzeCode"
     */
    private function shortPathLabel(string $relPath): string
    {
        $parts = array_values(array_filter(explode('/', $relPath)));
        $base = basename($relPath, '.php');
        if (count($parts) >= 2) {
            return $parts[count($parts) - 2].'/'.$base;
        }

        return $base;
    }

    private function relPath(string $filePath, string $repoRoot): string
    {
        return $repoRoot !== ''
            ? ltrim(str_replace(rtrim($repoRoot, '/'), '', $filePath), '/')
            : $filePath;
    }
}
