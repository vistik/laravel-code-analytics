<?php

namespace Vistik\LaravelCodeAnalytics\Reports;

class PullRequestContext
{
    public function __construct(
        public readonly string $prTitle,
        public readonly string $repo,
        public readonly string $headCommit,
        public readonly int $prAdditions,
        public readonly int $prDeletions,
        public readonly int $fileCount,
        public readonly string $prNumber = '',
        public readonly string $prUrl = '',
        public readonly int $connectedCount = 0,
    ) {}
}
