<?php

namespace Vistik\LaravelCodeAnalytics\Support;

use Illuminate\Support\Facades\Log;

class JsMetricsRunner
{
    /**
     * Run JS complexity analysis on the given file contents and return per-path metrics.
     *
     * Uses bin/jsmetrics.js (powered by @babel/parser) which supports JSX, TSX,
     * ES modules, TypeScript, and all modern syntax.
     *
     * @param  array<string, string|null>  $pathToContent  Relative file path → source (null entries are skipped)
     * @return array<string, JsMetrics> Relative path → parsed metric value objects
     */
    public function run(array $pathToContent): array
    {
        $handle = $this->prepare($pathToContent);
        if ($handle === null) {
            return [];
        }

        exec($handle['cmd'], $output, $exitCode);

        return $this->collect($handle, implode("\n", $output), $exitCode);
    }

    /**
     * Write the given sources to a temp dir and build the jsmetrics command.
     *
     * The caller is responsible for executing the returned command (e.g.
     * concurrently via Process::pool) and then passing the handle plus the
     * captured stdout to collect(). Returns null when there is nothing to run
     * or the node runtime / bundled script is unavailable.
     *
     * @param  array<string, string|null>  $pathToContent
     * @return array{cmd: string, tmpDir: string, pathToContent: array<string, string|null>}|null
     */
    public function prepare(array $pathToContent): ?array
    {
        $tmpDir = sys_get_temp_dir().'/jsmetrics_'.uniqid();

        mkdir($tmpDir, 0700, true);

        $written = 0;
        foreach ($pathToContent as $path => $content) {
            if ($content === null || $content === '') {
                continue;
            }

            $dest = $tmpDir.'/'.$path;
            $dir = dirname($dest);
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }

            file_put_contents($dest, $content);
            $written++;
        }

        if ($written === 0) {
            $this->cleanup($tmpDir);

            return null;
        }

        $node = $this->findNode();
        if ($node === null) {
            Log::info('node binary not found, skipping JS metrics.');
            $this->cleanup($tmpDir);

            return null;
        }

        // Resolve script path relative to this file (works in both dev and vendor installs).
        // The bundle includes @babel/parser inline — no npm install needed in the host app.
        $script = dirname(__DIR__, 2).'/bin/jsmetrics.bundle.js';
        if (! file_exists($script)) {
            Log::info('bin/jsmetrics.js not found, skipping JS metrics.');
            $this->cleanup($tmpDir);

            return null;
        }

        $cmd = escapeshellarg($node)
            .' '.escapeshellarg($script)
            .' '.escapeshellarg($tmpDir)
            .' 2>/dev/null';

        return ['cmd' => $cmd, 'tmpDir' => $tmpDir, 'pathToContent' => $pathToContent];
    }

    /**
     * Parse the JSON emitted on stdout by a prepared command, then clean up.
     *
     * @param  array{cmd: string, tmpDir: string, pathToContent: array<string, string|null>}  $handle
     * @return array<string, JsMetrics>
     */
    public function collect(array $handle, string $output, int $exitCode = 0): array
    {
        try {
            $data = json_decode($output, associative: true);

            if (! is_array($data) || empty($data['reports'])) {
                if ($exitCode !== 0) {
                    Log::warning('jsmetrics.js failed', ['exit' => $exitCode]);
                }

                return [];
            }

            $pathToContent = $handle['pathToContent'];
            $tmpDirPrefix = rtrim($handle['tmpDir'], '/').'/';
            $results = [];

            foreach ($data['reports'] as $report) {
                $reportPath = $report['path'] ?? '';

                // Strip absolute tmp prefix if present (shouldn't be, but guard anyway)
                $relativePath = str_starts_with($reportPath, $tmpDirPrefix)
                    ? substr($reportPath, strlen($tmpDirPrefix))
                    : $reportPath;

                if ($relativePath === '' || ! isset($pathToContent[$relativePath])) {
                    continue;
                }

                $results[$relativePath] = JsMetrics::fromRaw($report);
            }

            return $results;
        } finally {
            $this->cleanup($handle['tmpDir']);
        }
    }

    /**
     * Locate the `node` binary.
     */
    private function findNode(): ?string
    {
        foreach (['node', 'nodejs'] as $name) {
            $path = trim(shell_exec("which {$name} 2>/dev/null") ?? '');
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        // Common install locations
        foreach (['/usr/local/bin/node', '/usr/bin/node', '/opt/homebrew/bin/node'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function cleanup(string $tmpDir): void
    {
        if (is_dir($tmpDir)) {
            $this->removeDir($tmpDir);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
