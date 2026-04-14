<?php

namespace Vistik\LaravelCodeAnalytics\Support\Detection;

interface Detector
{
    public function detect(RepoContext $context): bool;
}
