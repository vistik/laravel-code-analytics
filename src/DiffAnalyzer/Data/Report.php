<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

readonly class Report
{
    /**
     * @param  list<FileReport>  $fileReports
     */
    public function __construct(
        public array $fileReports,
    ) {}

    /**
     * @param  list<FileReport>  $fileReports
     */
    public static function fromUnsorted(array $fileReports): self
    {
        usort($fileReports, fn (FileReport $a, FileReport $b) => $b->maxSeverity()->score() <=> $a->maxSeverity()->score());

        return new self($fileReports);
    }

    public function hasCategory(ChangeCategory $category): bool
    {
        foreach ($this->fileReports as $report) {
            if ($report->hasCategory($category)) {
                return true;
            }
        }

        return false;
    }

    public function maxSeverity(): Severity
    {
        $max = Severity::INFO;

        foreach ($this->fileReports as $report) {
            $fileSeverity = $report->maxSeverity();
            if ($fileSeverity->score() > $max->score()) {
                $max = $fileSeverity;
            }
        }

        return $max;
    }

    /**
     * @return array<string, int>
     */
    public function categoryCounts(): array
    {
        $counts = [];

        foreach ($this->fileReports as $report) {
            foreach ($report->changes as $change) {
                $key = $change->category->value;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function totalChanges(): int
    {
        $total = 0;

        foreach ($this->fileReports as $report) {
            $total += count($report->changes);
        }

        return $total;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'files' => array_map(fn (FileReport $r) => $r->toArray(), $this->fileReports),
            'summary' => [
                'total_files' => count($this->fileReports),
                'total_changes' => $this->totalChanges(),
                'max_severity' => $this->maxSeverity()->value,
                'categories' => $this->categoryCounts(),
            ],
        ];
    }
}
