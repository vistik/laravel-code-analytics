<?php

namespace Vistik\LaravelCodeAnalytics\Contracts;

use Vistik\LaravelCodeAnalytics\Enums\GraphLayout;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;

interface ReportGenerator
{
    public function generate(
        GraphPayload $payload,
        PullRequestContext $pr,
        ?GraphLayout $defaultView = null,
    ): string;

    public function writeFile(string $outputPath, string $content): void;
}
