<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SimpleXMLElement;

class ParseXmlCoverageReport
{
    /**
     * Parse PHPUnit's --coverage-xml directory output.
     *
     * @return array{
     *   fileCoverage: array<string, float>,
     *   lineCoverage: array<string, array<int, array{count: int, tests: list<string>}>>
     * }
     */
    /** @var list<string> Diagnostic messages populated during parse for the caller to surface */
    public array $diagnostics = [];

    public function parse(string $xmlDir, string $repoPath): array
    {
        if (! is_dir($xmlDir)) {
            throw new RuntimeException("Coverage XML directory not found: {$xmlDir}");
        }

        $repoPath = rtrim($repoPath, '/');
        $realXmlDir = rtrim((string) realpath($xmlDir), '/');

        $fileCoverage = [];
        $lineCoverage = [];
        $this->diagnostics = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($xmlDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $xmlFilesFound = 0;
        $firstXmlFile = null;
        $firstRootTag = null;

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() !== 'xml' || $fileInfo->getFilename() === 'index.xml') {
                continue;
            }

            $xmlFilesFound++;

            $content = file_get_contents($fileInfo->getPathname());
            if ($content === false) {
                continue;
            }

            // Capture first file for diagnostics before stripping namespace
            if ($firstXmlFile === null) {
                $firstXmlFile = $fileInfo->getRealPath();
                preg_match('/<(\w+)[\s>]/', $content, $m);
                $firstRootTag = $m[1] ?? '?';
            }

            // Strip the default namespace so SimpleXMLElement can access elements without a prefix.
            $content = (string) preg_replace('/\s+xmlns="[^"]*"/', '', $content);

            try {
                $xml = new SimpleXMLElement($content);
            } catch (\Exception) {
                continue;
            }

            $fileEl = $xml->file ?? null;
            if ($fileEl === null) {
                // Record what the root element actually is so we can diagnose wrong structure
                if (count($this->diagnostics) === 0) {
                    $this->diagnostics[] = "root element is <{$xml->getName()}>, expected child <file> — not found";
                    $children = [];
                    foreach ($xml->children() as $child) {
                        $children[] = '<'.$child->getName().'>';
                    }
                    $this->diagnostics[] = 'children of root: '.implode(', ', $children ?: ['(none)']);
                }

                continue;
            }

            // Resolve the source file path.
            // PHPUnit's XML dir mirrors the source tree: coverage/src/Foo.php.xml → src/Foo.php.
            // The `path` attribute is a namespace-style partial path (no extension, no src/ prefix)
            // so we rely primarily on the XML file's own location within the coverage directory.
            $relPath = null;
            $candidate = '';

            // Use getRealPath() so we always have an absolute path regardless of how the
            // iterator was opened (relative vs absolute input directory).
            $xmlAbsPath = (string) $fileInfo->getRealPath();
            $xmlRelative = substr($xmlAbsPath, strlen($realXmlDir) + 1);

            if (str_ends_with($xmlRelative, '.xml')) {
                $candidate = substr($xmlRelative, 0, -4); // strip .xml → e.g. src/Foo.php or Foo.php
                $relPath = $this->probeSourcePath($candidate, $repoPath);
            }

            if ($relPath === null && count($this->diagnostics) === 0) {
                $attrPath = (string) ($fileEl['path'] ?? '');
                $this->diagnostics[] = "path attr: '{$attrPath}', xml-derived candidate: '{$candidate}'";
                $this->diagnostics[] = "probed for: {$repoPath}/{$candidate} (and with src/, app/ prefix) — not found";
            }

            if ($relPath === null) {
                continue;
            }

            // File-level coverage from <totals><lines executable="..." executed="..."/>
            $totalsLines = $fileEl->totals->lines ?? null;
            if ($totalsLines !== null) {
                $executable = (int) $totalsLines['executable'];
                $executed = (int) $totalsLines['executed'];
                if ($executable > 0) {
                    $fileCoverage[$relPath] = $executed / $executable;
                }
            }

            // Per-line coverage. Format:
            //   <line nr="181">
            //     <covered by="TestClass::testMethod"/>
            //   </line>
            // Lines with <covered> children = covered; count = number of covering tests.
            // Lines without <covered> children = executable but not covered (count = 0).
            $lines = [];
            foreach ($fileEl->coverage->line ?? [] as $line) {
                $nr = (int) $line['nr'];
                $tests = [];
                foreach ($line->covered as $covered) {
                    $by = (string) $covered['by'];
                    if ($by !== '') {
                        $tests[] = $by;
                    }
                }
                $lines[$nr] = ['count' => count($tests), 'tests' => $tests];
            }

            if (! empty($lines)) {
                $lineCoverage[$relPath] = $lines;

                // Derive file coverage from line data when <totals> was absent
                if (! isset($fileCoverage[$relPath])) {
                    $covered = count(array_filter($lines, fn ($l) => $l['count'] > 0));
                    $fileCoverage[$relPath] = $covered / count($lines);
                }
            }
        }

        if ($xmlFilesFound === 0) {
            $this->diagnostics[] = "no .xml files found in {$xmlDir} (index.xml excluded)";
        } elseif (empty($fileCoverage) && $firstXmlFile !== null) {
            $this->diagnostics[] = "found {$xmlFilesFound} xml file(s), root tag was <{$firstRootTag}>, but parsed 0 source files";
            $this->diagnostics[] = "first xml file: {$firstXmlFile}";
            $this->diagnostics[] = "repo path: {$repoPath}";
        }

        return ['fileCoverage' => $fileCoverage, 'lineCoverage' => $lineCoverage];
    }

    /**
     * Given a candidate relative path derived from the coverage XML file's location,
     * probe the repo for the actual source file trying common source directory prefixes.
     * PHPUnit's coverage XML often mirrors the source tree but may omit the top-level
     * source directory (e.g. stores "Foo.php" when the file lives at "src/Foo.php").
     */
    private function probeSourcePath(string $candidate, string $repoPath): ?string
    {
        if ($candidate === '') {
            return null;
        }

        // Direct match (coverage dir already mirrors the full relative path)
        if (is_file($repoPath.'/'.$candidate)) {
            return $candidate;
        }

        // Try stripping one leading path component at a time — handles cases where the
        // coverage dir has an extra prefix relative to the repo root.
        $parts = explode('/', $candidate);
        for ($i = 1; $i < count($parts); $i++) {
            $stripped = implode('/', array_slice($parts, $i));
            if (is_file($repoPath.'/'.$stripped)) {
                return $stripped;
            }
        }

        // Try prepending common source directory prefixes
        foreach (['src/', 'app/', 'lib/'] as $prefix) {
            if (is_file($repoPath.'/'.$prefix.$candidate)) {
                return $prefix.$candidate;
            }
        }

        return null;
    }
}
