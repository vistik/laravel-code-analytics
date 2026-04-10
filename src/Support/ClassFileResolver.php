<?php

namespace Vistik\LaravelCodeAnalytics\Support;

/**
 * Resolves a fully-qualified class name to an absolute file path.
 *
 * Sources (tried in order):
 *  1. vendor/composer/autoload_classmap.php  — authoritative when present
 *  2. PSR-4 rules from composer.json         — fallback for unlisted classes
 */
class ClassFileResolver
{
    /** @var array<string, string>  FQCN => absolute path */
    private array $classmap = [];

    /** @var list<array{prefix: string, path: string}> sorted longest-prefix-first */
    private array $psr4 = [];

    public function __construct(private readonly string $repoRoot)
    {
        $this->loadClassmap();
        $this->loadPsr4();
    }

    /**
     * Resolve a FQCN to an absolute file path, or null if not found.
     */
    public function resolve(string $fqcn): ?string
    {
        $fqcn = ltrim($fqcn, '\\');

        // 1. Classmap lookup — exact and reliable
        if (isset($this->classmap[$fqcn])) {
            $path = $this->classmap[$fqcn];

            return is_file($path) ? $path : null;
        }

        // 2. PSR-4 derivation — best-effort for autoloaded classes
        foreach ($this->psr4 as ['prefix' => $prefix, 'path' => $basePath]) {
            if (str_starts_with($fqcn, $prefix)) {
                $relative = str_replace('\\', '/', substr($fqcn, strlen($prefix)));
                $candidate = $basePath.'/'.$relative.'.php';

                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Return true if the resolved path is inside vendor/.
     */
    public function isVendor(string $absolutePath): bool
    {
        $vendorDir = rtrim($this->repoRoot, '/').'/vendor/';

        return str_starts_with($absolutePath, $vendorDir);
    }

    // ── Loader helpers ────────────────────────────────────────────────────────

    private function loadClassmap(): void
    {
        $file = rtrim($this->repoRoot, '/').'/vendor/composer/autoload_classmap.php';

        if (is_file($file)) {
            /** @var array<string, string> $map */
            $map = require $file;
            $this->classmap = $map;
        }
    }

    private function loadPsr4(): void
    {
        $composerJson = rtrim($this->repoRoot, '/').'/composer.json';

        if (! is_file($composerJson)) {
            return;
        }

        $data = json_decode((string) file_get_contents($composerJson), true);

        if (! is_array($data)) {
            return;
        }

        $rules = array_merge(
            $data['autoload']['psr-4'] ?? [],
            $data['autoload-dev']['psr-4'] ?? [],
        );

        foreach ($rules as $prefix => $paths) {
            foreach ((array) $paths as $relPath) {
                $this->psr4[] = [
                    'prefix' => rtrim($prefix, '\\').'\\',
                    'path' => rtrim($this->repoRoot, '/').'/'.rtrim($relPath, '/'),
                ];
            }
        }

        // Longer prefixes must be checked first (more specific wins)
        usort($this->psr4, fn (array $a, array $b) => strlen($b['prefix']) <=> strlen($a['prefix']));
    }
}
