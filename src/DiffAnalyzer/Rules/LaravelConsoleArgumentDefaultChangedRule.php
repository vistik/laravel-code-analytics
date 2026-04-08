<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelConsoleArgumentDefaultChangedRule implements Rule
{
    use AnalyzesLaravelCode;

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects default value changes for Artisan command arguments and options';
    }

    public function description(): string
    {
        return 'Flags when the default value of an existing argument or option in an Artisan command signature is changed, which may silently alter behaviour for callers that rely on the default.';
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

            foreach ($newTokens as $name => $newInfo) {
                if (! isset($oldTokens[$name])) {
                    continue;
                }

                $oldDefault = $oldTokens[$name]['default'];
                $newDefault = $newInfo['default'];

                if ($oldDefault === $newDefault) {
                    continue;
                }

                $label = $newInfo['type'] === 'option' ? 'option' : 'argument';
                $oldDisplay = $oldDefault ?? 'none';
                $newDisplay = $newDefault ?? 'none';

                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Default of {$label} '{$name}' changed from '{$oldDisplay}' to '{$newDisplay}' on {$this->getClassName($key)}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }

        return $changes;
    }
}
