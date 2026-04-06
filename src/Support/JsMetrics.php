<?php

namespace Vistik\LaravelCodeAnalytics\Support;

readonly class JsMetrics
{
    public function __construct(
        public ?int $cyclomaticComplexity,
        public ?float $maintainabilityIndex,
        public ?float $bugs,
        public ?int $logicalLinesOfCode,
        public ?int $functionCount,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $aggregate = $raw['aggregate'] ?? [];
        $halstead = $aggregate['halstead'] ?? [];
        $sloc = $aggregate['sloc'] ?? [];

        return new self(
            cyclomaticComplexity: isset($aggregate['cyclomatic']) ? (int) $aggregate['cyclomatic'] : null,
            maintainabilityIndex: isset($raw['maintainability']) ? (float) $raw['maintainability'] : null,
            bugs: isset($halstead['bugs']) ? (float) $halstead['bugs'] : null,
            logicalLinesOfCode: isset($sloc['logical']) ? (int) $sloc['logical'] : null,
            functionCount: isset($raw['functions']) ? count($raw['functions']) : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'cyclomatic_complexity' => $this->cyclomaticComplexity,
            'maintainability_index' => $this->maintainabilityIndex,
            'bugs' => $this->bugs,
            'logical_lines_of_code' => $this->logicalLinesOfCode,
            'function_count' => $this->functionCount,
        ], fn ($v) => $v !== null);
    }
}
