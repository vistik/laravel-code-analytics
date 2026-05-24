<?php

namespace Vistik\LaravelCodeAnalytics\Support;

readonly class PhpMethodMetrics
{
    public function __construct(
        public string $name,
        public int $line,
        public int $cc,
        public int $lloc,
        public int $params,
        public float $flog,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'line' => $this->line,
            'cc' => $this->cc,
            'lloc' => $this->lloc,
            'params' => $this->params,
            'flog' => $this->flog,
        ];
    }
}
