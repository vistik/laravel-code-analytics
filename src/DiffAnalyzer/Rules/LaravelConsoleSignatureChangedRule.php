<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelConsoleSignatureChangedRule implements Rule
{
    use AnalyzesLaravelCode;

    public function __construct(private readonly ?string $repoPath = null)
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects Artisan command signature and name changes';
    }

    public function description(): string
    {
        return 'Flags when an Artisan command\'s $signature or $name property changes. Severity is elevated to VERY_HIGH if the command is hardcoded in a schedule definition, since callers relying on the old name will silently stop running.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $path = $file->effectivePath();

        if (! $this->pathContains($path, 'Console/Commands/') && ! $this->pathContains($path, 'Commands/')) {
            return [];
        }

        $changes = [];

        foreach ($comparison['properties'] as $key => $pair) {
            if (! str_ends_with($key, '::$signature') && ! str_ends_with($key, '::$name')) {
                continue;
            }

            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldVal = $this->printer->prettyPrint([$pair['old']]);
            $newVal = $this->printer->prettyPrint([$pair['new']]);

            if ($oldVal === $newVal) {
                continue;
            }

            $propName = str_ends_with($key, '::$signature') ? 'signature' : 'name';
            $className = $this->getClassName($key);

            $oldCommandName = $this->extractCommandName($this->extractPropertyStringValue($pair['old']) ?? '');
            $isScheduled = $oldCommandName !== '' && $this->isScheduledCommand($oldCommandName);

            $severity = $isScheduled ? Severity::VERY_HIGH : Severity::MEDIUM;
            $scheduledNote = $isScheduled ? ' — command is hardcoded in schedule' : ' — CLI interface changed';

            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: $severity,
                description: "Artisan command \${$propName} changed on {$className}{$scheduledNote}",
                location: $key,
                line: $pair['new']->getStartLine(),
            );
        }

        return $changes;
    }

    private function extractCommandName(string $signature): string
    {
        return trim(explode('{', $signature)[0]);
    }

    private function isScheduledCommand(string $commandName): bool
    {
        if ($this->repoPath === null) {
            return false;
        }

        $consolePath = "{$this->repoPath}/routes/console.php";

        if (! file_exists($consolePath)) {
            return false;
        }

        $content = (string) file_get_contents($consolePath);

        return str_contains($content, "'{$commandName}'") || str_contains($content, "\"{$commandName}\"");
    }
}
