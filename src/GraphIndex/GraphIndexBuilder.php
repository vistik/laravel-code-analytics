<?php

namespace Vistik\LaravelCodeAnalytics\GraphIndex;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

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

        $parser = (new ParserFactory)->createForHostVersion();
        $nodeFinder = new NodeFinder;

        // Regex patterns used as fallback when only a diff is available (no full content).
        $typePat = '/([A-Z][a-zA-Z0-9_]*)\s+\$(\w+)/';
        $chainPat = '/\$(?:this->)?(\w+)->([a-zA-Z_]\w*)\s*\(/';
        $staticPat = '/([A-Z][a-zA-Z0-9_]*)::([a-zA-Z_]\w*)\s*\(/';

        foreach ($nodes as $node) {
            $path = $node['path'] ?? '';
            if (! str_ends_with($path, '.php') || ! isset($fileDiffs[$path])) {
                continue;
            }

            $text = $fileContents[$path] ?? '';
            $hasFullContent = $text !== '';

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

            if ($hasFullContent) {
                // AST-based extraction for files with full content
                $errors = new Collecting;
                $ast = $parser->parse($text, $errors);
                if ($ast === null) {
                    continue;
                }

                // Build alias → short class name map for resolving imported names
                $useAliasMap = [];
                foreach ($nodeFinder->findInstanceOf($ast, Stmt\UseUse::class) as $use) {
                    $alias = $use->alias?->toString() ?? $use->name->getLast();
                    $useAliasMap[$alias] = $use->name->getLast();
                }

                $classLikes = [
                    ...$nodeFinder->findInstanceOf($ast, Stmt\Class_::class),
                    ...$nodeFinder->findInstanceOf($ast, Stmt\Trait_::class),
                ];

                foreach ($classLikes as $classLike) {
                    // Build property type map: propName → short class name
                    $propTypeMap = [];

                    // From typed class-level property declarations
                    foreach ($classLike->stmts as $stmt) {
                        if (! ($stmt instanceof Stmt\Property)) {
                            continue;
                        }
                        $propType = $stmt->type;
                        if ($propType instanceof Node\NullableType) {
                            $propType = $propType->type;
                        }
                        if (! ($propType instanceof Node\Name)) {
                            continue;
                        }
                        $shortClass = $propType->getLast();
                        foreach ($stmt->props as $prop) {
                            $propTypeMap[$prop->name->toString()] ??= $shortClass;
                        }
                    }

                    // From constructor parameters and assignments
                    foreach ($classLike->getMethods() as $method) {
                        if ($method->name->toString() !== '__construct') {
                            continue;
                        }
                        foreach ($method->params as $param) {
                            $paramType = $param->type;
                            if ($paramType instanceof Node\NullableType) {
                                $paramType = $paramType->type;
                            }
                            if ($paramType instanceof Node\Name && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                                $propTypeMap[$param->var->name] = $paramType->getLast();
                            }
                        }
                        foreach ($nodeFinder->findInstanceOf($method->stmts ?? [], Expr\Assign::class) as $assign) {
                            if (
                                ! ($assign->var instanceof Expr\PropertyFetch)
                                || ! ($assign->var->var instanceof Expr\Variable && $assign->var->var->name === 'this')
                                || ! ($assign->var->name instanceof Node\Identifier)
                            ) {
                                continue;
                            }
                            $propName = $assign->var->name->toString();
                            if ($assign->expr instanceof Expr\Variable && is_string($assign->expr->name) && isset($propTypeMap[$assign->expr->name])) {
                                $propTypeMap[$propName] = $propTypeMap[$assign->expr->name];
                            }
                            if ($assign->expr instanceof Expr\New_ && $assign->expr->class instanceof Node\Name) {
                                $propTypeMap[$propName] = $assign->expr->class->getLast();
                            }
                        }
                        break;
                    }

                    // Build method return type map for resolving $var = $this->method()
                    $returnTypeMap = [];
                    foreach ($classLike->getMethods() as $method) {
                        $returnType = $method->returnType;
                        if ($returnType instanceof Node\NullableType) {
                            $returnType = $returnType->type;
                        }
                        if ($returnType instanceof Node\Name) {
                            $returnTypeMap[$method->name->toString()] = $returnType->getLast();
                        }
                    }

                    foreach ($classLike->getMethods() as $method) {
                        if ($method->stmts === null) {
                            continue;
                        }

                        // Build local variable type map for this method
                        $localVarMap = $propTypeMap;
                        foreach ($nodeFinder->findInstanceOf($method->stmts, Expr\Assign::class) as $assign) {
                            if (! ($assign->var instanceof Expr\Variable) || ! is_string($assign->var->name)) {
                                continue;
                            }
                            $varName = $assign->var->name;

                            if ($assign->expr instanceof Expr\New_ && $assign->expr->class instanceof Node\Name) {
                                $localVarMap[$varName] = $assign->expr->class->getLast();
                            }

                            // $var = $this->someMethod() — resolved via return type map
                            if (
                                $assign->expr instanceof Expr\MethodCall
                                && $assign->expr->var instanceof Expr\Variable
                                && $assign->expr->var->name === 'this'
                                && $assign->expr->name instanceof Node\Identifier
                            ) {
                                $calledMethod = $assign->expr->name->toString();
                                if (isset($returnTypeMap[$calledMethod])) {
                                    $localVarMap[$varName] = $returnTypeMap[$calledMethod];
                                }
                            }

                            // $var = app(Foo::class) / resolve(Foo::class)
                            if (
                                $assign->expr instanceof Expr\FuncCall
                                && $assign->expr->name instanceof Node\Name
                                && in_array($assign->expr->name->toLowerString(), ['app', 'resolve'], true)
                                && isset($assign->expr->args[0])
                                && $assign->expr->args[0] instanceof Node\Arg
                            ) {
                                $classConst = $assign->expr->args[0]->value;
                                if (
                                    $classConst instanceof Expr\ClassConstFetch
                                    && $classConst->class instanceof Node\Name
                                    && $classConst->name instanceof Node\Identifier
                                    && $classConst->name->toString() === 'class'
                                ) {
                                    $localVarMap[$varName] = $classConst->class->getLast();
                                }
                            }

                            // $var = $container->make(Foo::class) / app()->make(Foo::class)
                            if (
                                $assign->expr instanceof Expr\MethodCall
                                && $assign->expr->name instanceof Node\Identifier
                                && $assign->expr->name->toString() === 'make'
                                && isset($assign->expr->args[0])
                                && $assign->expr->args[0] instanceof Node\Arg
                            ) {
                                $classConst = $assign->expr->args[0]->value;
                                if (
                                    $classConst instanceof Expr\ClassConstFetch
                                    && $classConst->class instanceof Node\Name
                                    && $classConst->name instanceof Node\Identifier
                                    && $classConst->name->toString() === 'class'
                                ) {
                                    $localVarMap[$varName] = $classConst->class->getLast();
                                }
                            }
                        }

                        // Extract instance method calls
                        foreach ($nodeFinder->findInstanceOf([$method], Expr\MethodCall::class) as $call) {
                            if (! ($call->name instanceof Node\Identifier)) {
                                continue;
                            }
                            $calleeName = $call->name->toString();

                            $shortClass = null;
                            if ($call->var instanceof Expr\Variable && is_string($call->var->name) && $call->var->name !== 'this') {
                                $shortClass = $localVarMap[$call->var->name] ?? null;
                            } elseif (
                                $call->var instanceof Expr\PropertyFetch
                                && $call->var->var instanceof Expr\Variable
                                && $call->var->var->name === 'this'
                                && $call->var->name instanceof Node\Identifier
                            ) {
                                $shortClass = $localVarMap[$call->var->name->toString()] ?? null;
                            } elseif (
                                $call->var instanceof Expr\MethodCall
                                && $call->var->var instanceof Expr\Variable
                                && $call->var->var->name === 'this'
                                && $call->var->name instanceof Node\Identifier
                            ) {
                                // $this->getService()->process()
                                $innerMethod = $call->var->name->toString();
                                $shortClass = $returnTypeMap[$innerMethod] ?? null;
                            }

                            if ($shortClass !== null) {
                                $resolved = $useAliasMap[$shortClass] ?? $shortClass;
                                $targetNodeId = $classNameIndex[$resolved] ?? null;
                                if ($targetNodeId !== null) {
                                    $addCaller($targetNodeId, $calleeName, $call->getStartLine());
                                }
                            }
                        }

                        // Extract static calls
                        foreach ($nodeFinder->findInstanceOf([$method], Expr\StaticCall::class) as $call) {
                            if (
                                ! ($call->name instanceof Node\Identifier)
                                || ! ($call->class instanceof Node\Name)
                            ) {
                                continue;
                            }
                            $classStr = $call->class->getLast();
                            if (in_array($classStr, ['self', 'static', 'parent'], true)) {
                                continue;
                            }
                            $resolved = $useAliasMap[$classStr] ?? $classStr;
                            $targetNodeId = $classNameIndex[$resolved] ?? null;
                            if ($targetNodeId !== null) {
                                $addCaller($targetNodeId, $call->name->toString(), $call->getStartLine());
                            }
                        }
                    }
                }

                continue;
            }

            // Fallback: reconstruct from diff when full content is unavailable (no line numbers)
            $text = implode("\n", array_map(
                fn (string $line) => substr($line, 1),
                array_filter(
                    explode("\n", $fileDiffs[$path]),
                    fn (string $line) => $line !== '' && $line[0] !== '-' && $line[0] !== '@',
                ),
            ));

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

            foreach (explode("\n", $text) as $lineText) {
                if (preg_match_all($staticPat, $lineText, $staticMatches, PREG_SET_ORDER)) {
                    foreach ($staticMatches as $sm) {
                        $targetId = $classNameIndex[$sm[1]] ?? null;
                        if ($targetId !== null) {
                            $addCaller($targetId, $sm[2], null);
                        }
                    }
                }
                if (preg_match_all($chainPat, $lineText, $chainMatches, PREG_SET_ORDER)) {
                    foreach ($chainMatches as $cm) {
                        $targetId = $propToClass[$cm[1]] ?? null;
                        if ($targetId !== null) {
                            $addCaller($targetId, $cm[2], null);
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
            [$source, $target] = $edge;
            $type = $edge[2] ?? null;
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
