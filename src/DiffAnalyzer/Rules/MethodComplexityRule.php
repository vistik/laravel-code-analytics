<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\Support\PhpMethodMetrics;
use Vistik\LaravelCodeAnalytics\Support\PhpMethodMetricsCalculator;

class MethodComplexityRule implements Rule
{
    private PhpMethodMetricsCalculator $calculator;

    public function __construct()
    {
        $this->calculator = new PhpMethodMetricsCalculator;
    }

    public function shortDescription(): string
    {
        return 'Detects method complexity severity changes (good/warn/bad)';
    }

    public function description(): string
    {
        return 'Flags methods whose complexity metric (CC, LLOC, or param count) crosses a severity threshold between old and new versions.';
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

        $oldByName = $this->indexByName(
            $this->calculator->calculate(['_' => $oldSource])['_'] ?? []
        );

        $newMetrics = $this->calculator->calculate(['_' => $newSource])['_'] ?? [];

        /** @var array<string, array{warn: int, bad: int}> $thresholds */
        $thresholds = config('laravel-code-analytics.method_metric_thresholds', [
            'cc' => ['warn' => 5, 'bad' => 10],
        ]);

        $changes = [];

        foreach ($newMetrics as $new) {
            $old = $oldByName[$new->name] ?? null;
            if ($old === null) {
                continue;
            }

            foreach ($thresholds as $metric => $limits) {
                $oldVal = $old->{$metric};
                $newVal = $new->{$metric};

                $oldBand = $this->band($oldVal, $limits['warn'], $limits['bad']);
                $newBand = $this->band($newVal, $limits['warn'], $limits['bad']);

                if ($oldBand === $newBand) {
                    continue;
                }

                $severity = $this->transitionSeverity($oldBand, $newBand);

                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::COMPLEXITY,
                    severity: $severity,
                    description: "Method {$new->name}: {$metric} severity {$oldBand} → {$newBand} ({$oldVal} → {$newVal})",
                    location: $new->name,
                    line: $new->line,
                );
            }
        }

        return $changes;
    }

    /**
     * @param  list<PhpMethodMetrics>  $metrics
     * @return array<string, PhpMethodMetrics>
     */
    private function indexByName(array $metrics): array
    {
        $indexed = [];
        foreach ($metrics as $m) {
            $indexed[$m->name] = $m;
        }

        return $indexed;
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
