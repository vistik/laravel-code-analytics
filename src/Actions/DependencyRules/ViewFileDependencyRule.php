<?php

namespace Vistik\LaravelCodeAnalytics\Actions\DependencyRules;

/**
 * Detects blade view dependencies expressed via view()->file(...), covering:
 *
 *   view()->file(__DIR__ . '/../../resources/views/foo.blade.php', ...)
 *   view()->file('resources/views/foo.blade.php', ...)
 *   view()->file('/absolute/path/foo.blade.php', ...)
 */
class ViewFileDependencyRule
{
    /**
     * Returns the repo-relative paths of blade views referenced via view()->file() in $content.
     *
     * @return list<string>
     */
    public function resolve(string $content, string $sourcePath): array
    {
        $paths = [];

        // view()->file(__DIR__ . '/relative/path.blade.php', ...)
        preg_match_all('/\bview\s*\(\s*\)\s*->\s*file\s*\(\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/m', $content, $dirMatches);
        foreach ($dirMatches[1] as $relPart) {
            $sourceDir = $sourcePath !== '' ? dirname($sourcePath) : '';
            $combined = $sourceDir !== '' ? $sourceDir.'/'.$relPart : $relPart;
            $paths[] = $this->normalizePath($combined);
        }

        // view()->file('/absolute/or/relative/path.blade.php', ...)
        preg_match_all('/\bview\s*\(\s*\)\s*->\s*file\s*\(\s*[\'"]([^\'"]+\.blade\.php)[\'"]/', $content, $fileMatches);
        foreach ($fileMatches[1] as $filePath) {
            $paths[] = ltrim($filePath, '/');
        }

        return array_values(array_unique($paths));
    }

    private function normalizePath(string $path): string
    {
        $parts = explode('/', $path);
        $result = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($result);
            } elseif ($part !== '.' && $part !== '') {
                $result[] = $part;
            }
        }

        return implode('/', $result);
    }
}
