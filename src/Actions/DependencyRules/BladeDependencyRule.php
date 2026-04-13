<?php

namespace Vistik\LaravelCodeAnalytics\Actions\DependencyRules;

/**
 * Extracts inter-template dependencies from .blade.php files.
 *
 * Handles: extends, include, includeIf, includeWhen, includeUnless,
 * includeFirst, each, component directives, and anonymous <x-*> component tags.
 */
class BladeDependencyRule
{
    /**
     * Returns the repo-relative paths of Blade views referenced in $content.
     *
     * @return list<string>
     */
    public function resolve(string $content): array
    {
        $paths = [];

        // @extends, @include, @includeIf, @each, @component — first string arg
        preg_match_all(
            '/@(?:extends|include|includeIf|each|component)\s*\(\s*[\'"]([^\'"\s]+)[\'"]/m',
            $content,
            $singleMatches
        );
        foreach ($singleMatches[1] as $viewName) {
            $paths[] = $this->viewNameToPath($viewName);
        }

        // @includeWhen, @includeUnless — view name is the second string arg
        preg_match_all(
            '/@(?:includeWhen|includeUnless)\s*\([^,]+,\s*[\'"]([^\'"\s]+)[\'"]/m',
            $content,
            $conditionalMatches
        );
        foreach ($conditionalMatches[1] as $viewName) {
            $paths[] = $this->viewNameToPath($viewName);
        }

        // @includeFirst — array of view names
        preg_match_all('/@includeFirst\s*\(\s*\[([^\]]+)\]/m', $content, $firstMatches);
        foreach ($firstMatches[1] as $arrayContent) {
            preg_match_all('/[\'"]([^\'"\s]+)[\'"]/', $arrayContent, $arrayViewMatches);
            foreach ($arrayViewMatches[1] as $viewName) {
                $paths[] = $this->viewNameToPath($viewName);
            }
        }

        // <x-component-name> and <x-namespace.component> anonymous components
        preg_match_all('/<x-([\w.-]+)[\s\/>]/m', $content, $xMatches);
        foreach ($xMatches[1] as $componentName) {
            $paths[] = $this->xComponentToPath($componentName);
        }

        return array_values(array_unique($paths));
    }

    private function viewNameToPath(string $viewName): string
    {
        return 'resources/views/'.str_replace('.', '/', $viewName).'.blade.php';
    }

    private function xComponentToPath(string $componentName): string
    {
        // Dots indicate nesting: x-forms.input → resources/views/components/forms/input.blade.php
        return 'resources/views/components/'.str_replace('.', '/', $componentName).'.blade.php';
    }
}
