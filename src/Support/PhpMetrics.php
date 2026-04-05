<?php

namespace Vistik\LaravelCodeAnalytics\Support;

readonly class PhpMetrics
{
    public function __construct(
        public ?int $cyclomaticComplexity,
        public ?int $weightedMethodCount,
        public ?float $maintainabilityIndex,
        public ?float $bugs,
        public ?int $lackOfCohesion,
        public ?int $efferentCoupling,
        public ?int $logicalLinesOfCode,
        public ?int $methodsCount,
    ) {}

    /**
     * Construct from a raw PhpMetrics JSON entry (uses the library's field names).
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        return new self(
            cyclomaticComplexity: isset($raw['ccn']) ? (int) $raw['ccn'] : null,
            weightedMethodCount: isset($raw['wmc']) ? (int) $raw['wmc'] : null,
            maintainabilityIndex: isset($raw['mi']) ? (float) $raw['mi'] : null,
            bugs: isset($raw['bugs']) ? (float) $raw['bugs'] : null,
            lackOfCohesion: isset($raw['lcom']) ? (int) $raw['lcom'] : null,
            efferentCoupling: isset($raw['efferentCoupling']) ? (int) $raw['efferentCoupling'] : null,
            logicalLinesOfCode: isset($raw['lloc']) ? (int) $raw['lloc'] : null,
            methodsCount: isset($raw['nbMethods']) ? (int) $raw['nbMethods'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'cyclomatic_complexity' => $this->cyclomaticComplexity,
            'weighted_method_count' => $this->weightedMethodCount,
            'maintainability_index' => $this->maintainabilityIndex,
            'bugs' => $this->bugs,
            'lack_of_cohesion' => $this->lackOfCohesion,
            'efferent_coupling' => $this->efferentCoupling,
            'logical_lines_of_code' => $this->logicalLinesOfCode,
            'methods_count' => $this->methodsCount,
        ], fn ($v) => $v !== null);
    }
}
