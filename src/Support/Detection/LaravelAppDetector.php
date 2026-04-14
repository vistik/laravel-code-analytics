<?php

namespace Vistik\LaravelCodeAnalytics\Support\Detection;

class LaravelAppDetector implements Detector
{
    public function detect(RepoContext $context): bool
    {
        if ($context->isFilesystem()) {
            return is_file("{$context->repoPath}/artisan");
        }

        $type = trim(shell_exec("git -C {$context->gitDir} cat-file -t {$context->commit}:artisan 2>/dev/null") ?? '');

        return $type === 'blob';
    }
}
