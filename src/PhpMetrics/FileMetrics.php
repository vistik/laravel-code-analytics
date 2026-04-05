<?php

namespace Vistik\LaravelCodeAnalytics\PhpMetrics;

readonly class FileMetrics
{
    public function __construct(
        public ?int $cc = null,
        public ?float $mi = null,
        public ?float $bugs = null,
        public ?int $coupling = null,
        public ?int $lloc = null,
        public ?int $methods = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            cc: isset($data['cc']) ? (int) $data['cc'] : null,
            mi: isset($data['mi']) ? round((float) $data['mi'], 1) : null,
            bugs: isset($data['bugs']) ? round((float) $data['bugs'], 3) : null,
            coupling: isset($data['coupling']) ? (int) $data['coupling'] : null,
            lloc: isset($data['lloc']) ? (int) $data['lloc'] : null,
            methods: isset($data['methods']) ? (int) $data['methods'] : null,
        );
    }
}
