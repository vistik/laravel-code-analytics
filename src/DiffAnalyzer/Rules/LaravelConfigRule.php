<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelConfigRule implements Rule
{
    use AnalyzesLaravelCode;

    /** @var array<string, Severity> Config files with special significance */
    private const SENSITIVE_CONFIGS = [
        'config/auth.php' => Severity::VERY_HIGH,
        'config/database.php' => Severity::VERY_HIGH,
        'config/queue.php' => Severity::MEDIUM,
        'config/cache.php' => Severity::MEDIUM,
        'config/mail.php' => Severity::MEDIUM,
        'config/session.php' => Severity::MEDIUM,
        'config/broadcasting.php' => Severity::MEDIUM,
        'config/filesystems.php' => Severity::MEDIUM,
        'config/services.php' => Severity::MEDIUM,
        'config/cors.php' => Severity::MEDIUM,
    ];

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects configuration and environment variable changes';
    }

    public function description(): string
    {
        return 'Detects configuration changes: config file modifications with sensitivity levels, .env.example variable additions/removals, and cache key string changes.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];
        $path = $file->effectivePath();

        if ($this->pathStartsWith($path, 'config/')) {
            $this->analyzeConfigFile($path, $comparison, $changes);
        }

        if ($path === '.env.example') {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::MEDIUM,
                description: 'Environment template (.env.example) changed — deployment may require new env variables',
            );
        }

        // Check for cache key changes in any PHP file
        $this->analyzeCacheKeys($comparison, $changes);

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeConfigFile(string $path, array $comparison, array &$changes): void
    {
        $severity = self::SENSITIVE_CONFIGS[$path] ?? Severity::INFO;

        $changes[] = new ClassifiedChange(
            category: ChangeCategory::LARAVEL,
            severity: $severity,
            description: 'Configuration modified: '.basename($path, '.php'),
        );

        // Try to detect which config keys changed by comparing return array structures
        if ($comparison['old_nodes'] !== null && $comparison['new_nodes'] !== null) {
            $oldKeys = $this->extractReturnedArrayKeys($comparison['old_nodes']);
            $newKeys = $this->extractReturnedArrayKeys($comparison['new_nodes']);

            $added = array_diff($newKeys, $oldKeys);
            $removed = array_diff($oldKeys, $newKeys);

            foreach ($added as $key) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: $severity,
                    description: "Config key added: {$key}",
                );
            }

            foreach ($removed as $key) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: $severity,
                    description: "Config key removed: {$key}",
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeCacheKeys(array $comparison, array &$changes): void
    {
        $cacheFacades = ['Cache', 'RateLimiter'];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            foreach ($cacheFacades as $facade) {
                $oldKeys = $this->extractCacheKeysFromNode($pair['old'], $facade);
                $newKeys = $this->extractCacheKeysFromNode($pair['new'], $facade);

                $changed = array_diff($oldKeys, $newKeys);

                if (count($changed) > 0) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: "Cache key changed in {$key} — existing cached data may be orphaned",
                        location: $key,
                    );

                    break;
                }
            }
        }
    }

    /**
     * Extract top-level keys from a config file (which typically returns an array).
     *
     * @param  array<int, Node>  $nodes
     * @return list<string>
     */
    private function extractReturnedArrayKeys(array $nodes): array
    {
        $keys = [];

        $returns = $this->finder->findInstanceOf($nodes, Node\Stmt\Return_::class);

        foreach ($returns as $return) {
            if ($return->expr instanceof Expr\Array_) {
                foreach ($return->expr->items as $item) {
                    if ($item !== null && $item->key instanceof Node\Scalar\String_) {
                        $keys[] = $item->key->value;
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function extractCacheKeysFromNode(Node $node, string $facade): array
    {
        $keys = [];

        foreach ($this->findStaticCalls($node, $facade) as $call) {
            $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

            if (in_array($methodName, ['get', 'put', 'forget', 'remember', 'rememberForever', 'has', 'flexible'], true)) {
                $key = $this->getFirstStringArg($call);
                if ($key !== null) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }
}
