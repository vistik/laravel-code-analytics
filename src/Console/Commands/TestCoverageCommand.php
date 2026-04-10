<?php

namespace Vistik\LaravelCodeAnalytics\Console\Commands;

use Illuminate\Console\Command;
use Vistik\LaravelCodeAnalytics\Actions\MapTestCoverage;

class TestCoverageCommand extends Command
{
    protected $signature = 'code:test-coverage
        {repo-path? : Path to the local git repo (defaults to current working directory)}
        {--format=text : Output format: text or json}
        {--invert : Show source files → test files instead of test files → source files}
        {--only-missing : Only show source files not covered by any test}';

    protected $description = 'Map which tests cover which source files via static import analysis';

    public function handle(MapTestCoverage $action): int
    {
        $repoPath = $this->argument('repo-path') ?? getcwd();
        $format = $this->option('format');
        $invert = $this->option('invert');
        $onlyMissing = $this->option('only-missing');

        if (! is_dir($repoPath)) {
            $this->error("Directory not found: {$repoPath}");

            return self::FAILURE;
        }

        $result = $action->execute($repoPath);

        if ($format === 'json') {
            if ($onlyMissing) {
                $this->output->write(json_encode($result['uncovered_sources'], JSON_PRETTY_PRINT));
            } elseif ($invert) {
                $this->output->write(json_encode($result['source_to_tests'], JSON_PRETTY_PRINT));
            } else {
                $this->output->write(json_encode($result['test_to_sources'], JSON_PRETTY_PRINT));
            }

            return self::SUCCESS;
        }

        if ($onlyMissing) {
            return $this->renderMissing($result['uncovered_sources']);
        }

        if ($invert) {
            return $this->renderMap($result['source_to_tests'], 'Source file', 'Covered by');
        }

        return $this->renderMap($result['test_to_sources'], 'Test file', 'Imports');
    }

    /** @param array<string, list<string>> $map */
    private function renderMap(array $map, string $keyLabel, string $valueLabel): int
    {
        if ($map === []) {
            $this->warn('No mappings found.');

            return self::SUCCESS;
        }

        foreach ($map as $key => $values) {
            $this->line("<fg=cyan>{$key}</>");
            foreach ($values as $value) {
                $this->line("  <fg=gray>{$valueLabel}:</> {$value}");
            }
            $this->newLine();
        }

        $total = count($map);
        $this->info("{$total} {$keyLabel}(s) mapped.");

        return self::SUCCESS;
    }

    /** @param list<string> $files */
    private function renderMissing(array $files): int
    {
        if ($files === []) {
            $this->info('All source files are imported by at least one test.');

            return self::SUCCESS;
        }

        foreach ($files as $file) {
            $this->line($file);
        }

        $count = count($files);
        $this->newLine();
        $this->warn("{$count} source file(s) not covered by any test.");

        return self::SUCCESS;
    }
}
