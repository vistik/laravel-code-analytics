<?php

namespace Vistik\LaravelCodeAnalytics\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use RuntimeException;
use Throwable;
use Vistik\LaravelCodeAnalytics\Actions\AnalyzeCode;
use Vistik\LaravelCodeAnalytics\Actions\GenerateJsonReport;
use Vistik\LaravelCodeAnalytics\Actions\GenerateLlmReport;
use Vistik\LaravelCodeAnalytics\Ai\Agents\CodeReviewAgent;
use Vistik\LaravelCodeAnalytics\Ai\Tools\RunCodeAnalysis;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\ArrayFileGroupResolver;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts\FileGroupResolver;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\Enums\GraphLayout;
use Vistik\LaravelCodeAnalytics\Enums\OutputFormat;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;

use function Laravel\Prompts\select;

class CodeAnalyzeCommand extends Command
{
    protected $signature = 'code:analyze
        {repo-path? : Path to the local git repo (defaults to current working directory)}
        {output? : Output file path (HTML, Markdown, or JSON depending on --format)}
        {--base= : Base branch or commit to diff against (default: main). Not needed with --pr= (auto-detected from the PR). Use HEAD to see only uncommitted changes}
        {--from= : Start commit hash for a range diff (e.g. abc1234). Use with --to or omit --to to use HEAD}
        {--to= : End commit hash for a range diff (defaults to HEAD when --from is set)}
        {--pick : Interactively pick two commits from git history to compare}
        {--pr= : GitHub PR URL to analyze remotely (e.g. https://github.com/owner/repo/pull/123)}
        {--full : Analyze all tracked files instead of just the diff}
        {--title= : Custom title for the analysis report}
        {--view= : Default graph view to show (force, tree, grouped, cake, arch)}
        {--config= : Path to a JSON config file (supports: repo_path, output, base, pr, title, view, format, open, file_groups, min_severity, file, full)}
        {--format=html : Output format: html, md, json, metrics, llm, or github}
        {--output= : Output file or directory path (alternative to the positional output argument)}
        {--min-severity= : Minimum severity to include (info, low, medium, high, very_high) — files with only lower-severity changes are excluded}
        {--file=* : Only analyze files matching this path or glob pattern (can be repeated)}
        {--folder=* : Only analyze files under this directory prefix (can be repeated, e.g. --folder=src)}
        {--ext=* : Only analyze files with this extension (can be repeated, e.g. --ext=php)}
        {--open : Open the generated file in the browser when done}
        {--full-files : Embed full file contents in the report to enable the "Full file" diff view (increases report size)}
        {--github-metrics : Include per-class and per-method PHP metrics as inline annotations (only applies to --format=github)}
        {--review : Generate an AI review summary and embed it in the HTML report (requires Ollama running locally)}';

    protected $description = 'Analyze a local branch diff — AST analysis, risk scoring, and interactive graph';

    public function handle(AnalyzeCode $action): int
    {
        try {
            $config = $this->loadConfig();

            $repoPath = $this->argument('repo-path') ?? $config['repo_path'] ?? getcwd();
            $outputPath = $this->option('output') ?? $this->argument('output') ?? $config['output'] ?? null;
            $prUrl = $this->option('pr');
            // When --pr= is given, the base is auto-detected from the PR; --base= is only needed for local mode.
            $baseBranch = $this->option('base') ?? $config['base'] ?? ($prUrl !== null ? null : 'main');
            $title = $this->option('title') ?? $config['title'] ?? null;
            $viewString = $this->option('view') ?? $config['view'] ?? null;
            $view = $viewString !== null
                ? (GraphLayout::tryFrom($viewString) ?? throw new RuntimeException("Invalid view: {$viewString}. Valid options: force, tree, grouped, cake, arch"))
                : GraphLayout::Force;
            $formatString = $this->option('format') ?? $config['format'] ?? 'html';
            $format = OutputFormat::tryFrom($formatString)
                ?? throw new RuntimeException("Invalid format: {$formatString}. Valid options: html, md, json, metrics, llm, github");
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

            $fileGroups = $config['file_groups'] ?? config('laravel-code-analytics.file_groups');
            if ($fileGroups !== null) {
                $action = new AnalyzeCode(groupResolver: $this->resolveGroupResolver($fileGroups));
            }

            $explicitFiles = $this->option('file') ?: ($config['file'] ?? []);

            $full = $this->option('full') || ($config['full'] ?? false);

            [$fromCommit, $toCommit] = $this->resolveCommitRange($repoPath);

            $folderPatterns = array_map(
                fn (string $f) => rtrim($f, '/').'/',
                $this->option('folder') ?: ($config['folder'] ?? []),
            );
            $extPatterns = array_map(
                fn (string $e) => '*.'.ltrim(ltrim($e, '*'), '.'),
                $this->option('ext') ?: ($config['ext'] ?? []),
            );
            // For the LLM format, --file= acts as a display focus rather than a graph filter so
            // that incoming edges (files that depend on the focused file) are also captured.
            $focusFiles = $format === OutputFormat::LLM && ! empty($explicitFiles) ? $explicitFiles : null;
            $filePatterns = array_merge(
                $focusFiles !== null ? [] : $explicitFiles,
                $folderPatterns,
                $extPatterns,
            ) ?: null;

            $raw = ! $openFile && $outputPath === null;

            $onProgress = $raw ? null : function (string $level, string $message): void {
                match ($level) {
                    'info' => $this->info($message),
                    'warn' => $this->warn($message),
                    'timing' => $this->output->isVerbose() ? $this->line($message) : null,
                    default => $this->line($message),
                };
            };

            $onPayloadReady = $this->buildOnPayloadReady($format, $repoPath, $baseBranch, $prUrl, $full, $filePatterns, $fromCommit, $toCommit, $minSeverity, $focusFiles);

            $result = $action->execute(
                repoPath: $repoPath,
                outputPath: $outputPath,
                baseBranch: $baseBranch,
                prUrl: $prUrl ?: null,
                full: $full,
                title: $title,
                view: $view,
                format: $format,
                minSeverity: $minSeverity,
                watchedFiles: $config['watched_files'] ?? null,
                filePatterns: $filePatterns ?: null,
                onProgress: $onProgress,
                raw: $raw,
                includeFileContents: $includeFileContents,
                githubMetrics: $githubMetrics,
                filterDefaults: $config['filter_defaults'] ?? [],
                riskScoringConfig: $config['risk_scoring'] ?? [],
                criticalTables: $config['critical_tables'] ?? [],
                fromCommit: $fromCommit,
                toCommit: $toCommit,
                focusFiles: $focusFiles,
                onPayloadReady: $onPayloadReady,
            );

            if (isset($result['content'])) {
                $this->output->write($result['content']);
            }

            if (! $openFile || empty($result['files'])) {
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

    /**
     * Resolve the from/to commit range from --from/--to options or the interactive --pick flow.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveCommitRange(string $repoPath): array
    {
        $fromOption = $this->option('from');
        $toOption = $this->option('to');
        $pick = $this->option('pick');

        if (! $pick && $fromOption === null && $toOption === null) {
            return [null, null];
        }

        $logLines = array_filter(
            explode("\n", trim(shell_exec('git -C '.escapeshellarg($repoPath).' log --oneline -40 2>/dev/null') ?? ''))
        );

        if (empty($logLines)) {
            throw new RuntimeException('Could not read git log. Is this a git repository?');
        }

        if ($pick) {
            $commits = array_values($logLines);

            $fromChoice = select(
                label: 'Select base commit (from):',
                options: $commits,
                scroll: 12,
            );
            $fromCommit = substr($fromChoice, 0, 7);

            $toOptions = array_merge(['HEAD (working tree)'], array_values(array_filter($commits, fn ($c) => $c !== $fromChoice)));
            $toChoice = select(
                label: 'Select head commit (to):',
                options: $toOptions,
                default: 'HEAD (working tree)',
                scroll: 12,
            );
            $toCommit = $toChoice === 'HEAD (working tree)' ? null : substr($toChoice, 0, 7);

            if ($fromCommit === ($toCommit ?? 'HEAD')) {
                throw new RuntimeException('The from and to commits must be different.');
            }

            return [$fromCommit, $toCommit];
        }

        return [$fromOption, $toOption];
    }

    /** @param array<string, list<string>> $fileGroups */
    private function resolveGroupResolver(array $fileGroups): FileGroupResolver
    {
        return new ArrayFileGroupResolver($fileGroups);
    }

    /**
     * Returns an onPayloadReady closure when --review is set for HTML format,
     * null otherwise. The closure pre-computes LLM/JSON text from the payload
     * (avoiding a second analysis run) then runs the AI agent.
     *
     * @param  list<string>|null  $filePatterns
     * @param  list<string>|null  $focusFiles
     */
    private function buildOnPayloadReady(
        OutputFormat $format,
        string $repoPath,
        ?string $baseBranch,
        ?string $prUrl,
        bool $full,
        ?array $filePatterns,
        ?string $fromCommit,
        ?string $toCommit,
        ?Severity $minSeverity,
        ?array $focusFiles,
    ): ?Closure {
        if (! $this->option('review') || $format !== OutputFormat::HTML) {
            return null;
        }

        return function (GraphPayload $payload, PullRequestContext $pr, LayerStack $layerStack) use ($focusFiles, $repoPath, $baseBranch, $prUrl, $full, $filePatterns, $fromCommit, $toCommit, $minSeverity): array {
            $llm = (new GenerateLlmReport($focusFiles))->generate($payload, $pr, null, $layerStack);
            $json = (new GenerateJsonReport)->generate($payload, $pr, null, $layerStack);

            $tool = (new RunCodeAnalysis(
                repoPath: $repoPath,
                baseBranch: $baseBranch,
                prUrl: $prUrl,
                full: $full,
                filePatterns: $filePatterns,
                fromCommit: $fromCommit,
                toCommit: $toCommit,
                minSeverity: $minSeverity,
            ))->withPrecomputed($llm, $json);

            $this->info('Generating AI review...');

            try {
                $reviewText = (string) (new CodeReviewAgent($tool))->prompt('Review these changes.');
            } catch (RequestException $e) {
                $status = $e->response->status();
                $this->warn(match (true) {
                    $status === 401 || $status === 403 => 'AI review skipped: authentication failed. Ensure Ollama is running locally (or the correct API key is set for a remote provider).',
                    $status === 404 => 'AI review skipped: model not found. Run `ollama pull llama3.1:8b` to install it, or override with --model=<name>.',
                    default => "AI review skipped: HTTP {$status}.",
                });

                return [];
            } catch (ProviderOverloadedException $e) {
                throw new RuntimeException($e->getMessage(), previous: $e);
            } catch (Throwable $e) {
                $this->warn('AI review skipped: '.$e->getMessage());

                return [];
            }

            return ['aiReview' => $reviewText];
        };
    }
}
