<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring;

interface RiskFactor
{
    public function name(): string;

    public function score(RiskData $data): int;

    public function maxScore(): int;
}
