<?php

namespace Vistik\LaravelCodeAnalytics\Support;

use Illuminate\Support\Facades\Log;

class PhpMetricsRunner
{
    /**
     * Run PhpMetrics on the given PHP file contents and return per-class metrics.
     *
     * @param  array<string, string|null>  $pathToContent  Relative file path → PHP source (null entries are skipped)
     * @return array<string, PhpMetrics> FQCN → parsed metric value objects
     */
    public function run(array $pathToContent): array
    {
        $handle = $this->prepare($pathToContent);
        if ($handle === null) {
            return [];
        }

        exec($handle['cmd'], $output, $exitCode);

        return $this->collect($handle, $exitCode);
    }

    /**
     * Write the given sources to a temp dir and build the phpmetrics command.
     *
     * The caller is responsible for executing the returned command (e.g.
     * concurrently via Process::pool) and then passing the handle to collect().
     * Returns null when there is nothing to analyze.
     *
     * @param  array<string, string|null>  $pathToContent
     * @return array{cmd: string, reportPath: string, tmpDir: string}|null
     */
    public function prepare(array $pathToContent): ?array
    {
        $tmpDir = sys_get_temp_dir().'/phpmetrics_'.uniqid();
        $reportPath = $tmpDir.'_report.json';

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
            $this->cleanup($tmpDir, $reportPath);

            return null;
        }

        $binary = realpath(__DIR__.'/../../vendor/bin/phpmetrics') ?: base_path('vendor/bin/phpmetrics');
        $cmd = escapeshellcmd($binary)
            .' --report-json='.escapeshellarg($reportPath)
            .' '.escapeshellarg($tmpDir)
            .' 2>/dev/null';

        return ['cmd' => $cmd, 'reportPath' => $reportPath, 'tmpDir' => $tmpDir];
    }

    /**
     * Parse the JSON report produced by a prepared command, then clean up.
     *
     * @param  array{cmd: string, reportPath: string, tmpDir: string}  $handle
     * @return array<string, PhpMetrics>
     */
    public function collect(array $handle, int $exitCode = 0): array
    {
        try {
            if (! file_exists($handle['reportPath'])) {
                Log::warning('PhpMetrics report not generated', ['cmd' => $handle['cmd'], 'exit' => $exitCode]);

                return [];
            }

            $json = file_get_contents($handle['reportPath']);
            $data = json_decode($json, associative: true);

            if (! is_array($data)) {
                return [];
            }

            $skip = ['tree', 'composer', 'searches'];

            $filtered = array_filter(
                $data,
                fn ($key) => ! in_array($key, $skip, strict: true)
                    && ! str_ends_with($key, '\\')
                    && isset($data[$key]['_type'])
                    && $data[$key]['_type'] === 'Hal\\Metric\\ClassMetric',
                ARRAY_FILTER_USE_KEY,
            );

            return array_map(fn (array $raw) => PhpMetrics::fromRaw($raw), $filtered);
        } finally {
            $this->cleanup($handle['tmpDir'], $handle['reportPath']);
        }
    }

    private function cleanup(string $tmpDir, string $reportPath): void
    {
        if (file_exists($reportPath)) {
            unlink($reportPath);
        }

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
