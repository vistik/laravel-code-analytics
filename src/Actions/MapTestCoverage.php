<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MapTestCoverage
{
    /**
     * @return array{
     *   test_to_sources: array<string, list<string>>,
     *   source_to_tests: array<string, list<string>>,
     *   uncovered_sources: list<string>,
     * }
     */
    public function execute(string $repoPath): array
    {
        $repoPath = rtrim(realpath($repoPath) ?: $repoPath, '/');
        $mappings = $this->loadPsr4Mappings($repoPath);

        $testFiles = $this->findPhpFiles($repoPath, $mappings['test_dirs']);
        $sourceFiles = $this->findPhpFiles($repoPath, $mappings['src_dirs']);

        $testToSources = [];
        foreach ($testFiles as $testFile) {
            $relTest = $this->relativePath($repoPath, $testFile);
            $sources = $this->extractSourceImports($testFile, $repoPath, $mappings['src_psr4']);
            if ($sources !== []) {
                $testToSources[$relTest] = $sources;
            }
        }

        $sourceToTests = [];
        foreach ($testToSources as $test => $sources) {
            foreach ($sources as $source) {
                $sourceToTests[$source][] = $test;
            }
        }

        $coveredSources = array_keys($sourceToTests);
        $relSources = array_map(fn ($f) => $this->relativePath($repoPath, $f), $sourceFiles);
        $uncovered = array_values(array_diff($relSources, $coveredSources));

        ksort($testToSources);
        ksort($sourceToTests);
        sort($uncovered);

        return [
            'test_to_sources' => $testToSources,
            'source_to_tests' => $sourceToTests,
            'uncovered_sources' => $uncovered,
        ];
    }

    /** @return array{src_psr4: array<string, string>, src_dirs: list<string>, test_dirs: list<string>} */
    private function loadPsr4Mappings(string $repoPath): array
    {
        $composerPath = $repoPath.'/composer.json';

        $srcPsr4 = [];
        $testPsr4 = [];

        if (file_exists($composerPath)) {
            $composer = json_decode((string) file_get_contents($composerPath), true);
            $srcPsr4 = $composer['autoload']['psr-4'] ?? [];
            $testPsr4 = $composer['autoload-dev']['psr-4'] ?? [];
        }

        $normalizeDir = fn ($dir) => rtrim(is_array($dir) ? $dir[0] : $dir, '/');

        $srcPsr4Normalized = array_map($normalizeDir, $srcPsr4);
        $srcDirs = array_unique(array_values($srcPsr4Normalized));

        $testDirs = [];
        foreach ($testPsr4 as $ns => $dir) {
            $d = $normalizeDir($dir);
            if (stripos($d, 'test') !== false || stripos($ns, 'test') !== false) {
                $testDirs[] = $d;
            }
        }

        if ($testDirs === [] && $testPsr4 !== []) {
            $testDirs = array_map($normalizeDir, array_values($testPsr4));
        }

        if ($testDirs === []) {
            $testDirs = ['tests'];
        }

        return [
            'src_psr4' => $srcPsr4Normalized,
            'src_dirs' => $srcDirs !== [] ? $srcDirs : ['src'],
            'test_dirs' => array_unique($testDirs),
        ];
    }

    /** @return list<string> */
    private function findPhpFiles(string $repoPath, array $dirs): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            $absDir = $repoPath.'/'.$dir;
            if (! is_dir($absDir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param array<string, string> $srcPsr4
     * @return list<string>
     */
    private function extractSourceImports(string $testFile, string $repoPath, array $srcPsr4): array
    {
        $content = file_get_contents($testFile);
        if ($content === false) {
            return [];
        }

        preg_match_all('/^use ([A-Za-z0-9\\\\]+);/m', $content, $matches);

        $sources = [];
        foreach ($matches[1] as $fqcn) {
            $path = $this->fqcnToPath($repoPath, $fqcn, $srcPsr4);
            if ($path !== null) {
                $sources[] = $path;
            }
        }

        return array_values(array_unique($sources));
    }

    /** @param array<string, string> $srcPsr4 */
    private function fqcnToPath(string $repoPath, string $fqcn, array $srcPsr4): ?string
    {
        $bestNs = null;
        $bestLen = 0;

        foreach ($srcPsr4 as $ns => $dir) {
            $nsPrefix = rtrim($ns, '\\');
            if (str_starts_with($fqcn, $nsPrefix) && strlen($nsPrefix) > $bestLen) {
                $bestNs = $nsPrefix;
                $bestLen = strlen($nsPrefix);
            }
        }

        if ($bestNs === null) {
            return null;
        }

        $dir = $srcPsr4[rtrim($bestNs, '\\').'\\'] ?? $srcPsr4[$bestNs] ?? null;
        if ($dir === null) {
            return null;
        }

        $relative = substr($fqcn, strlen($bestNs) + 1);
        $filePath = $dir.'/'.str_replace('\\', '/', $relative).'.php';

        return file_exists($repoPath.'/'.$filePath) ? $filePath : null;
    }

    private function relativePath(string $repoPath, string $absolutePath): string
    {
        return ltrim(str_replace($repoPath, '', $absolutePath), '/');
    }
}
