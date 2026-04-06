<?php

namespace Vistik\LaravelCodeAnalytics\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Vistik\LaravelCodeAnalytics\Actions\AnalyzeCode;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\ArrayFileGroupResolver;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts\FileGroupResolver;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\PatternBasedGroupResolver;
use Vistik\LaravelCodeAnalytics\Enums\OutputFormat;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

class CodeAnalyzeCommand extends Command
{
    protected $signature = 'code:analyze
        {repo-path? : Path to the local git repo (defaults to current working directory)}
        {output? : Output file path (HTML, Markdown, or JSON depending on --format)}
        {--base= : Base branch or commit to diff against (default: main)}
        {--pr= : GitHub PR URL to analyze remotely (e.g. https://github.com/owner/repo/pull/123)}
        {--title= : Custom title for the analysis report}
        {--view= : Default graph view to show (force, tree, grouped, cake, arch)}
        {--config= : Path to a JSON config file (supports: repo_path, output, base, pr, title, view, format, open, file_groups)}
        {--format=html : Output format: html, md, or json}
        {--open : Open the generated file in the browser when done}';

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
                ?? throw new RuntimeException("Invalid format: {$formatString}. Valid options: html, md, json");
            $openFile = $this->option('open') || ($config['open'] ?? false);

            if (isset($config['file_groups'])) {
                $action = new AnalyzeCode(groupResolver: $this->resolveGroupResolver($config));
            }

            $prUrl = $this->option('pr');

            $result = $action->execute(
                repoPath: $repoPath,
                outputPath: $outputPath,
                baseBranch: $baseBranch,
                prUrl: $prUrl ?: null,
                title: $title,
                view: $view,
                format: $format,
                onProgress: function (string $level, string $message): void {
                    match ($level) {
                        'info' => $this->info($message),
                        'warn' => $this->warn($message),
                        'error' => $this->error($message),
                        default => $this->line($message),
                    };
                },
            );

            if (empty($result['files'])) {
                return self::SUCCESS;
            }

            $risk = $result['risk'];
            $this->newLine();
            $this->printRiskScore($risk);

            $firstFile = reset($result['files']);

            if ($openFile) {
                shell_exec('open '.escapeshellarg($firstFile));
            } else {
                $this->newLine();
                $this->line('Open with: <info>open '.escapeshellarg($firstFile).'</info>');
            }

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

    private function printRiskScore(RiskScore $risk): void
    {
        $score = $risk->score;
        $label = match (true) {
            $score >= 75 => '<fg=red>Very High</>',
            $score >= 50 => '<fg=yellow>High</>',
            $score >= 25 => '<fg=cyan>Medium</>',
            default => '<fg=green>Low</>',
        };

        $this->line("Risk Score: <fg=white;options=bold>{$score}/100</> — {$label}");
        $this->newLine();

        $rows = [];
        foreach ($risk->factors as $factor) {
            $pct = $factor['maxScore'] > 0
                ? (int) round(($factor['score'] / $factor['maxScore']) * 100)
                : 0;

            $bar = str_repeat('█', (int) round($pct / 10)).str_repeat('░', 10 - (int) round($pct / 10));
            $rows[] = [
                $factor['name'],
                $bar,
                "{$factor['score']}/{$factor['maxScore']}",
            ];
        }

        $this->table(['Factor', 'Score', 'Points'], $rows);
    }
}
