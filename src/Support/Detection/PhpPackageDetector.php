<?php

namespace Vistik\LaravelCodeAnalytics\Support\Detection;

class PhpPackageDetector implements Detector
{
    public function detect(RepoContext $context): bool
    {
        $json = $context->isFilesystem()
            ? $this->readFromFilesystem($context->repoPath)
            : $this->readFromGit($context->gitDir, $context->commit);

        return $this->composerIndicatesPackage(json_decode($json ?? '', true));
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

    private function composerIndicatesPackage(?array $composer): bool
    {
        if (! is_array($composer)) {
            return false;
        }

        // "project" type means a standalone application, not a distributable package
        $type = $composer['type'] ?? 'library';

        return $type !== 'project';
    }
}
