<?php

namespace Vistik\LaravelCodeAnalytics\Support\Detection;

final readonly class RepoContext
{
    private function __construct(
        public readonly ?string $repoPath,
        public readonly ?string $gitDir,
        public readonly ?string $commit,
    ) {}

    public static function filesystem(string $repoPath): self
    {
        return new self(repoPath: $repoPath, gitDir: null, commit: null);
    }

    public static function git(string $gitDir, string $commit): self
    {
        return new self(repoPath: null, gitDir: $gitDir, commit: $commit);
    }

    public function isFilesystem(): bool
    {
        return $this->repoPath !== null;
    }
}
