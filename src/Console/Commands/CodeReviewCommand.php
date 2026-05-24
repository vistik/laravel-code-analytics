<?php

namespace Vistik\LaravelCodeAnalytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\AiException;
use RuntimeException;
use Throwable;
use Vistik\LaravelCodeAnalytics\Ai\Agents\CodeReviewAgent;
use Vistik\LaravelCodeAnalytics\Ai\Tools\RunCodeAnalysis;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class CodeReviewCommand extends Command
{
    protected $signature = 'code:review
        {repo-path? : Path to the local git repo (defaults to current working directory)}
        {--base= : Base branch or commit to diff against (default: main)}
        {--pr= : GitHub PR URL to analyze remotely (e.g. https://github.com/owner/repo/pull/123)}
        {--full : Analyze all tracked files instead of just the diff}
        {--from= : Start commit hash for a range diff}
        {--to= : End commit hash (defaults to HEAD when --from is set)}
        {--file=* : Only analyze files matching this path or glob pattern (can be repeated)}
        {--folder=* : Only analyze files under this directory prefix (can be repeated)}
        {--ext=* : Only analyze files with this extension (can be repeated)}
        {--min-severity= : Minimum severity to include (info, low, medium, high, very_high)}
        {--model= : Override the AI model (default: llama3.1:8b)}';

    protected $description = 'AI-powered review overview — groups changes and highlights where to look first';

    public function handle(): int
    {
        $repoPath = $this->argument('repo-path') ?? getcwd();
        $prUrl = $this->option('pr');
        $baseBranch = $this->option('base') ?? ($prUrl !== null ? null : 'main');

        $minSeverityString = $this->option('min-severity');
        $minSeverity = $minSeverityString !== null
            ? (Severity::tryFrom($minSeverityString) ?? throw new RuntimeException("Invalid min-severity: {$minSeverityString}. Valid options: info, low, medium, high, very_high"))
            : null;

        $filePatterns = array_merge(
            $this->option('file') ?: [],
            array_map(fn (string $f) => rtrim($f, '/').'/', $this->option('folder') ?: []),
            array_map(fn (string $e) => '*.'.ltrim(ltrim($e, '*'), '.'), $this->option('ext') ?: []),
        ) ?: null;

        $tool = new RunCodeAnalysis(
            repoPath: $repoPath,
            baseBranch: $baseBranch,
            prUrl: $prUrl ?: null,
            full: (bool) $this->option('full'),
            filePatterns: $filePatterns,
            fromCommit: $this->option('from'),
            toCommit: $this->option('to'),
            minSeverity: $minSeverity,
        );

        $agent = new CodeReviewAgent($tool);

        $this->line('<fg=yellow>Running analysis and generating review overview...</>');
        $this->newLine();

        try {
            $model = $this->option('model');

            $response = $model !== null
                ? $agent->prompt('Review these changes.', model: $model)
                : $agent->prompt('Review these changes.');

            $this->line((string) $response);
        } catch (RequestException $e) {
            $status = $e->response->status();
            if ($status === 401 || $status === 403) {
                $this->error('Authentication failed. Ensure Ollama is running locally (or set the correct API key for a remote provider).');
            } elseif ($status === 404) {
                $this->error('Model not found. Run `ollama pull llama3.1:8b` to install it, or override with --model=<name>.');
            } elseif ($status === 429) {
                $this->error('Rate limited by the AI provider. Please try again in a moment.');
            } else {
                $this->error("AI provider request failed (HTTP {$status}): ".$e->getMessage());
            }

            return self::FAILURE;
        } catch (AiException $e) {
            $this->error('AI error: '.$e->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Unexpected error: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
