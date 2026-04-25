<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use RuntimeException;
use SimpleXMLElement;

class ParseCloverReport
{
    /**
     * Parse a Clover XML coverage report.
     *
     * @return array{
     *   fileCoverage: array<string, float>,
     *   lineCoverage: array<string, array<int, array{count: int, tests: list<string>}>>
     * }
     */
    public function parse(string $cloverPath, string $repoPath): array
    {
        if (! file_exists($cloverPath)) {
            throw new RuntimeException("Coverage file not found: {$cloverPath}");
        }

        $content = file_get_contents($cloverPath);
        if ($content === false) {
            throw new RuntimeException("Could not read coverage file: {$cloverPath}");
        }

        $xml = new SimpleXMLElement($content);
        $repoPath = rtrim($repoPath, '/');

        $fileCoverage = [];
        $lineCoverage = [];

        foreach ($xml->xpath('//file') as $file) {
            $absPath = (string) $file['name'];
            $relPath = $this->normalizePath($absPath, $repoPath);

            if ($relPath === null) {
                continue;
            }

            // Collect stmt line hit counts (method lines are just entry points, not useful as indicators)
            $lines = [];
            foreach ($file->line as $line) {
                if ((string) $line['type'] === 'stmt') {
                    $num = (int) $line['num'];
                    $lines[$num] = ['count' => (int) $line['count'], 'tests' => []];
                }
            }

            // File-level coverage from the <metrics> element (more accurate than counting lines)
            $metrics = $file->metrics;
            if ($metrics !== null) {
                $statements = (int) $metrics['statements'];
                $covered = (int) $metrics['coveredstatements'];
                if ($statements > 0) {
                    $fileCoverage[$relPath] = $covered / $statements;
                }
            } elseif (! empty($lines)) {
                $coveredLines = count(array_filter($lines, fn (array $l) => $l['count'] > 0));
                $fileCoverage[$relPath] = $coveredLines / count($lines);
            }

            if (! empty($lines)) {
                $lineCoverage[$relPath] = $lines;
            }
        }

        return ['fileCoverage' => $fileCoverage, 'lineCoverage' => $lineCoverage];
    }

    private function normalizePath(string $absPath, string $repoPath): ?string
    {
        if (str_starts_with($absPath, $repoPath.'/')) {
            return substr($absPath, strlen($repoPath) + 1);
        }

        // Fallback for CI environments where the absolute path differs from local (e.g.
        // /home/runner/work/... vs /Users/dev/...) — walk path segments and probe for a
        // matching file under the repo root so we can still resolve the relative path.
        $parts = explode('/', ltrim($absPath, '/'));
        for ($i = 1; $i < count($parts) - 1; $i++) {
            $suffix = implode('/', array_slice($parts, $i));
            if (is_file($repoPath.'/'.$suffix)) {
                return $suffix;
            }
        }

        return null;
    }
}
