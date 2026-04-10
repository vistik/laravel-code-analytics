<?php

namespace Vistik\LaravelCodeAnalytics\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Vistik\LaravelCodeAnalytics\Actions\AnalyzeCode;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\ArrayFileGroupResolver;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts\FileGroupResolver;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\PatternBasedGroupResolver;
use Vistik\LaravelCodeAnalytics\Enums\OutputFormat;

class CodeAnalyzeCommand extends Command
{
    protected $signature = 'code:analyze
        {repo-path? : Path to the local git repo (defaults to current working directory)}
        {output? : Output file path (HTML, Markdown, or JSON depending on --format)}
        {--base= : Base branch or commit to diff against (default: main)}
        {--pr= : GitHub PR URL to analyze remotely (e.g. https://github.com/owner/repo/pull/123)}
        {--all : Analyze all tracked files instead of just the diff}
        {--title= : Custom title for the analysis report}
        {--view= : Default graph view to show (force, tree, grouped, cake, arch)}
        {--config= : Path to a JSON config file (supports: repo_path, output, base, pr, title, view, format, open, file_groups, min_severity, file, all)}
        {--format=html : Output format: html, md, json, metrics, metrics-details, or github}
        {--min-severity= : Minimum severity to include (info, low, medium, high, very_high) — files with only lower-severity changes are excluded}
        {--file=* : Only analyze files matching this path or glob pattern (can be repeated)}
        {--open : Open the generated file in the browser when done}
        {--full-files : Embed full file contents in the report to enable the "Full file" diff view (increases report size)}
        {--github-metrics : Include per-class and per-method PHP metrics as inline annotations (only applies to --format=github)}';

    protected $description = 'Analyze a local branch diff — AST analysis, risk scoring, and interactive graph';

    public function handle(AnalyzeCode $action): int
    {
        try {
            $config = $this->loadConfig();

            $repoPath = $this->argument('repo-path') ?? $config['repo_path'] ?? getcwd();
            $outputPath = $this->argument('output') ?? $config['output'] ?? null;
            $baseBranch = $this->option('base') ?? $config['base'] ?? 'main';
            $title = $this->option('title') ?? $config['title'] ?? null;
            $view = $this->option('view') ?? $config['view'] ?? null;
            $formatString = $this->option('format') ?? $config['format'] ?? 'html';
            $format = OutputFormat::tryFrom($formatString)
                ?? throw new RuntimeException("Invalid format: {$formatString}. Valid options: html, md, json, metrics, metrics-details, github");
            $openFile = $this->option('open') || ($config['open'] ?? false);
            $includeFileContents = $this->option('full-files') || ($config['full_files'] ?? false);
            $githubMetrics = $this->option('github-metrics') || ($config['github_metrics'] ?? false);

            if ($openFile && $outputPath === null) {
                $ext = $format->fileExtension();
                $tmp = tempnam(sys_get_temp_dir(), 'code-analyze-');
                unlink($tmp);
                $outputPath = $tmp.'.'.$ext;
            }

            $minSeverityString = $this->option('min-severity') ?? $config['min_severity'] ?? null;
            $minSeverity = $minSeverityString !== null
                ? (Severity::tryFrom($minSeverityString) ?? throw new RuntimeException("Invalid min-severity: {$minSeverityString}. Valid options: info, low, medium, high, very_high"))
                : null;

            if (isset($config['file_groups'])) {
                $action = new AnalyzeCode(groupResolver: $this->resolveGroupResolver($config));
            }

            $prUrl = $this->option('pr');
            $all = $this->option('all') || ($config['all'] ?? false);

            $filePatterns = $this->option('file') ?: ($config['file'] ?? null) ?: null;

            $result = $action->execute(
                repoPath: $repoPath,
                outputPath: $outputPath,
                baseBranch: $baseBranch,
                prUrl: $prUrl ?: null,
                all: $all,
                title: $title,
                view: $view,
                format: $format,
                minSeverity: $minSeverity,
                watchedFiles: $config['watched_files'] ?? null,
                filePatterns: $filePatterns ?: null,
                onProgress: null,
                raw: ! $openFile && $outputPath === null,
                includeFileContents: $includeFileContents,
                githubMetrics: $githubMetrics,
            );

            if (! $openFile) {
                $this->output->write($result['content'] ?? '');

                return self::SUCCESS;
            }

            if (empty($result['files'])) {
                return self::SUCCESS;
            }

            $firstFile = reset($result['files']);
            shell_exec('open '.escapeshellarg($firstFile));

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        $configPath = $this->option('config');

        if ($configPath === null) {
            return [];
        }

        if (! file_exists($configPath)) {
            throw new RuntimeException("Config file not found: {$configPath}");
        }

        $config = json_decode(file_get_contents($configPath), true);

        if (! is_array($config)) {
            throw new RuntimeException("Invalid JSON in config file: {$configPath}");
        }

        return $config;
    }

    /** @param array<string, mixed> $config */
    private function resolveGroupResolver(array $config): FileGroupResolver
    {
        if (isset($config['file_groups']) && is_array($config['file_groups'])) {
            return new ArrayFileGroupResolver($config['file_groups']);
        }

        return new PatternBasedGroupResolver;
    }
}
