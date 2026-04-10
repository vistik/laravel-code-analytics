<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelConfigDependencyRule implements Rule
{
    use AnalyzesLaravelCode;

    public function __construct(private readonly ?string $repoPath = null)
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Tracks config file dependencies — which files read from a changed config, and which configs a changed file depends on';
    }

    public function description(): string
    {
        return 'When a config file (e.g. config/services.php) is changed, scans the repo for PHP files that read from it via config() or Config::get(). When a PHP file is changed, detects which config namespaces it depends on and flags added or removed dependencies.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $path = $file->effectivePath();

        if ($this->pathStartsWith($path, 'config/') && str_ends_with($path, '.php')) {
            return $this->analyzeConfigFileChange($path);
        }

        if (str_ends_with($path, '.php') && $comparison['new_nodes'] !== null) {
            return $this->analyzeConfigUsagesInFile($comparison);
        }

        return [];
    }

    /**
     * When a config file changes, find all repo files that read from its namespace.
     *
     * @return list<ClassifiedChange>
     */
    private function analyzeConfigFileChange(string $path): array
    {
        $namespace = basename($path, '.php');

        if ($this->repoPath === null) {
            return [];
        }

        $consumers = $this->findConfigConsumers($namespace);

        if (empty($consumers)) {
            return [];
        }

        $count = count($consumers);
        $preview = implode(', ', array_slice($consumers, 0, 5));
        $suffix = $count > 5 ? ' (+'.($count - 5).' more)' : '';

        return [new ClassifiedChange(
            category: ChangeCategory::LARAVEL_CONFIG,
            severity: Severity::MEDIUM,
            description: "Config '{$namespace}' is read by {$count} file".($count === 1 ? '' : 's').": {$preview}{$suffix}",
        )];
    }

    /**
     * When a PHP file changes, detect added or removed config namespace dependencies.
     *
     * @return list<ClassifiedChange>
     */
    private function analyzeConfigUsagesInFile(array $comparison): array
    {
        $oldNamespaces = $comparison['old_nodes'] !== null
            ? $this->extractConfigNamespaces($comparison['old_nodes'])
            : [];

        $newNamespaces = $this->extractConfigNamespaces($comparison['new_nodes']);

        $added = array_diff($newNamespaces, $oldNamespaces);
        $removed = array_diff($oldNamespaces, $newNamespaces);

        $changes = [];

        foreach ($added as $ns) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL_CONFIG,
                severity: Severity::LOW,
                description: "Added dependency on config '{$ns}' (config/{$ns}.php)",
            );
        }

        foreach ($removed as $ns) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL_CONFIG,
                severity: Severity::LOW,
                description: "Removed dependency on config '{$ns}' (config/{$ns}.php)",
            );
        }

        return $changes;
    }

    /**
     * Extract the unique config namespaces referenced in the given AST nodes.
     * Covers config('ns.key') helper calls and Config::get/has/set('ns.key') facade calls.
     *
     * @param  array<int, Node>  $nodes
     * @return list<string>
     */
    private function extractConfigNamespaces(array $nodes): array
    {
        $namespaces = [];

        foreach ($this->finder->findInstanceOf($nodes, Expr\FuncCall::class) as $call) {
            if (! ($call->name instanceof Node\Name) || $call->name->getLast() !== 'config') {
                continue;
            }
            $ns = $this->extractNamespaceFromArg($call->args[0] ?? null);
            if ($ns !== null) {
                $namespaces[] = $ns;
            }
        }

        foreach ($this->finder->findInstanceOf($nodes, Expr\StaticCall::class) as $call) {
            if (! ($call->class instanceof Node\Name) || $call->class->getLast() !== 'Config') {
                continue;
            }
            if (! ($call->name instanceof Node\Identifier)) {
                continue;
            }
            if (! in_array($call->name->toString(), ['get', 'has', 'set', 'string', 'integer', 'float', 'boolean', 'array'], true)) {
                continue;
            }
            $ns = $this->extractNamespaceFromArg($call->args[0] ?? null);
            if ($ns !== null) {
                $namespaces[] = $ns;
            }
        }

        return array_values(array_unique($namespaces));
    }

    private function extractNamespaceFromArg(mixed $arg): ?string
    {
        if (! ($arg instanceof Node\Arg)) {
            return null;
        }
        if (! ($arg->value instanceof Scalar\String_)) {
            return null;
        }
        $key = $arg->value->value;
        $ns = explode('.', $key)[0];

        return $ns !== '' ? $ns : null;
    }

    /**
     * Scan the repo for PHP files that contain a config() or Config::get() call
     * referencing the given namespace. Skips vendor and the config file itself.
     *
     * @return list<string>
     */
    private function findConfigConsumers(string $namespace): array
    {
        $consumers = [];
        $repoPath = rtrim($this->repoPath, '/');

        $patterns = [
            "config('{$namespace}.",
            "config(\"{$namespace}.",
            "Config::get('{$namespace}.",
            "Config::get(\"{$namespace}.",
            "Config::has('{$namespace}.",
            "Config::has(\"{$namespace}.",
            "Config::set('{$namespace}.",
            "Config::set(\"{$namespace}.",
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($repoPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! ($file instanceof \SplFileInfo) || $file->getExtension() !== 'php') {
                continue;
            }

            $realPath = $file->getRealPath();

            if (str_contains($realPath, '/vendor/') || str_contains($realPath, '/node_modules/')) {
                continue;
            }

            if (str_ends_with($realPath, "/config/{$namespace}.php")) {
                continue;
            }

            $content = (string) file_get_contents($realPath);

            foreach ($patterns as $pattern) {
                if (str_contains($content, $pattern)) {
                    $consumers[] = ltrim(str_replace($repoPath, '', $realPath), '/');
                    break;
                }
            }
        }

        sort($consumers);

        return $consumers;
    }
}
