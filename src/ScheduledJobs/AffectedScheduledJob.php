<?php

namespace Vistik\LaravelCodeAnalytics\ScheduledJobs;

readonly class AffectedScheduledJob
{
    public function __construct(
        public ScheduledJobDefinition $job,
        public string $triggeredByPath,
        public array $dependencyChain,
        public ?string $jobNodeId = null,
        public array $reachableNodeIds = [],
        public array $reachableDepths = [],
    ) {}

    public function toArray(): array
    {
        return [
            'handlerFqcn' => $this->job->handlerFqcn,
            'scheduleExpression' => $this->job->scheduleExpression,
            'triggeredByPath' => $this->triggeredByPath,
            'dependencyChain' => $this->dependencyChain,
            'jobNodeId' => $this->jobNodeId,
            'reachableNodeIds' => $this->reachableNodeIds,
            'reachableDepths' => $this->reachableDepths,
        ];
    }
}
