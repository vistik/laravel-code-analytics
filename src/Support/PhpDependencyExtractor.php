<?php

namespace Vistik\LaravelCodeAnalytics\Support;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Extracts class references from PHP source and classifies how each dependency is used:
 *
 *   constructor_injection — type-hinted in __construct params (IoC container injects it)
 *   method_injection      — type-hinted in a non-constructor method param
 *   new_instance          — directly instantiated with `new ClassName()`
 *   container_resolved    — resolved via app(), resolve(), App::make()
 *   static_call           — used via ClassName::method()
 *   type_reference        — property types, return types, extends/implements, use imports
 */
class PhpDependencyExtractor
{
    public const CONSTRUCTOR_INJECTION = 'constructor_injection';

    public const METHOD_INJECTION = 'method_injection';

    public const NEW_INSTANCE = 'new_instance';

    public const CONTAINER_RESOLVED = 'container_resolved';

    public const STATIC_CALL = 'static_call';

    public const TYPE_REFERENCE = 'type_reference';

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
     * Returns a map of FQCN (or short class name) → dependency type string.
     * When a class is referenced in multiple ways the strongest type wins:
     * constructor_injection > method_injection > container_resolved > new_instance > static_call > type_reference
     *
     * @return array<string, string>
     */
    public function extract(string $content): array
    {
        $errors = new Collecting;
        $nodes = $this->parser->parse($content, $errors);

        if ($nodes === null) {
            return $this->fallbackExtract($content);
        }

        /** @var array<string, list<string>> */
        $refs = [];

        $this->collectConstructorInjections($nodes, $refs);
        $this->collectMethodInjections($nodes, $refs);
        $this->collectNewInstances($nodes, $refs);
        $this->collectContainerResolutions($nodes, $refs);
        $this->collectStaticCalls($nodes, $refs);
        $this->collectTypeReferences($nodes, $refs);

        return array_map([$this, 'strongest'], $refs);
    }

    /**
     * @param  array<int, \PhpParser\Node>  $nodes
     * @param  array<string, list<string>>  $refs
     */
    private function collectConstructorInjections(array $nodes, array &$refs): void
    {
        foreach ($this->finder->findInstanceOf($nodes, Stmt\ClassMethod::class) as $method) {
            /** @var Stmt\ClassMethod $method */
            if ($method->name->name !== '__construct') {
                continue;
            }
            foreach ($method->params as $param) {
                if ($param->type === null) {
                    continue;
                }
                foreach ($this->resolveTypeNames($param->type) as $name) {
                    $refs[$name][] = self::CONSTRUCTOR_INJECTION;
                }
            }
        }
    }

    /**
     * @param  array<int, \PhpParser\Node>  $nodes
     * @param  array<string, list<string>>  $refs
     */
    private function collectMethodInjections(array $nodes, array &$refs): void
    {
        foreach ($this->finder->findInstanceOf($nodes, Stmt\ClassMethod::class) as $method) {
            /** @var Stmt\ClassMethod $method */
            if ($method->name->name === '__construct') {
                continue;
            }
            foreach ($method->params as $param) {
                if ($param->type === null) {
                    continue;
                }
                foreach ($this->resolveTypeNames($param->type) as $name) {
                    $refs[$name][] = self::METHOD_INJECTION;
                }
            }
        }
    }

    /**
     * @param  array<int, \PhpParser\Node>  $nodes
     * @param  array<string, list<string>>  $refs
     */
    private function collectNewInstances(array $nodes, array &$refs): void
    {
        foreach ($this->finder->findInstanceOf($nodes, Expr\New_::class) as $new) {
            /** @var Expr\New_ $new */
            if (! ($new->class instanceof Name)) {
                continue;
            }
            $name = $new->class->toString();
            if (in_array($name, ['self', 'static', 'parent'], true)) {
                continue;
            }
            $refs[$name][] = self::NEW_INSTANCE;
        }
    }

    /**
     * Detects app(Foo::class), resolve(Foo::class), App::make(Foo::class),
     * app()->make(Foo::class), $this->app->make(Foo::class).
     *
     * @param  array<int, \PhpParser\Node>  $nodes
     * @param  array<string, list<string>>  $refs
     */
    private function collectContainerResolutions(array $nodes, array &$refs): void
    {
        // app(Foo::class) / resolve(Foo::class)
        foreach ($this->finder->findInstanceOf($nodes, Expr\FuncCall::class) as $call) {
            /** @var Expr\FuncCall $call */
            if (! ($call->name instanceof Name)) {
                continue;
            }
            $funcName = $call->name->toString();
            if (! in_array($funcName, ['app', 'resolve'], true)) {
                continue;
            }
            if (isset($call->args[0])) {
                $argValue = $call->args[0] instanceof \PhpParser\Node\Arg ? $call->args[0]->value : null;
                foreach ($this->classConstFetchName($argValue) as $name) {
                    $refs[$name][] = self::CONTAINER_RESOLVED;
                }
            }
        }

        // App::make(Foo::class), App::resolve(Foo::class)
        foreach ($this->finder->findInstanceOf($nodes, Expr\StaticCall::class) as $call) {
            /** @var Expr\StaticCall $call */
            if (! ($call->class instanceof Name)) {
                continue;
            }
            $className = $call->class->toString();
            if (! in_array($className, ['App', 'Application'], true)) {
                continue;
            }
            if (! ($call->name instanceof \PhpParser\Node\Identifier)) {
                continue;
            }
            if (! in_array($call->name->name, ['make', 'resolve', 'get'], true)) {
                continue;
            }
            if (isset($call->args[0])) {
                $argValue = $call->args[0] instanceof \PhpParser\Node\Arg ? $call->args[0]->value : null;
                foreach ($this->classConstFetchName($argValue) as $name) {
                    $refs[$name][] = self::CONTAINER_RESOLVED;
                }
            }
        }

        // $this->app->make(Foo::class) / app()->make(Foo::class)
        foreach ($this->finder->findInstanceOf($nodes, Expr\MethodCall::class) as $call) {
            /** @var Expr\MethodCall $call */
            if (! ($call->name instanceof \PhpParser\Node\Identifier)) {
                continue;
            }
            if (! in_array($call->name->name, ['make', 'resolve', 'get'], true)) {
                continue;
            }
            if (isset($call->args[0])) {
                $argValue = $call->args[0] instanceof \PhpParser\Node\Arg ? $call->args[0]->value : null;
                foreach ($this->classConstFetchName($argValue) as $name) {
                    $refs[$name][] = self::CONTAINER_RESOLVED;
                }
            }
        }
    }

    /**
     * @param  array<int, \PhpParser\Node>  $nodes
     * @param  array<string, list<string>>  $refs
     */
    private function collectStaticCalls(array $nodes, array &$refs): void
    {
        foreach ($this->finder->findInstanceOf($nodes, Expr\StaticCall::class) as $call) {
            /** @var Expr\StaticCall $call */
            if (! ($call->class instanceof Name)) {
                continue;
            }
            $name = $call->class->toString();
            if (in_array($name, ['self', 'static', 'parent', 'App', 'Application'], true)) {
                continue;
            }
            $refs[$name][] = self::STATIC_CALL;
        }

        foreach ($this->finder->findInstanceOf($nodes, Expr\ClassConstFetch::class) as $fetch) {
            /** @var Expr\ClassConstFetch $fetch */
            if (! ($fetch->class instanceof Name)) {
                continue;
            }
            $name = $fetch->class->toString();
            if (in_array($name, ['self', 'static', 'parent'], true)) {
                continue;
            }
            // Only add if constant is not "class" (bare Foo::class used as string is type_reference)
            if ($fetch->name instanceof \PhpParser\Node\Identifier && $fetch->name->name !== 'class') {
                $refs[$name][] = self::STATIC_CALL;
            }
        }
    }

    /**
     * Covers property types, return types, extends/implements, use imports.
     *
     * @param  array<int, \PhpParser\Node>  $nodes
     * @param  array<string, list<string>>  $refs
     */
    private function collectTypeReferences(array $nodes, array &$refs): void
    {
        // use imports
        foreach ($this->finder->findInstanceOf($nodes, Stmt\UseUse::class) as $use) {
            /** @var Stmt\UseUse $use */
            $refs[$use->name->toString()][] = self::TYPE_REFERENCE;
        }

        // class extends
        foreach ($this->finder->findInstanceOf($nodes, Stmt\Class_::class) as $class) {
            /** @var Stmt\Class_ $class */
            if ($class->extends !== null) {
                $refs[$class->extends->toString()][] = self::TYPE_REFERENCE;
            }
            foreach ($class->implements as $iface) {
                $refs[$iface->toString()][] = self::TYPE_REFERENCE;
            }
        }

        // interface extends
        foreach ($this->finder->findInstanceOf($nodes, Stmt\Interface_::class) as $iface) {
            /** @var Stmt\Interface_ $iface */
            foreach ($iface->extends as $parent) {
                $refs[$parent->toString()][] = self::TYPE_REFERENCE;
            }
        }

        // property type hints
        foreach ($this->finder->findInstanceOf($nodes, Stmt\Property::class) as $prop) {
            /** @var Stmt\Property $prop */
            if ($prop->type !== null) {
                foreach ($this->resolveTypeNames($prop->type) as $name) {
                    $refs[$name][] = self::TYPE_REFERENCE;
                }
            }
        }

        // return types and constructor promoted property types
        foreach ($this->finder->findInstanceOf($nodes, Stmt\ClassMethod::class) as $method) {
            /** @var Stmt\ClassMethod $method */
            if ($method->returnType !== null) {
                foreach ($this->resolveTypeNames($method->returnType) as $name) {
                    $refs[$name][] = self::TYPE_REFERENCE;
                }
            }
            // Constructor promoted properties (public/protected/private params)
            foreach ($method->params as $param) {
                if ($param->flags !== 0 && $param->type !== null) {
                    foreach ($this->resolveTypeNames($param->type) as $name) {
                        $refs[$name][] = self::TYPE_REFERENCE;
                    }
                }
            }
        }
    }

    /**
     * Extract class name(s) from a type node (handles unions, intersections, nullable, named).
     *
     * @return list<string>
     */
    private function resolveTypeNames(\PhpParser\Node $typeNode): array
    {
        if ($typeNode instanceof Name) {
            $name = $typeNode->toString();
            if (! in_array($name, ['self', 'static', 'parent', 'null', 'void', 'never', 'mixed',
                'bool', 'int', 'float', 'string', 'array', 'callable', 'iterable', 'object'], true)) {
                return [$name];
            }

            return [];
        }

        if ($typeNode instanceof \PhpParser\Node\NullableType) {
            return $this->resolveTypeNames($typeNode->type);
        }

        if ($typeNode instanceof \PhpParser\Node\UnionType || $typeNode instanceof \PhpParser\Node\IntersectionType) {
            $names = [];
            foreach ($typeNode->types as $t) {
                $names = array_merge($names, $this->resolveTypeNames($t));
            }

            return $names;
        }

        // Identifier (scalar types) — skip
        return [];
    }

    /**
     * Extract class name from a Foo::class expression.
     *
     * @return list<string>
     */
    private function classConstFetchName(?\PhpParser\Node $node): array
    {
        if ($node instanceof Expr\ClassConstFetch
            && $node->class instanceof Name
            && $node->name instanceof \PhpParser\Node\Identifier
            && $node->name->name === 'class') {
            return [$node->class->toString()];
        }

        if ($node instanceof \PhpParser\Node\Scalar\String_) {
            if (preg_match('/^[A-Z][A-Za-z0-9_\\\\]+$/', $node->value)) {
                return [$node->value];
            }
        }

        return [];
    }

    /**
     * Priority order: constructor > method > container > new > static > type_reference
     *
     * @param  list<string>  $types
     */
    private function strongest(array $types): string
    {
        $priority = [
            self::CONSTRUCTOR_INJECTION => 0,
            self::METHOD_INJECTION => 1,
            self::CONTAINER_RESOLVED => 2,
            self::NEW_INSTANCE => 3,
            self::STATIC_CALL => 4,
            self::TYPE_REFERENCE => 5,
        ];

        usort($types, fn ($a, $b) => ($priority[$a] ?? 99) <=> ($priority[$b] ?? 99));

        return $types[0] ?? self::TYPE_REFERENCE;
    }

    /**
     * Regex-based fallback when parsing fails — all refs classified as type_reference.
     *
     * @return array<string, string>
     */
    private function fallbackExtract(string $content): array
    {
        $refs = [];

        preg_match_all('/^\s*use\s+([A-Z][A-Za-z0-9_\\\\]+)/m', $content, $m);
        foreach ($m[1] as $name) {
            $refs[$name] = self::TYPE_REFERENCE;
        }

        preg_match_all('/(?:extends|implements)\s+([A-Z][A-Za-z0-9_\\\\]+)/m', $content, $m);
        foreach ($m[1] as $name) {
            $refs[$name] = self::TYPE_REFERENCE;
        }

        preg_match_all('/\bnew\s+([A-Z][A-Za-z0-9_\\\\]+)/m', $content, $m);
        foreach ($m[1] as $name) {
            $refs[$name] = isset($refs[$name]) ? $refs[$name] : self::NEW_INSTANCE;
        }

        preg_match_all('/([A-Z][A-Za-z0-9_]+)::/m', $content, $m);
        foreach ($m[1] as $name) {
            if (! isset($refs[$name])) {
                $refs[$name] = self::STATIC_CALL;
            }
        }

        return $refs;
    }
}
