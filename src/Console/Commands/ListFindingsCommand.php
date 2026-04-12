<?php

namespace Vistik\LaravelCodeAnalytics\Console\Commands;

use Illuminate\Console\Command;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\FindingsCatalog;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

use function Laravel\Prompts\select;

class ListFindingsCommand extends Command
{
    protected $signature = 'code:findings
        {--severity= : Filter by severity: very_high, high, medium, low, info (default: all)}
        {--all : Show all severities at once without stepping}';

    protected $description = 'List all finding types with example code — use this to review and calibrate severity levels';

    private const SEVERITY_COLORS = [
        'very_high' => '<fg=red;options=bold>',
        'high'      => '<fg=red>',
        'medium'    => '<fg=yellow>',
        'low'       => '<fg=blue>',
        'info'      => '<fg=gray>',
    ];

    private const SEVERITY_ORDER = [
        'very_high' => 0,
        'high'      => 1,
        'medium'    => 2,
        'low'       => 3,
        'info'      => 4,
    ];

    public function handle(): int
    {
        $severityFilter = $this->option('severity');
        $showAll = $this->option('all');

        if ($severityFilter !== null) {
            $severity = Severity::tryFrom($severityFilter);
            if ($severity === null) {
                $this->error("Invalid severity: {$severityFilter}. Valid values: very_high, high, medium, low, info");

                return self::FAILURE;
            }
            $findings = FindingsCatalog::bySeverity($severity);
        } else {
            $findings = FindingsCatalog::all();
        }

        if (empty($findings)) {
            $this->info('No findings match the given filter.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<options=bold>  Code Findings Catalog</>');
        $this->line("  <fg=gray>{$this->countSummary($findings)}</>");
        $this->line('  <fg=gray>Use this to review severity calibration. Very High = cannot review PR without checking this.</>');
        $this->line('');

        if ($showAll) {
            foreach ($findings as $index => $finding) {
                $this->renderFinding($finding, $index + 1, count($findings));
                $this->line('');
            }

            return self::SUCCESS;
        }

        // Interactive step-through
        $index = 0;
        $total = count($findings);

        while ($index < $total) {
            $finding = $findings[$index];
            $this->renderFinding($finding, $index + 1, $total);
            $this->line('');

            if ($index === $total - 1) {
                $this->line('  <fg=green>✓ All findings reviewed.</>');
                break;
            }

            $choice = select(
                label: 'Navigate',
                options: $this->navigationOptions($index, $total),
                default: 'next',
            );

            $this->line('');

            match ($choice) {
                'next' => $index++,
                'prev' => $index = max(0, $index - 1),
                'jump' => $index = $this->jumpToFinding($findings),
                'quit' => $index = $total, // exit loop
                default => null,
            };
        }

        return self::SUCCESS;
    }

    /**
     * @param array{rule: string, severity: Severity, title: string, description: string, before: string, after: string} $finding
     */
    private function renderFinding(array $finding, int $current, int $total): void
    {
        $severityValue = $finding['severity']->value;
        $colorOpen = self::SEVERITY_COLORS[$severityValue];
        $label = $finding['severity']->label();
        $badge = "{$colorOpen}● {$label}</>";

        $separator = str_repeat('─', 70);

        $this->line("  <fg=gray>{$separator}</>");
        $this->line("  {$badge}  <options=bold>{$finding['title']}</>  <fg=gray>[{$current}/{$total}]  {$finding['rule']}</>");
        $this->line('');
        $this->line("  {$finding['description']}");
        $this->line('');

        // Before block
        $this->line('  <fg=gray>BEFORE:</>');
        foreach (explode("\n", $this->trimIndent($finding['before'])) as $line) {
            $this->line("  <fg=gray>│</> {$line}");
        }

        $this->line('');

        // After block
        $this->line('  <fg=yellow>AFTER:</>');
        foreach (explode("\n", $this->trimIndent($finding['after'])) as $line) {
            $this->line("  <fg=yellow>│</> {$line}");
        }
    }

    private function trimIndent(string $code): string
    {
        $lines = explode("\n", trim($code));

        // Find the minimum indentation (ignoring empty lines)
        $minIndent = PHP_INT_MAX;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));
            $minIndent = min($minIndent, $indent);
        }

        if ($minIndent === PHP_INT_MAX) {
            $minIndent = 0;
        }

        return implode("\n", array_map(
            fn ($line) => substr($line, min($minIndent, strlen($line))),
            $lines,
        ));
    }

    /**
     * @param list<array{rule: string, severity: Severity, title: string, description: string, before: string, after: string}> $findings
     */
    private function jumpToFinding(array $findings): int
    {
        $options = [];
        foreach ($findings as $i => $finding) {
            $options[(string) $i] = "[{$finding['severity']->label()}] {$finding['title']}";
        }

        $choice = select(
            label: 'Jump to finding',
            options: $options,
        );

        return (int) $choice;
    }

    /**
     * @param list<array{rule: string, severity: Severity, title: string, description: string, before: string, after: string}> $findings
     */
    private function countSummary(array $findings): string
    {
        $counts = [];
        foreach (array_keys(self::SEVERITY_ORDER) as $sev) {
            $count = count(array_filter($findings, fn ($f) => $f['severity']->value === $sev));
            if ($count > 0) {
                $severity = Severity::from($sev);
                $counts[] = "{$count} {$severity->label()}";
            }
        }

        return implode(', ', $counts).' — '.count($findings).' total';
    }

    /**
     * @return array<string, string>
     */
    private function navigationOptions(int $index, int $total): array
    {
        $options = ['next' => 'Next →'];

        if ($index > 0) {
            $options['prev'] = '← Previous';
        }

        $options['jump'] = 'Jump to…';
        $options['quit'] = 'Quit';

        return $options;
    }
}
