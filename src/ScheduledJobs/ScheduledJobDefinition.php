<?php

namespace Vistik\LaravelCodeAnalytics\ScheduledJobs;

readonly class ScheduledJobDefinition
{
    public function __construct(
        public string $handlerFqcn,
        public string $scheduleExpression,
    ) {}
}
