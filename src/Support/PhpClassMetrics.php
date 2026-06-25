<?php

namespace Vistik\LaravelCodeAnalytics\Support;

readonly class PhpClassMetrics
{
    public function __construct(
        public string $name,
        public string $kind,
        public int $line,
        public int $methods,
        public int $wmc,
        public float $ccAvg,
        public int $maxCc,
        public int $lloc,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind,
            'line' => $this->line,
            'methods' => $this->methods,
            'wmc' => $this->wmc,
            'cc_avg' => $this->ccAvg,
            'max_cc' => $this->maxCc,
            'lloc' => $this->lloc,
        ];
    }
}
