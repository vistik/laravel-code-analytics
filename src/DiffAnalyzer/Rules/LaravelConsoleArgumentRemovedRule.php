<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelConsoleArgumentRemovedRule implements Rule
{
    use AnalyzesLaravelCode;

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects arguments and options removed from Artisan commands';
    }

    public function description(): string
    {
        return 'Flags when a positional argument or named option is removed from an Artisan command signature, as existing callers that pass the removed argument will break.';
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

            foreach ($oldTokens as $name => $info) {
                if (isset($newTokens[$name])) {
                    continue;
                }

                $label = $info['type'] === 'option' ? 'option' : 'argument';

                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::HIGH,
                    description: "Artisan command {$label} '{$name}' removed from {$this->getClassName($key)}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }

        return $changes;
    }
}
