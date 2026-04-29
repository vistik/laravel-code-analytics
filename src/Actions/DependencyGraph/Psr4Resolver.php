<?php

namespace Vistik\LaravelCodeAnalytics\Actions\DependencyGraph;

final class Psr4Resolver
{
    private ?array $psr4Map = null;

    public function __construct(
        private readonly string $repoPath = '',
        private readonly ?string $repoDir = null,
        private readonly string $headCommit = '',
    ) {}

    public function pathForFqcn(string $fqcn): ?string
    {
        foreach ($this->loadMap() as $prefix => $dir) {
            if (str_starts_with($fqcn, $prefix)) {
                return $dir.str_replace('\\', '/', substr($fqcn, strlen($prefix))).'.php';
            }
        }

        return null;
    }

    public function fqcnForPath(string $path): ?string
    {
        if (preg_match('#^app/(.+)\.php$#', $path, $m)) {
            return 'App\\'.str_replace('/', '\\', $m[1]);
        }
        if (preg_match('#^database/factories/(.+)\.php$#', $path, $m)) {
            return 'Database\\Factories\\'.str_replace('/', '\\', $m[1]);
        }
        if (preg_match('#^tests/(.+)\.php$#', $path, $m)) {
            return 'Tests\\'.str_replace('/', '\\', $m[1]);
        }

        return null;
    }

    private function loadMap(): array
    {
        if ($this->psr4Map !== null) {
            return $this->psr4Map;
        }

        $map = $this->parseMap($this->readComposerJson());

        if (empty($map)) {
            $map = [
                'App\\' => 'app/',
                'Database\\Factories\\' => 'database/factories/',
                'Database\\Seeders\\' => 'database/seeders/',
                'Tests\\' => 'tests/',
            ];
        }

        uksort($map, fn ($a, $b) => strlen($b) - strlen($a));

        return $this->psr4Map = $map;
    }

    private function readComposerJson(): ?string
    {
        if ($this->repoPath !== '') {
            $path = "{$this->repoPath}/composer.json";

            return is_file($path) ? (file_get_contents($path) ?: null) : null;
        }

        if ($this->repoDir !== null && $this->headCommit !== '') {
            $content = shell_exec("git -C {$this->repoDir} cat-file blob {$this->headCommit}:composer.json 2>/dev/null");

            return ($content !== null && $content !== '') ? $content : null;
        }

        return null;
    }

    /** @return array<string, string> */
    private function parseMap(?string $json): array
    {
        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach (['autoload', 'autoload-dev'] as $key) {
            foreach ($decoded[$key]['psr-4'] ?? [] as $ns => $dirs) {
                $ns = rtrim($ns, '\\').'\\';
                foreach ((array) $dirs as $dir) {
                    $map[$ns] = rtrim($dir, '/').'/';
                }
            }
        }

        return $map;
    }
}
