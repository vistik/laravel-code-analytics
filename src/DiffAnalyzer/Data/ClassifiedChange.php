<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

readonly class ClassifiedChange
{
    public function __construct(
        public ChangeCategory $category,
        public Severity $severity,
        public string $description,
        public ?string $location = null,
        public ?int $line = null,
        public ?string $snippet = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'description' => $this->description,
            'location' => $this->location,
            'line' => $this->line,
            'snippet' => $this->snippet,
        ], fn ($v) => $v !== null);
    }
}
