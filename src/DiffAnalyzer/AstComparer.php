<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

class AstComparer
{
    private Parser $parser;

    private NodeFinder $finder;

    private Standard $printer;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->finder = new NodeFinder;
        $this->printer = new Standard;
    }

    /**
     * Compare old and new PHP source code and return structural changes.
     *
     * @return array{
     *     ast_identical: bool,
     *     old_nodes: ?array<int, Node>,
     *     new_nodes: ?array<int, Node>,
     *     old_source: ?string,
     *     new_source: ?string,
     *     classes: array<string, array{old: ?Stmt\Class_, new: ?Stmt\Class_}>,
     *     functions: array<string, array{old: ?Stmt\Function_, new: ?Stmt\Function_}>,
     *     methods: array<string, array{old: ?Stmt\ClassMethod, new: ?Stmt\ClassMethod}>,
     *     properties: array<string, array{old: ?Stmt\Property, new: ?Stmt\Property}>,
     *     class_constants: array<string, array{old: ?Stmt\ClassConst, new: ?Stmt\ClassConst}>,
     *     use_statements: array{old: list<Stmt\Use_>, new: list<Stmt\Use_>},
     *     old_parse_errors: list<string>,
     *     new_parse_errors: list<string>,
     * }
     */
    public function compare(?string $oldSource, ?string $newSource): array
    {
        $oldErrors = new Collecting;
        $newErrors = new Collecting;

        $oldNodes = $oldSource !== null ? $this->parser->parse($oldSource, $oldErrors) : null;
        $newNodes = $newSource !== null ? $this->parser->parse($newSource, $newErrors) : null;

        $astIdentical = $this->areAstIdentical($oldNodes, $newNodes);

        $classes = $this->matchByName(
            $this->findNodes($oldNodes, Stmt\Class_::class),
            $this->findNodes($newNodes, Stmt\Class_::class),
        );
        $interfaces = $this->matchByName(
            $this->findNodes($oldNodes, Stmt\Interface_::class),
            $this->findNodes($newNodes, Stmt\Interface_::class),
        );
        $enums = $this->matchByName(
            $this->findNodes($oldNodes, Stmt\Enum_::class),
            $this->findNodes($newNodes, Stmt\Enum_::class),
        );

        // Build a map of old class name -> new class name for renames
        $classRenames = $this->buildRenameMap($classes, $interfaces, $enums);

        return [
            'ast_identical' => $astIdentical,
            'old_nodes' => $oldNodes,
            'new_nodes' => $newNodes,
            'old_source' => $oldSource,
            'new_source' => $newSource,
            'classes' => $classes,
            'interfaces' => $interfaces,
            'enums' => $enums,
            'functions' => $this->matchByName(
                $this->findNodes($oldNodes, Stmt\Function_::class),
                $this->findNodes($newNodes, Stmt\Function_::class),
            ),
            'methods' => $this->matchMethods($oldNodes, $newNodes, $classRenames),
            'properties' => $this->matchProperties($oldNodes, $newNodes, $classRenames),
            'class_constants' => $this->matchClassConstants($oldNodes, $newNodes, $classRenames),
            'use_statements' => [
                'old' => $oldNodes ? $this->findNodes($oldNodes, Stmt\Use_::class) : [],
                'new' => $newNodes ? $this->findNodes($newNodes, Stmt\Use_::class) : [],
            ],
            'old_parse_errors' => array_map(fn ($e) => $e->getMessage(), $oldErrors->getErrors()),
            'new_parse_errors' => array_map(fn ($e) => $e->getMessage(), $newErrors->getErrors()),
        ];
    }

    /**
     * Check if two AST arrays are structurally identical (ignoring position attributes).
     *
     * @param  ?array<int, Node>  $oldNodes
     * @param  ?array<int, Node>  $newNodes
     */
    public function areAstIdentical(?array $oldNodes, ?array $newNodes): bool
    {
        if ($oldNodes === null || $newNodes === null) {
            return $oldNodes === $newNodes;
        }

        return $this->hashNodes($oldNodes) === $this->hashNodes($newNodes);
    }

    /**
     * Produce a structural hash of a node (or array of nodes), ignoring line numbers and formatting.
     *
     * @param  Node|list<Node>  $nodeOrNodes
     */
    public function hashNodes(Node|array $nodeOrNodes): string
    {
        if (is_array($nodeOrNodes)) {
            $parts = array_map(fn (Node $n) => $this->hashNodes($n), $nodeOrNodes);

            return md5(implode('|', $parts));
        }

        return md5($this->printer->prettyPrint([$nodeOrNodes]));
    }

    /**
     * Hash a single node's subtree for structural comparison.
     */
    public function hashNode(Node $node): string
    {
        return $this->hashNodes($node);
    }

    /**
     * Find all nodes of a given type in an AST.
     *
     * @template T of Node
     *
     * @param  ?array<int, Node>  $nodes
     * @param  class-string<T>  $type
     * @return list<T>
     */
    private function findNodes(?array $nodes, string $type): array
    {
        if ($nodes === null) {
            return [];
        }

        return $this->finder->findInstanceOf($nodes, $type);
    }

    /**
     * Match named declarations between old and new ASTs.
     * Detects renames by comparing structural similarity of unmatched declarations.
     *
     * @template T of Node
     *
     * @param  list<T>  $oldDecls
     * @param  list<T>  $newDecls
     * @return array<string, array{old: ?T, new: ?T, renamed_from?: string}>
     */
    private function matchByName(array $oldDecls, array $newDecls): array
    {
        $matched = [];

        $oldByName = [];
        foreach ($oldDecls as $decl) {
            $name = $this->getNodeName($decl);
            if ($name !== null) {
                $oldByName[$name] = $decl;
            }
        }

        $newByName = [];
        foreach ($newDecls as $decl) {
            $name = $this->getNodeName($decl);
            if ($name !== null) {
                $newByName[$name] = $decl;
            }
        }

        // First pass: exact name matches
        $matchedNames = array_intersect(array_keys($oldByName), array_keys($newByName));
        foreach ($matchedNames as $name) {
            $matched[$name] = [
                'old' => $oldByName[$name],
                'new' => $newByName[$name],
            ];
        }

        // Collect unmatched
        $unmatchedOld = array_diff_key($oldByName, $matched);
        $unmatchedNew = array_diff_key($newByName, $matched);

        // Second pass: detect renames by structural similarity
        $renames = $this->detectRenames($unmatchedOld, $unmatchedNew);

        foreach ($renames as $rename) {
            $matched[$rename['new_name']] = [
                'old' => $rename['old_node'],
                'new' => $rename['new_node'],
                'renamed_from' => $rename['old_name'],
            ];
            unset($unmatchedOld[$rename['old_name']], $unmatchedNew[$rename['new_name']]);
        }

        // Remaining unmatched are pure additions/removals
        foreach ($unmatchedOld as $name => $decl) {
            $matched[$name] = ['old' => $decl, 'new' => null];
        }

        foreach ($unmatchedNew as $name => $decl) {
            $matched[$name] = ['old' => null, 'new' => $decl];
        }

        return $matched;
    }

    /**
     * Detect renames among unmatched old and new declarations by comparing
     * the structural hash of their method bodies (ignoring the class/method name itself).
     *
     * @template T of Node
     *
     * @param  array<string, T>  $unmatchedOld
     * @param  array<string, T>  $unmatchedNew
     * @return list<array{old_name: string, new_name: string, old_node: T, new_node: T, similarity: float}>
     */
    private function detectRenames(array $unmatchedOld, array $unmatchedNew): array
    {
        if (count($unmatchedOld) === 0 || count($unmatchedNew) === 0) {
            return [];
        }

        $renames = [];
        $usedOld = [];
        $usedNew = [];

        foreach ($unmatchedOld as $oldName => $oldNode) {
            $oldMethodHashes = $this->getMethodBodyHashes($oldNode);

            $bestMatch = null;
            $bestSimilarity = 0.0;

            foreach ($unmatchedNew as $newName => $newNode) {
                if (isset($usedNew[$newName])) {
                    continue;
                }

                $newMethodHashes = $this->getMethodBodyHashes($newNode);

                if ($oldMethodHashes === [] && $newMethodHashes === []) {
                    // Neither has methods: compare parent class, interfaces, and traits
                    $similarity = $this->calculateStructuralSimilarity($oldNode, $newNode);
                } elseif ($oldMethodHashes === [] || $newMethodHashes === []) {
                    // One has methods, the other doesn't — not a rename
                    continue;
                } else {
                    $similarity = $this->calculateSimilarity($oldMethodHashes, $newMethodHashes);
                }

                if ($similarity > $bestSimilarity) {
                    $bestSimilarity = $similarity;
                    $bestMatch = $newName;
                }
            }

            // Require at least 50% method body similarity to consider it a rename
            if ($bestMatch !== null && $bestSimilarity >= 0.5) {
                $renames[] = [
                    'old_name' => $oldName,
                    'new_name' => $bestMatch,
                    'old_node' => $oldNode,
                    'new_node' => $unmatchedNew[$bestMatch],
                    'similarity' => $bestSimilarity,
                ];
                $usedOld[$oldName] = true;
                $usedNew[$bestMatch] = true;
            }
        }

        return $renames;
    }

    /**
     * Extract hashes of method bodies from a class-like node, keyed by method name.
     * For non-class nodes, hash the entire node body.
     *
     * @return array<string, string>
     */
    private function getMethodBodyHashes(Node $node): array
    {
        $hashes = [];

        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Interface_ || $node instanceof Stmt\Enum_) {
            foreach ($node->getMethods() as $method) {
                $bodyHash = $method->stmts !== null
                    ? md5($this->printer->prettyPrint($method->stmts))
                    : md5('');
                $hashes[$method->name->toString()] = $bodyHash;
            }
        }

        return $hashes;
    }

    /**
     * Calculate similarity between two sets of method body hashes.
     * Matches methods by name first, then compares their body hashes.
     *
     * @param  array<string, string>  $oldHashes
     * @param  array<string, string>  $newHashes
     */
    private function calculateSimilarity(array $oldHashes, array $newHashes): float
    {
        $allMethodNames = array_unique([...array_keys($oldHashes), ...array_keys($newHashes)]);
        $totalMethods = count($allMethodNames);

        if ($totalMethods === 0) {
            return 0.0;
        }

        $matching = 0;
        foreach ($allMethodNames as $methodName) {
            if (isset($oldHashes[$methodName], $newHashes[$methodName])
                && $oldHashes[$methodName] === $newHashes[$methodName]) {
                $matching++;
            }
        }

        return $matching / $totalMethods;
    }

    /**
     * Calculate similarity between two class-like nodes that have no methods,
     * based on their structural features (parent class, implemented interfaces, used traits).
     * Returns 1.0 if all structural features match, 0.0 otherwise.
     */
    private function calculateStructuralSimilarity(Node $old, Node $new): float
    {
        if (! ($old instanceof Stmt\Class_) || ! ($new instanceof Stmt\Class_)) {
            return get_class($old) === get_class($new) ? 1.0 : 0.0;
        }

        if ($old->extends?->toString() !== $new->extends?->toString()) {
            return 0.0;
        }

        $oldImpls = array_map(fn (Node\Name $n) => $n->toString(), $old->implements);
        $newImpls = array_map(fn (Node\Name $n) => $n->toString(), $new->implements);
        sort($oldImpls);
        sort($newImpls);

        if ($oldImpls !== $newImpls) {
            return 0.0;
        }

        return 1.0;
    }

    /**
     * Build a map of old class name -> new class name from rename detection results.
     *
     * @param  array<string, array<string, mixed>>  ...$matchResults
     * @return array<string, string>
     */
    private function buildRenameMap(array ...$matchResults): array
    {
        $map = [];

        foreach ($matchResults as $result) {
            foreach ($result as $newName => $pair) {
                if (isset($pair['renamed_from'])) {
                    $map[$pair['renamed_from']] = $newName;
                }
            }
        }

        return $map;
    }

    /**
     * Re-key an old-side extracted map (e.g. "OldClass::method") using class renames,
     * so that "OldClass::method" becomes "NewClass::method" for proper matching.
     *
     * @param  array<string, mixed>  $items
     * @param  array<string, string>  $classRenames
     * @return array<string, mixed>
     */
    private function rekeyForRenames(array $items, array $classRenames, string $separator = '::'): array
    {
        $rekeyed = [];

        foreach ($items as $key => $value) {
            $parts = explode($separator, $key, 2);
            $className = $parts[0];
            $memberName = $parts[1] ?? '';

            if (isset($classRenames[$className])) {
                $key = $classRenames[$className].$separator.$memberName;
            }

            $rekeyed[$key] = $value;
        }

        return $rekeyed;
    }

    /**
     * Match methods across all classes, keyed as "ClassName::methodName".
     * Accounts for class renames so that methods are matched correctly.
     * Detects method renames within the same class by comparing parameter list hashes.
     *
     * @param  ?array<int, Node>  $oldNodes
     * @param  ?array<int, Node>  $newNodes
     * @param  array<string, string>  $classRenames  old class name -> new class name
     * @return array<string, array{old: ?Stmt\ClassMethod, new: ?Stmt\ClassMethod, class: string, renamed_from?: string}>
     */
    private function matchMethods(?array $oldNodes, ?array $newNodes, array $classRenames = []): array
    {
        $oldMethods = $this->rekeyForRenames($this->extractMethods($oldNodes), $classRenames);
        $newMethods = $this->extractMethods($newNodes);

        $matched = [];
        foreach (array_unique([...array_keys($oldMethods), ...array_keys($newMethods)]) as $key) {
            $matched[$key] = [
                'old' => $oldMethods[$key] ?? null,
                'new' => $newMethods[$key] ?? null,
                'class' => explode('::', $key)[0],
            ];
        }

        // Second pass: detect method renames within the same class.
        // A rename is inferred when two unmatched methods share the same parameter list
        // (for non-empty param lists), or the same body (for zero-param methods).
        $unmatchedOldKeys = array_keys(array_filter($matched, fn ($p) => $p['old'] !== null && $p['new'] === null));
        $unmatchedNewKeys = array_keys(array_filter($matched, fn ($p) => $p['old'] === null && $p['new'] !== null));

        $usedNewKeys = [];

        foreach ($unmatchedOldKeys as $oldKey) {
            /** @var Stmt\ClassMethod $oldMethod */
            $oldMethod = $matched[$oldKey]['old'];
            $className = $matched[$oldKey]['class'];

            $bestNewKey = null;
            $bestScore = 0.0;

            foreach ($unmatchedNewKeys as $newKey) {
                if (isset($usedNewKeys[$newKey]) || $matched[$newKey]['class'] !== $className) {
                    continue;
                }

                /** @var Stmt\ClassMethod $newMethod */
                $newMethod = $matched[$newKey]['new'];
                $score = $this->methodRenameSimilarity($oldMethod, $newMethod);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestNewKey = $newKey;
                }
            }

            if ($bestNewKey !== null && $bestScore >= 1.0) {
                $oldMethodName = explode('::', $oldKey)[1];
                $matched[$bestNewKey] = [
                    'old' => $oldMethod,
                    'new' => $matched[$bestNewKey]['new'],
                    'class' => $className,
                    'renamed_from' => $oldMethodName,
                ];
                unset($matched[$oldKey]);
                $usedNewKeys[$bestNewKey] = true;
            }
        }

        return $matched;
    }

    /**
     * Score how likely two methods (in the same class) represent a rename.
     * Returns 1.0 if the signature match is strong enough, 0.0 otherwise.
     */
    private function methodRenameSimilarity(Stmt\ClassMethod $old, Stmt\ClassMethod $new): float
    {
        $oldParamsHash = $this->hashNodes($old->params);
        $newParamsHash = $this->hashNodes($new->params);

        if ($oldParamsHash !== $newParamsHash) {
            return 0.0;
        }

        // Empty param lists match trivially; also require an identical body to avoid false positives.
        if (count($old->params) === 0) {
            return $this->hashNodes($old->stmts ?? []) === $this->hashNodes($new->stmts ?? []) ? 1.0 : 0.0;
        }

        return 1.0;
    }

    /**
     * @param  ?array<int, Node>  $nodes
     * @return array<string, Stmt\ClassMethod>
     */
    private function extractMethods(?array $nodes): array
    {
        if ($nodes === null) {
            return [];
        }

        $methods = [];

        /** @var list<Stmt\Class_|Stmt\Interface_|Stmt\Enum_> $classLikes */
        $classLikes = [
            ...$this->findNodes($nodes, Stmt\Class_::class),
            ...$this->findNodes($nodes, Stmt\Interface_::class),
            ...$this->findNodes($nodes, Stmt\Enum_::class),
        ];

        foreach ($classLikes as $classLike) {
            $className = $classLike->name?->toString() ?? 'anonymous';
            foreach ($classLike->getMethods() as $method) {
                $key = "{$className}::{$method->name->toString()}";
                $methods[$key] = $method;
            }
        }

        return $methods;
    }

    /**
     * Match properties across all classes.
     *
     * @param  ?array<int, Node>  $oldNodes
     * @param  ?array<int, Node>  $newNodes
     * @return array<string, array{old: ?Stmt\Property, new: ?Stmt\Property, class: string}>
     */
    /**
     * @param  array<string, string>  $classRenames
     */
    private function matchProperties(?array $oldNodes, ?array $newNodes, array $classRenames = []): array
    {
        $old = $this->rekeyForRenames($this->extractProperties($oldNodes), $classRenames, '::$');
        $new = $this->extractProperties($newNodes);

        $matched = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $matched[$key] = [
                'old' => $old[$key] ?? null,
                'new' => $new[$key] ?? null,
                'class' => explode('::$', $key)[0],
            ];
        }

        return $matched;
    }

    /**
     * @param  ?array<int, Node>  $nodes
     * @return array<string, Stmt\Property>
     */
    private function extractProperties(?array $nodes): array
    {
        if ($nodes === null) {
            return [];
        }

        $properties = [];

        $classLikes = [
            ...$this->findNodes($nodes, Stmt\Class_::class),
            ...$this->findNodes($nodes, Stmt\Interface_::class),
            ...$this->findNodes($nodes, Stmt\Enum_::class),
        ];

        foreach ($classLikes as $classLike) {
            $className = $classLike->name?->toString() ?? 'anonymous';
            foreach ($this->finder->findInstanceOf([$classLike], Stmt\Property::class) as $prop) {
                foreach ($prop->props as $item) {
                    $key = "{$className}::\${$item->name->toString()}";
                    $properties[$key] = $prop;
                }
            }
        }

        return $properties;
    }

    /**
     * Match class constants across all classes.
     *
     * @param  ?array<int, Node>  $oldNodes
     * @param  ?array<int, Node>  $newNodes
     * @return array<string, array{old: ?Stmt\ClassConst, new: ?Stmt\ClassConst, class: string}>
     */
    /**
     * @param  array<string, string>  $classRenames
     */
    private function matchClassConstants(?array $oldNodes, ?array $newNodes, array $classRenames = []): array
    {
        $old = $this->rekeyForRenames($this->extractClassConstants($oldNodes), $classRenames);
        $new = $this->extractClassConstants($newNodes);

        $matched = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $matched[$key] = [
                'old' => $old[$key] ?? null,
                'new' => $new[$key] ?? null,
                'class' => explode('::', $key)[0],
            ];
        }

        return $matched;
    }

    /**
     * @param  ?array<int, Node>  $nodes
     * @return array<string, Stmt\ClassConst>
     */
    private function extractClassConstants(?array $nodes): array
    {
        if ($nodes === null) {
            return [];
        }

        $constants = [];

        $classLikes = [
            ...$this->findNodes($nodes, Stmt\Class_::class),
            ...$this->findNodes($nodes, Stmt\Interface_::class),
            ...$this->findNodes($nodes, Stmt\Enum_::class),
        ];

        foreach ($classLikes as $classLike) {
            $className = $classLike->name?->toString() ?? 'anonymous';
            foreach ($this->finder->findInstanceOf([$classLike], Stmt\ClassConst::class) as $const) {
                foreach ($const->consts as $item) {
                    $key = "{$className}::{$item->name->toString()}";
                    $constants[$key] = $const;
                }
            }
        }

        return $constants;
    }

    private function getNodeName(Node $node): ?string
    {
        if (property_exists($node, 'name') && $node->name !== null) {
            return $node->name instanceof Node\Identifier ? $node->name->toString() : (string) $node->name;
        }

        return null;
    }
}
