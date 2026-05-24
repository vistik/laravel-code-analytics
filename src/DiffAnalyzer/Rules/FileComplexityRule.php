<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\Support\PhpMethodMetrics;
use Vistik\LaravelCodeAnalytics\Support\PhpMethodMetricsCalculator;

class FileComplexityRule implements Rule
{
    private PhpMethodMetricsCalculator $calculator;

    public function __construct()
    {
        $this->calculator = new PhpMethodMetricsCalculator;
    }

    public function shortDescription(): string
    {
        return 'Detects file-level complexity severity changes (CC and Flog totals)';
    }

    public function description(): string
    {
        return 'Flags files whose total CC or total Flog score (summed across all methods) crosses a severity threshold between the old and new versions.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        if (! in_array($file->status, [FileStatus::MODIFIED, FileStatus::RENAMED], strict: true)) {
            return [];
        }

        $oldSource = $comparison['old_source'];
        $newSource = $comparison['new_source'];

        if ($oldSource === null || $newSource === null) {
            return [];
        }

        $oldMethods = $this->calculator->calculate(['_' => $oldSource])['_'] ?? [];
        $newMethods = $this->calculator->calculate(['_' => $newSource])['_'] ?? [];

        if (empty($oldMethods) || empty($newMethods)) {
            return [];
        }

        /** @var array<string, array{warn: int|float, bad: int|float}> $thresholds */
        $thresholds = config('laravel-code-analytics.file_complexity_thresholds', [
            'cc' => ['warn' => 25, 'bad' => 50],
            'flog' => ['warn' => 30, 'bad' => 60],
        ]);

        $changes = [];

        foreach ($thresholds as $metric => $limits) {
            $oldTotal = $this->total($oldMethods, $metric);
            $newTotal = $this->total($newMethods, $metric);

            $oldBand = $this->band($oldTotal, $limits['warn'], $limits['bad']);
            $newBand = $this->band($newTotal, $limits['warn'], $limits['bad']);

            if ($oldBand === $newBand) {
                continue;
            }

            $severity = $this->transitionSeverity($oldBand, $newBand);

            $oldFmt = is_float($oldTotal) ? number_format($oldTotal, 1) : $oldTotal;
            $newFmt = is_float($newTotal) ? number_format($newTotal, 1) : $newTotal;

            $changes[] = new ClassifiedChange(
                category: ChangeCategory::COMPLEXITY,
                severity: $severity,
                description: "File {$metric} severity {$oldBand} → {$newBand} (total {$metric}: {$oldFmt} → {$newFmt})",
                location: null,
                line: null,
            );
        }

        return $changes;
    }

    /**
     * @param  list<PhpMethodMetrics>  $methods
     */
    private function total(array $methods, string $metric): int|float
    {
        $sum = 0;
        foreach ($methods as $m) {
            $sum += $m->{$metric};
        }

        return $sum;
    }

    private function band(int|float $value, int|float $warn, int|float $bad): string
    {
        if ($value >= $bad) {
            return 'bad';
        }
        if ($value >= $warn) {
            return 'warn';
        }

        return 'good';
    }

    private function transitionSeverity(string $from, string $to): Severity
    {
        return match ([$from, $to]) {
            ['good', 'warn'] => Severity::LOW,
            ['good', 'bad'] => Severity::HIGH,
            ['warn', 'bad'] => Severity::MEDIUM,
            ['warn', 'good'] => Severity::INFO,
            ['bad', 'warn'] => Severity::INFO,
            ['bad', 'good'] => Severity::LOW,
            default => Severity::INFO,
        };
    }
}
