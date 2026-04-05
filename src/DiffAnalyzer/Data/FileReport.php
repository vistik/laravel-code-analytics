<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

readonly class FileReport
{
    /**
     * @param  list<ClassifiedChange>  $changes
     */
    public function __construct(
        public string $path,
        public FileStatus $status,
        public array $changes = [],
    ) {}

    public function hasCategory(ChangeCategory $category): bool
    {
        foreach ($this->changes as $change) {
            if ($change->category === $category) {
                return true;
            }
        }

        return false;
    }

    public function maxSeverity(): Severity
    {
        $max = Severity::INFO;

        foreach ($this->changes as $change) {
            if ($change->severity->score() > $max->score()) {
                $max = $change->severity;
            }
        }

        return $max;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'status' => $this->status->value,
            'max_severity' => $this->maxSeverity()->value,
            'changes' => array_map(fn (ClassifiedChange $c) => $c->toArray(), $this->changes),
        ];
    }
}
