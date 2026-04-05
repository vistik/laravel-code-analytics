<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring\LocalFactors;

use Vistik\LaravelCodeAnalytics\RiskScoring\RiskData;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskFactor;

class FileSpreadFactor implements RiskFactor
{
    public function name(): string
    {
        return 'File Spread';
    }

    public function score(RiskData $data): int
    {
        return match (true) {
            $data->fileCount > 30 => 10,
            $data->fileCount > 15 => 7,
            $data->fileCount > 8 => 4,
            $data->fileCount > 3 => 2,
            default => 0,
        };
    }

    public function maxScore(): int
    {
        return 10;
    }
}
