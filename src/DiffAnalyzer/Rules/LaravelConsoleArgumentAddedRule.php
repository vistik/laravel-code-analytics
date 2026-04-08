<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelConsoleArgumentAddedRule implements Rule
{
    use AnalyzesLaravelCode;

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects new arguments and options added to Artisan commands';
    }

    public function description(): string
    {
        return 'Flags when a new positional argument or named option is added to an Artisan command signature. Required arguments are flagged as HIGH severity as they break existing callers.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $path = $file->effectivePath();

        if (! $this->pathContains($path, 'Console/Commands/') && ! $this->pathContains($path, 'Commands/')) {
            return [];
        }

        $changes = [];

        foreach ($comparison['properties'] as $key => $pair) {
            if (! str_ends_with($key, '::$signature')) {
                continue;
            }

            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldSig = $this->extractPropertyStringValue($pair['old']);
            $newSig = $this->extractPropertyStringValue($pair['new']);

            if ($oldSig === null || $newSig === null) {
                continue;
            }

            $oldTokens = $this->parseSignatureTokens($oldSig);
            $newTokens = $this->parseSignatureTokens($newSig);

            foreach ($newTokens as $name => $info) {
                if (isset($oldTokens[$name])) {
                    continue;
                }

                $label = $info['type'] === 'option' ? 'option' : 'argument';
                $severity = ($info['type'] === 'argument' && $info['required']) ? Severity::HIGH : Severity::LOW;

                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: $severity,
                    description: "New {$label} '{$name}' added to Artisan command {$this->getClassName($key)}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }

        return $changes;
    }
}
