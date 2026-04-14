<?php

namespace Vistik\LaravelCodeAnalytics\Support\Detection;

class LaravelPackageDetector implements Detector
{
    public function detect(RepoContext $context): bool
    {
        $json = $context->isFilesystem()
            ? $this->readFromFilesystem($context->repoPath)
            : $this->readFromGit($context->gitDir, $context->commit);

        return $this->composerRequiresLaravel(json_decode($json ?? '', true));
    }

    private function readFromFilesystem(string $repoPath): ?string
    {
        $path = "{$repoPath}/composer.json";

        return file_exists($path) ? file_get_contents($path) : null;
    }

    private function readFromGit(string $gitDir, string $commit): ?string
    {
        $json = trim(shell_exec("git -C {$gitDir} cat-file -p {$commit}:composer.json 2>/dev/null") ?? '');

        return $json !== '' ? $json : null;
    }

    private function composerRequiresLaravel(?array $composer): bool
    {
        if (! is_array($composer)) {
            return false;
        }

        $deps = array_merge(
            array_keys($composer['require'] ?? []),
            array_keys($composer['require-dev'] ?? []),
        );

        foreach ($deps as $dep) {
            if (str_starts_with($dep, 'illuminate/') || $dep === 'laravel/framework') {
                return true;
            }
        }

        return false;
    }
}
