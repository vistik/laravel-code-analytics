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
        $tmpDir = sys_get_temp_dir().'/phpmetrics_'.uniqid();
        $reportPath = $tmpDir.'_report.json';

        try {
            return $this->runInTmpDir($pathToContent, $tmpDir, $reportPath);
        } finally {
            $this->cleanup($tmpDir, $reportPath);
        }
    }

    /**
     * @param  array<string, string|null>  $pathToContent
     * @return array<string, PhpMetrics>
     */
    private function runInTmpDir(array $pathToContent, string $tmpDir, string $reportPath): array
    {
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
            return [];
        }

        $binary = realpath(__DIR__.'/../../vendor/bin/phpmetrics') ?: base_path('vendor/bin/phpmetrics');
        $cmd = escapeshellcmd($binary)
            .' --report-json='.escapeshellarg($reportPath)
            .' '.escapeshellarg($tmpDir)
            .' 2>/dev/null';

        exec($cmd, $output, $exitCode);

        if (! file_exists($reportPath)) {
            Log::warning('PhpMetrics report not generated', ['cmd' => $cmd, 'exit' => $exitCode]);

            return [];
        }

        $json = file_get_contents($reportPath);
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
