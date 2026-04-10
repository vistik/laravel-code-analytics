<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Closure;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\ChangeClassifier;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts\FileGroupResolver;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\DiffParser;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\LaravelMigrationModelCorrelator;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\PatternBasedGroupResolver;
use Vistik\LaravelCodeAnalytics\Enums\NodeGroup;
use Vistik\LaravelCodeAnalytics\Enums\OutputFormat;
use Vistik\LaravelCodeAnalytics\FileSignal\CalculateFileSignal;
use Vistik\LaravelCodeAnalytics\FileSignal\FileSignalScoring;
use Vistik\LaravelCodeAnalytics\RiskScoring\CalculateRiskScore;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScoring;
use Vistik\LaravelCodeAnalytics\Support\JsMetrics;
use Vistik\LaravelCodeAnalytics\Support\JsMetricsRunner;
use Vistik\LaravelCodeAnalytics\Support\PhpDependencyExtractor;
use Vistik\LaravelCodeAnalytics\Support\PhpMethodMetricsCalculator;
use Vistik\LaravelCodeAnalytics\Support\PhpMetrics;
use Vistik\LaravelCodeAnalytics\Support\PhpMetricsRunner;

class AnalyzeCode
{
    private RiskScoring $riskScorer;

    private FileSignalScoring $fileSignalScorer;

    private string $repoPath = '';

    private string $headCommit;

    private string $baseCommit;

    private string $branchName;

    private string $diff;

    private bool $isLaravel;

    /** Bare-clone path when analyzing a remote GitHub PR (null in local mode) */
    private ?string $repoDir = null;

    /** GitHub "owner/repo" when analyzing a remote PR (empty in local mode) */
    private string $prRepo = '';

    /** @var array<string, string> */
    private array $fqcnToNode = [];

    /** @var array<string, string> */
    private array $pathToNode = [];

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $edges = [];

    /** @var array<string, true> */
    private array $edgeSet = [];

    private ?Closure $onProgress;

    public function __construct(
        private readonly FileGroupResolver $groupResolver = new PatternBasedGroupResolver,
        ?RiskScoring $riskScorer = null,
        ?FileSignalScoring $fileSignalScorer = null,
    ) {
        $this->riskScorer = $riskScorer ?? new CalculateRiskScore;
        $this->fileSignalScorer = $fileSignalScorer ?? new CalculateFileSignal;
    }

    /**
     * @return array{files: array<string, string>, risk: RiskScore, content?: string}
     */
    /**
     * @return array{files: array<string, string>, risk: RiskScore, content?: string}
     */
    public function execute(
        string $repoPath = '',
        ?string $outputPath = null,
        string $baseBranch = 'main',
        ?string $prUrl = null,
        bool $all = false,
        ?string $title = null,
        ?string $view = null,
        OutputFormat $format = OutputFormat::HTML,
        ?Severity $minSeverity = null,
        ?Closure $onProgress = null,
        ?array $watchedFiles = null,
        ?array $filePatterns = null,
        bool $raw = false,
        bool $includeFileContents = false,
        bool $githubMetrics = false,
    ): array {
        $this->onProgress = $onProgress;
        $this->fqcnToNode = [];
        $this->pathToNode = [];
        $this->edges = [];
        $this->edgeSet = [];
        $this->repoDir = null;
        $this->prRepo = '';

        if ($prUrl !== null) {
            // ── GitHub PR mode ───────────────────────────────────────────────
            $init = $this->initFromPrUrl($prUrl);
            $init['prLinkUrl'] = $prUrl;
        } else {
            // ── Local mode ───────────────────────────────────────────────────
            $this->repoPath = rtrim(realpath($repoPath) ?: $repoPath, '/');
            $init = $this->initLocalMode($baseBranch, $title, $all);
        }

        $files = $init['files'];
        $totalAdditions = $init['totalAdditions'];
        $totalDeletions = $init['totalDeletions'];
        $repoName = $init['repoName'];
        $prTitle = $init['prTitle'];
        $prLinkUrl = $init['prLinkUrl'];

        if (empty($files)) {
            return ['files' => [], 'risk' => new RiskScore(0)];
        }

        // ── Apply file pattern filter ─────────────────────────────────────────
        if ($filePatterns !== null) {
            $files = array_values(array_filter($files, function (array $file) use ($filePatterns): bool {
                foreach ($filePatterns as $pattern) {
                    if (fnmatch($pattern, $file['path']) || str_contains($file['path'], $pattern)) {
                        return true;
                    }
                }

                return false;
            }));

            $totalAdditions = array_sum(array_column($files, 'additions'));
            $totalDeletions = array_sum(array_column($files, 'deletions'));

            if (empty($files)) {
                $this->progress('warn', 'No files matched the --file filter.');

                return ['files' => [], 'risk' => new RiskScore(0)];
            }

            $this->progress('line', '  File filter applied: '.implode(', ', $filePatterns));
        }

        $fileCount = count($files);
        $this->progress('line', "  Files: {$fileCount}, +{$totalAdditions} -{$totalDeletions}");

        // ── Parse diff for file statuses ─────────────────────────────────────
        $diffParser = new DiffParser;
        $parsedFileDiffs = $diffParser->parse($this->diff);
        $fileDiffMap = [];
        foreach ($parsedFileDiffs as $fd) {
            $fileDiffMap[$fd->effectivePath()] = $fd;
        }

        // ── Build nodes ──────────────────────────────────────────────────────
        $this->progress('info', 'Classifying files...');

        $watchedFiles ??= config('analysis.watched_files') ?? config('laravel-code-analytics.watched_files', []);

        $nodes = [];
        foreach ($files as $file) {
            $node = $this->classifyFile($file, $fileDiffMap);

            foreach ($watchedFiles as $watch) {
                if ($this->matchesWatchPattern($file['path'], $watch['pattern'] ?? '')) {
                    $node['watched'] = true;
                    $node['watchReason'] = $watch['reason'] ?? null;
                    break;
                }
            }

            $nodes[] = $node;
        }

        $nodes = $this->resolveNodeLabels($nodes);
        $nodes = $this->assignDomainColors($nodes);

        // ── Fetch file contents from HEAD ─────────────────────────────────────
        $this->progress('info', 'Reading file contents...');

        $frontendExts = ['jsx', 'tsx', 'vue', 'js', 'ts'];
        $phpFiles = array_filter($nodes, fn ($n) => str_ends_with($n['path'], '.php'));
        $frontendFiles = array_filter($nodes, fn ($n) => in_array($n['ext'], $frontendExts));

        $allFilePaths = array_merge(
            array_column(array_values($phpFiles), 'path'),
            array_column(array_values($frontendFiles), 'path'),
        );

        if ($this->repoDir !== null) {
            // PR URL mode: read from the bare git clone via cat-file --batch
            $headContents = $this->readFileContentsFromGit($allFilePaths);
        } else {
            // Local mode: read directly from the working tree — this naturally
            // includes any staged or unstaged modifications git diff picked up.
            $headContents = [];
            foreach ($allFilePaths as $path) {
                $fullPath = "{$this->repoPath}/{$path}";
                if (is_file($fullPath)) {
                    $content = file_get_contents($fullPath);
                    $headContents[$path] = $content !== false && $content !== '' ? $content : null;
                } else {
                    $headContents[$path] = null;
                }
            }
        }

        // ── Build dependency maps ────────────────────────────────────────────
        $this->progress('info', 'Extracting dependencies...');

        // Build FQCN → path map from actual file contents so any namespace structure works
        $fqcnToFilePath = [];
        foreach ($headContents as $path => $content) {
            if ($content === null || $content === '' || ! str_ends_with($path, '.php')) {
                continue;
            }
            $fqcn = $this->extractFqcnFromContent($content);
            if ($fqcn !== null) {
                $fqcnToFilePath[$fqcn] = $path;
            }
        }

        // Also build path → FQCN reverse map for fqcnToNode building
        $filePathToFqcn = array_flip($fqcnToFilePath);

        foreach ($nodes as $node) {
            $this->pathToNode[$node['path']] = $node['id'];
            if (str_ends_with($node['path'], '.php')) {
                $fqcn = $filePathToFqcn[$node['path']] ?? $this->pathToFqcn($node['path']);
                if ($fqcn) {
                    $this->fqcnToNode[$fqcn] = $node['id'];
                }
            }
        }

        $componentNameToNode = [];
        foreach ($nodes as $n) {
            if (in_array($n['ext'], $frontendExts)) {
                $basename = pathinfo($n['path'], PATHINFO_FILENAME);
                if (strtolower($basename) !== 'index') {
                    $componentNameToNode[$basename] = $n['id'];
                }
            }
        }

        foreach ($phpFiles as $node) {
            $content = $headContents[$node['path']] ?? null;
            if ($content === null || $content === '') {
                continue;
            }
            $references = $this->extractReferences($content);
            $this->matchReferences($references, $node['id']);
            $this->matchViewReferences($content, $node['id']);
        }

        foreach ($frontendFiles as $node) {
            $content = $headContents[$node['path']] ?? null;
            if ($content === null || $content === '') {
                continue;
            }
            $this->matchComponentReferences($content, $node['id'], $componentNameToNode);
        }

        $this->progress('line', '  Found '.count($this->edges).' dependencies.');

        // ── Run AST analysis ─────────────────────────────────────────────────
        $this->progress('info', 'Running AST analysis...');

        $astComparer = new AstComparer;
        $changeClassifier = new ChangeClassifier($astComparer, $this->isLaravel, $this->repoPath ?: null);
        $oldSources = $this->fetchOldSources($phpFiles, $fileDiffMap);

        $fileReports = [];
        foreach ($phpFiles as $node) {
            $filePath = $node['path'];
            $fileDiff = $fileDiffMap[$filePath] ?? null;
            if ($fileDiff === null) {
                continue;
            }
            $oldSource = $oldSources[$filePath] ?? null;
            $newSource = $fileDiff->status !== FileStatus::DELETED ? ($headContents[$filePath] ?? null) : null;
            $comparison = $astComparer->compare($oldSource, $newSource);
            $fileReport = $changeClassifier->classify($fileDiff, $comparison, $newSource);
            $fileReports[$filePath] = $fileReport;
        }

        $this->progress('line', '  Analyzed '.count($fileReports).' PHP files.');

        if ($this->isLaravel) {
            // In PR URL mode the bare clone can't be used to scan non-PR models, so pass null.
            $fileReports = (new LaravelMigrationModelCorrelator)->correlate($fileReports, $headContents, $this->repoDir !== null ? null : $this->repoPath);
        }

        // ── Enrich nodes with analysis data ──────────────────────────────────
        foreach ($nodes as &$node) {
            $report = $fileReports[$node['path']] ?? null;
            $changes = $report->changes ?? [];
            $node['severity'] = ! empty($changes) ? $report->maxSeverity()->value : null;
            $node['analysisCount'] = count($changes);
            $node['veryHighCount'] = count(array_filter($changes, fn ($c) => $c->severity === Severity::VERY_HIGH));
            $node['highCount'] = count(array_filter($changes, fn ($c) => $c->severity === Severity::HIGH));
            $node['mediumCount'] = count(array_filter($changes, fn ($c) => $c->severity === Severity::MEDIUM));
            $node['lowCount'] = count(array_filter($changes, fn ($c) => $c->severity === Severity::LOW));
            $node['infoCount'] = count(array_filter($changes, fn ($c) => $c->severity === Severity::INFO));
        }
        unset($node);

        // ── Build analysis data for embedding ────────────────────────────────
        $analysisData = [];
        foreach ($fileReports as $filePath => $report) {
            $analysisData[$filePath] = array_map(fn ($c) => array_filter([
                'category' => $c->category->value,
                'severity' => $c->severity->value,
                'description' => $c->description,
                'location' => $c->location,
                'line' => $c->line,
            ], fn ($v) => $v !== null), $report->changes);
        }

        // ── Compute metrics ───────────────────────────────────────────────────
        ['hotSpots' => $phpHotSpots, 'metricsData' => $metricsData] = $this->computePhpMetrics($headContents, $oldSources, $fqcnToFilePath);
        ['hotSpots' => $jsHotSpots, 'metricsData' => $jsMetricsData] = $this->computeJsMetrics($frontendFiles, $headContents, $oldSources);
        $metricsData = array_merge($metricsData, $jsMetricsData);

        // ── Extract per-file diffs ────────────────────────────────────────────
        $fileDiffs = $this->extractFileDiffs();

        // ── Collect full file contents for "Full file" diff view ─────────────
        $fileContents = [];
        if ($includeFileContents) {
            $diffPaths = array_keys($fileDiffs);
            if (! empty($diffPaths)) {
                if ($this->repoDir !== null) {
                    $rawContents = $this->readFileContentsFromGit($diffPaths);
                } else {
                    $rawContents = [];
                    foreach ($diffPaths as $path) {
                        $fullPath = "{$this->repoPath}/{$path}";
                        if (is_file($fullPath)) {
                            $content = file_get_contents($fullPath);
                            $rawContents[$path] = $content !== false ? $content : null;
                        }
                    }
                }
                foreach ($rawContents as $path => $content) {
                    if ($content !== null && strlen($content) <= 500_000) {
                        $fileContents[$path] = $content;
                    }
                }
            }
        }

        // ── Compute per-file signal score ─────────────────────────────────────
        foreach ($nodes as &$node) {
            $node['_signal'] = $this->fileSignalScorer->calculate(
                $node,
                $analysisData[$node['path']] ?? [],
                $metricsData[$node['path']] ?? null,
            );
        }
        unset($node);

        // ── Apply minSeverity filter ──────────────────────────────────────────
        if ($minSeverity !== null) {
            ['nodes' => $nodes, 'analysisData' => $analysisData, 'metricsData' => $metricsData, 'fileDiffs' => $fileDiffs, 'fileContents' => $fileContents]
                = (new MinSeverityFilter)->apply($nodes, $analysisData, $metricsData, $fileDiffs, $minSeverity, $fileContents);

            $fileCount = count($nodes);
            $totalAdditions = array_sum(array_column($nodes, 'add'));
            $totalDeletions = array_sum(array_column($nodes, 'del'));

            $this->progress('line', "  After min-severity filter ({$minSeverity->value}): {$fileCount} files.");
        }

        // ── Compute overall risk score ────────────────────────────────────────
        $riskResult = $this->riskScorer->calculate($nodes, $totalAdditions, $totalDeletions, $fileCount, $phpHotSpots + $jsHotSpots);

        // ── Generate report ───────────────────────────────────────────────────
        $this->progress('info', "Generating {$format->value} report...");

        $reportGenerator = $format->generator(['metrics' => $githubMetrics]);
        $content = $reportGenerator->generate(
            nodes: $nodes,
            edges: $this->edges,
            fileDiffs: $fileDiffs,
            analysisData: $analysisData,
            title: $prTitle,
            repo: $repoName,
            headCommit: $this->headCommit,
            prAdditions: $totalAdditions,
            prDeletions: $totalDeletions,
            fileCount: $fileCount,
            prUrl: $prLinkUrl,
            riskScore: $riskResult,
            metricsData: $metricsData,
            fileContents: $fileContents,
        );

        if ($raw) {
            return ['files' => [], 'risk' => $riskResult, 'content' => $content];
        }

        if ($outputPath === null) {
            $outputDir = base_path('output');
            if (! is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $safeBranch = preg_replace('/[^a-zA-Z0-9._-]/', '-', $this->branchName);
            $ext = $format->fileExtension();
            $outputPath = $this->repoDir !== null
                ? "{$outputDir}/pr-".preg_replace('/[^0-9]/', '', $this->branchName).".{$ext}"
                : "{$outputDir}/local-{$safeBranch}.{$ext}";
        }

        $reportGenerator->writeFile($outputPath, $content);

        $this->progress('line', "  Generated: {$outputPath}");
        $this->progress('info', 'Done!');

        return ['files' => ['all' => $outputPath], 'risk' => $riskResult];
    }

    /**
     * Fetch PR metadata and diff from GitHub, shallow-clone git objects, and
     * populate all internal state so the shared analysis pipeline can proceed.
     *
     * @return array{files: list<array{path: string, additions: int, deletions: int}>, totalAdditions: int, totalDeletions: int, repoName: string, prTitle: string}
     */
    private function initFromPrUrl(string $prUrl): array
    {
        if (! preg_match('#https?://github\.com/([^/]+/[^/]+)/pull/(\d+)#', $prUrl, $m)) {
            throw new RuntimeException('Invalid GitHub PR URL. Expected: https://github.com/owner/repo/pull/123');
        }

        $this->prRepo = $m[1];
        $prNumber = $m[2];

        $this->progress('info', "Fetching PR #{$prNumber} from {$this->prRepo}...");

        $prJson = json_decode(
            trim(shell_exec('gh pr view '.escapeshellarg($prNumber).' --repo '.escapeshellarg($this->prRepo).' --json title,additions,deletions,files,headRefOid,baseRefOid,headRefName,baseRefName 2>/dev/null') ?? ''),
            true,
        );

        if (! $prJson || empty($prJson['files'])) {
            throw new RuntimeException('Could not fetch PR data. Make sure `gh` is authenticated and the PR URL is valid.');
        }

        $this->headCommit = $prJson['headRefOid'] ?? '';
        $this->baseCommit = $prJson['baseRefOid'] ?? '';
        $this->branchName = "PR #{$prNumber}";
        $this->isLaravel = false;

        $this->progress('line', '  Title: '.$prJson['title']);
        $this->progress('line', '  HEAD: '.substr($this->headCommit, 0, 7).'  Base: '.substr($this->baseCommit, 0, 7));

        // ── Shallow-fetch git objects so we can read file contents and old sources
        if (! empty($this->headCommit)) {
            $persistentDir = storage_path('app/git-objects/'.substr($this->headCommit, 0, 2).'/'.$this->headCommit);
            $alreadyCached = is_dir($persistentDir)
                && trim(shell_exec("git -C {$persistentDir} cat-file -t {$this->headCommit} 2>/dev/null") ?? '') === 'commit';

            if ($alreadyCached) {
                $this->repoDir = $persistentDir;
                $this->progress('line', '  Using cached git objects.');
            } else {
                $this->progress('info', 'Fetching git objects (shallow)...');
                mkdir($persistentDir, 0755, true);
                shell_exec("git init --bare {$persistentDir} 2>&1");
                shell_exec("git -C {$persistentDir} remote add origin https://github.com/{$this->prRepo}.git 2>&1");

                $changedPaths = array_column($prJson['files'], 'path');
                $phpPaths = array_values(array_filter($changedPaths, fn ($p) => str_ends_with($p, '.php')));

                shell_exec("git -C {$persistentDir} fetch --depth 1 --filter=blob:none origin {$this->headCommit} 2>&1");

                if (! empty($this->baseCommit)) {
                    shell_exec("git -C {$persistentDir} fetch --depth 1 --filter=blob:none origin {$this->baseCommit} 2>&1");
                }

                $verify = trim(shell_exec("git -C {$persistentDir} cat-file -t {$this->headCommit} 2>/dev/null") ?? '');
                if ($verify === 'commit') {
                    $this->repoDir = $persistentDir;
                    $this->prefetchBlobs($persistentDir, $this->headCommit, $changedPaths);
                    if (! empty($this->baseCommit) && ! empty($phpPaths)) {
                        $this->prefetchBlobs($persistentDir, $this->baseCommit, $phpPaths);
                    }
                    $this->progress('line', '  Cached git objects locally.');
                } else {
                    $this->progress('warn', '  Could not fetch git objects; file-level analysis may be limited.');
                    shell_exec('rm -rf '.escapeshellarg($persistentDir));
                }
            }

            // Detect Laravel by checking for the artisan file in head commit
            if ($this->repoDir !== null) {
                $artisanType = trim(shell_exec("git -C {$this->repoDir} cat-file -t {$this->headCommit}:artisan 2>/dev/null") ?? '');
                $this->isLaravel = $artisanType === 'blob';
            }
        }

        // ── Fetch the PR diff ────────────────────────────────────────────────
        $this->progress('info', 'Fetching diff...');
        $this->diff = trim(shell_exec('gh pr diff '.escapeshellarg($prNumber).' --repo '.escapeshellarg($this->prRepo).' 2>/dev/null') ?? '');

        if (! str_contains($this->diff, 'diff --git')) {
            throw new RuntimeException('Failed to fetch PR diff. Make sure `gh` is authenticated.');
        }

        $totalAdditions = (int) ($prJson['additions'] ?? 0);
        $totalDeletions = (int) ($prJson['deletions'] ?? 0);

        $files = array_map(fn ($f) => [
            'path' => $f['path'],
            'additions' => (int) ($f['additions'] ?? 0),
            'deletions' => (int) ($f['deletions'] ?? 0),
        ], $prJson['files']);

        return [
            'files' => $files,
            'totalAdditions' => $totalAdditions,
            'totalDeletions' => $totalDeletions,
            'repoName' => basename($this->prRepo),
            'prTitle' => $prJson['title'],
        ];
    }

    /**
     * Read file contents from the bare git clone via a single cat-file --batch pass.
     *
     * @param  list<string>  $paths
     * @return array<string, string|null>
     */
    private function readFileContentsFromGit(array $paths): array
    {
        if (empty($paths) || $this->repoDir === null) {
            return array_fill_keys($paths, null);
        }

        $stdin = implode("\n", array_map(fn ($p) => "{$this->headCommit}:{$p}", $paths))."\n";
        $batchOutput = Process::input($stdin)->run("git -C {$this->repoDir} cat-file --batch")->output();

        $headContents = [];
        $pos = 0;
        $len = strlen($batchOutput);

        foreach ($paths as $path) {
            if ($pos >= $len) {
                $headContents[$path] = null;

                continue;
            }

            $nl = strpos($batchOutput, "\n", $pos);
            if ($nl === false) {
                $headContents[$path] = null;
                break;
            }

            $header = substr($batchOutput, $pos, $nl - $pos);
            $pos = $nl + 1;

            if (str_ends_with($header, ' missing')) {
                $headContents[$path] = null;

                continue;
            }

            $size = (int) (explode(' ', $header)[2] ?? 0);
            $content = $size > 0 ? substr($batchOutput, $pos, $size) : '';
            $pos += $size + 1;

            $headContents[$path] = $content !== '' ? $content : null;
        }

        return $headContents;
    }

    private function prefetchBlobs(string $repoDir, string $commit, array $filePaths): void
    {
        if (empty($filePaths)) {
            return;
        }

        $pathArgs = implode(' ', array_map('escapeshellarg', $filePaths));
        $treeListing = shell_exec("git -C {$repoDir} ls-tree {$commit} -- {$pathArgs} 2>/dev/null") ?? '';

        preg_match_all('/\b([0-9a-f]{40})\t/m', $treeListing, $matches);
        $blobShas = $matches[1];

        if (empty($blobShas)) {
            return;
        }

        $shaArgs = implode(' ', $blobShas);
        shell_exec("git -C {$repoDir} fetch origin {$shaArgs} 2>/dev/null");
    }

    /**
     * Initialize state from a local git repository.
     *
     * @return array{files: list<array{path: string, additions: int, deletions: int}>, totalAdditions: int, totalDeletions: int, repoName: string, prTitle: string, prLinkUrl: string}
     */
    private function initLocalMode(string $baseBranch, ?string $title, bool $all = false): array
    {
        $gitDir = trim(shell_exec("git -C {$this->repoPath} rev-parse --git-dir 2>/dev/null") ?? '');
        if ($gitDir === '') {
            throw new RuntimeException("Not a git repository: {$this->repoPath}");
        }

        $this->headCommit = trim(shell_exec("git -C {$this->repoPath} rev-parse HEAD 2>/dev/null") ?? '');
        $this->branchName = trim(shell_exec("git -C {$this->repoPath} rev-parse --abbrev-ref HEAD 2>/dev/null") ?? 'HEAD');

        if (empty($this->headCommit)) {
            throw new RuntimeException('Could not resolve HEAD commit.');
        }

        $repoName = basename($this->repoPath);
        $this->isLaravel = file_exists("{$this->repoPath}/artisan");

        if ($all) {
            // ── All-files mode: analyze entire working tree ───────────────────
            $prTitle = $title ?? "{$this->branchName} (all files)";
            $this->diff = '';

            $this->progress('info', "Analyzing {$repoName}: {$prTitle}...");
            $this->progress('line', '  HEAD: '.substr($this->headCommit, 0, 7));

            $lsOutput = trim(shell_exec("git -C {$this->repoPath} ls-files 2>/dev/null") ?? '');
            if (empty($lsOutput)) {
                throw new RuntimeException("No tracked files found in: {$this->repoPath}");
            }

            $files = [];
            foreach (array_filter(explode("\n", $lsOutput)) as $path) {
                $files[] = ['path' => $path, 'additions' => 0, 'deletions' => 0];
            }

            return compact('files', 'repoName', 'prTitle') + ['totalAdditions' => 0, 'totalDeletions' => 0, 'prLinkUrl' => ''];
        }

        // ── Diff mode (default) ───────────────────────────────────────────────
        $this->baseCommit = trim(shell_exec("git -C {$this->repoPath} rev-parse {$baseBranch} 2>/dev/null") ?? '');

        if (empty($this->baseCommit)) {
            throw new RuntimeException("Could not resolve base: {$baseBranch}");
        }

        $hasUncommitted = trim(shell_exec("git -C {$this->repoPath} status --porcelain 2>/dev/null") ?? '') !== '';
        $uncommittedSuffix = $hasUncommitted ? ' + uncommitted' : '';
        $prTitle = $title ?? "{$this->branchName} vs {$baseBranch}{$uncommittedSuffix}";

        $this->progress('info', "Analyzing {$repoName}: {$prTitle}...");
        $this->progress('line', '  HEAD: '.substr($this->headCommit, 0, 7).'  Base: '.substr($this->baseCommit, 0, 7));
        if ($hasUncommitted) {
            $this->progress('line', '  Including staged and unstaged working tree changes.');
        }

        $this->diff = shell_exec("git -C {$this->repoPath} diff {$baseBranch} 2>/dev/null") ?? '';

        if (! str_contains($this->diff, 'diff --git')) {
            $this->progress('warn', "No changes found between working tree and {$baseBranch}.");

            return ['files' => [], 'totalAdditions' => 0, 'totalDeletions' => 0, 'repoName' => $repoName, 'prTitle' => $prTitle, 'prLinkUrl' => ''];
        }

        $numstat = trim(shell_exec("git -C {$this->repoPath} diff --numstat {$baseBranch} 2>/dev/null") ?? '');
        $files = [];
        $totalAdditions = 0;
        $totalDeletions = 0;

        foreach (explode("\n", $numstat) as $line) {
            if (empty($line)) {
                continue;
            }

            // Format: <additions>\t<deletions>\t<path>  (or -\t-\t<path> for binary)
            $parts = explode("\t", $line, 3);
            if (count($parts) < 3) {
                continue;
            }

            [$add, $del, $path] = $parts;
            $add = is_numeric($add) ? (int) $add : 0;
            $del = is_numeric($del) ? (int) $del : 0;
            $files[] = ['path' => $path, 'additions' => $add, 'deletions' => $del];
            $totalAdditions += $add;
            $totalDeletions += $del;
        }

        if (empty($files)) {
            throw new RuntimeException('No files found in diff output.');
        }

        return compact('files', 'totalAdditions', 'totalDeletions', 'repoName', 'prTitle') + ['prLinkUrl' => ''];
    }

    /**
     * Resolve label collisions across all nodes using a two-pass domain-prefix strategy.
     */
    private function resolveNodeLabels(array $nodes): array
    {
        // First pass: prefix colliding labels with their domain
        $labelCounts = array_count_values(array_column($nodes, 'id'));
        foreach ($nodes as &$node) {
            if ($labelCounts[$node['id']] > 1) {
                $domain = explode('/', $node['folder'] ?: '(root)')[0];
                $node['id'] = "{$domain}/{$node['id']}";
            }
        }
        unset($node);

        // Second pass: fall back to full folder path for remaining collisions
        $labelCounts = array_count_values(array_column($nodes, 'id'));
        foreach ($nodes as &$node) {
            if ($labelCounts[$node['id']] > 1) {
                $folder = $node['folder'] ?: '(root)';
                $node['id'] = "{$folder}/".basename($node['path'], '.php');
            }
        }
        unset($node);

        return $nodes;
    }

    /**
     * Assign a deterministic palette color to each node based on its domain.
     */
    private function assignDomainColors(array $nodes): array
    {
        $palette = [
            '#3fb950', '#58a6ff', '#d29922', '#f78166', '#d2a8ff',
            '#f778ba', '#79c0ff', '#7ee787', '#ff7b72', '#e3b341',
            '#ffa657', '#8957e5', '#56d4dd', '#db61a2', '#c9d1d9',
            '#a5d6ff', '#ffdf5d', '#ff9bce', '#b4f5a3', '#d4a4eb',
        ];

        $domains = array_keys(array_count_values(array_column($nodes, 'domain')));
        sort($domains);

        $colorMap = [];
        foreach ($domains as $i => $domain) {
            $colorMap[$domain] = $palette[$i % count($palette)];
        }

        foreach ($nodes as &$node) {
            $node['domainColor'] = $colorMap[$node['domain']] ?? '#8b949e';
        }
        unset($node);

        return $nodes;
    }

    /**
     * Fetch base-commit file contents for PHP files that need AST comparison.
     *
     * @param  array<int, array>  $phpFiles
     * @return array<string, string>
     */
    private function fetchOldSources(array $phpFiles, array $fileDiffMap): array
    {
        $needsOldSource = [];
        foreach ($phpFiles as $node) {
            $fileDiff = $fileDiffMap[$node['path']] ?? null;
            if ($fileDiff && $fileDiff->status !== FileStatus::ADDED) {
                $needsOldSource[] = $node['path'];
            }
        }

        if (empty($needsOldSource)) {
            return [];
        }

        $gitDir = $this->repoDir ?? $this->repoPath;
        $baseCommit = $this->baseCommit;

        $results = Process::pool(function ($pool) use ($needsOldSource, $gitDir, $baseCommit): void {
            foreach ($needsOldSource as $path) {
                $pool->as($path)->command("git -C {$gitDir} show {$baseCommit}:{$path}");
            }
        })->start()->wait();

        $oldSources = [];
        foreach ($needsOldSource as $path) {
            $output = $results[$path]->output();
            if ($output !== '') {
                $oldSources[$path] = $output;
            }
        }

        return $oldSources;
    }

    /**
     * Run PhpMetrics on head and base sources and build per-file metrics entries.
     *
     * @param  array<string, string|null>  $headContents
     * @param  array<string, string>  $oldSources
     * @param  array<string, string>  $fqcnToFilePath
     * @return array{hotSpots: int, metricsData: array<string, array>}
     */
    private function computePhpMetrics(array $headContents, array $oldSources, array $fqcnToFilePath): array
    {
        if (empty($headContents)) {
            return ['hotSpots' => 0, 'metricsData' => []];
        }

        $this->progress('info', 'Running PhpMetrics...');

        // Run on base state to get "before" metrics for diff display
        $metricsBefore = [];
        if (! empty($oldSources)) {
            $oldFqcnToPath = [];
            foreach ($oldSources as $path => $content) {
                $fqcn = $this->extractFqcnFromContent($content);
                if ($fqcn !== null) {
                    $oldFqcnToPath[$fqcn] = $path;
                }
            }
            foreach ((new PhpMetricsRunner)->run($oldSources) as $fqcn => $m) {
                $path = $oldFqcnToPath[$fqcn] ?? $this->fqcnToPath($fqcn);
                if ($path !== null) {
                    $metricsBefore[$path] = $m;
                }
            }
        }

        $metricsByFqcn = (new PhpMetricsRunner)->run($headContents);
        $hotSpots = $this->countHotSpots($metricsByFqcn);
        $metricsData = [];

        foreach ($metricsByFqcn as $fqcn => $m) {
            $path = $fqcnToFilePath[$fqcn] ?? $this->fqcnToPath($fqcn);
            if ($path === null) {
                continue;
            }

            $entry = array_filter([
                'cc' => $m->cyclomaticComplexity,
                'mi' => $m->maintainabilityIndex !== null ? round($m->maintainabilityIndex, 1) : null,
                'bugs' => $m->bugs !== null ? round($m->bugs, 3) : null,
                'coupling' => $m->efferentCoupling,
                'lloc' => $m->logicalLinesOfCode,
                'methods' => $m->methodsCount,
            ], fn ($v) => $v !== null);

            $before = $metricsBefore[$path] ?? null;
            if ($before !== null) {
                $beforeEntry = array_filter([
                    'cc' => $before->cyclomaticComplexity,
                    'mi' => $before->maintainabilityIndex !== null ? round($before->maintainabilityIndex, 1) : null,
                    'bugs' => $before->bugs !== null ? round($before->bugs, 3) : null,
                    'coupling' => $before->efferentCoupling,
                    'lloc' => $before->logicalLinesOfCode,
                    'methods' => $before->methodsCount,
                ], fn ($v) => $v !== null);
                if (! empty($beforeEntry)) {
                    $entry['before'] = $beforeEntry;
                }
            }

            if (! empty($entry)) {
                $metricsData[$path] = $entry;
            }
        }

        $this->progress('line', '  Metrics computed for '.count($metricsByFqcn).' classes.');

        // Enrich per-file entries with per-method breakdowns
        $methodMetrics = (new PhpMethodMetricsCalculator)->calculate($headContents);
        foreach ($methodMetrics as $path => $methods) {
            if (isset($metricsData[$path]) && ! empty($methods)) {
                $metricsData[$path]['method_metrics'] = array_map(fn ($m) => $m->toArray(), $methods);
            }
        }

        if (! empty($oldSources)) {
            $beforeMethodMetrics = (new PhpMethodMetricsCalculator)->calculate($oldSources);
            foreach ($beforeMethodMetrics as $path => $methods) {
                if (isset($metricsData[$path]) && ! empty($methods)) {
                    $metricsData[$path]['before_method_metrics'] = array_map(fn ($m) => $m->toArray(), $methods);
                }
            }
        }

        return compact('hotSpots', 'metricsData');
    }

    /**
     * Run JS complexity analysis on head and base sources and build per-file metrics entries.
     *
     * @param  array<int, array>  $frontendFiles
     * @param  array<string, string|null>  $headContents
     * @param  array<string, string>  $oldSources
     * @return array{hotSpots: int, metricsData: array<string, array>}
     */
    private function computeJsMetrics(array $frontendFiles, array $headContents, array $oldSources): array
    {
        if (empty($frontendFiles)) {
            return ['hotSpots' => 0, 'metricsData' => []];
        }

        $jsContents = [];
        foreach ($frontendFiles as $node) {
            $content = $headContents[$node['path']] ?? null;
            if ($content !== null && $content !== '') {
                $jsContents[$node['path']] = $content;
            }
        }

        if (empty($jsContents)) {
            return ['hotSpots' => 0, 'metricsData' => []];
        }

        $this->progress('info', 'Running JS complexity analysis...');

        $jsMetricsByPath = (new JsMetricsRunner)->run($jsContents);

        $jsMetricsBefore = [];
        if (! empty($oldSources)) {
            $oldJsContents = array_intersect_key($oldSources, $jsContents);
            if (! empty($oldJsContents)) {
                $jsMetricsBefore = (new JsMetricsRunner)->run($oldJsContents);
            }
        }

        $hotSpots = $this->countJsHotSpots($jsMetricsByPath);
        $metricsData = [];

        foreach ($jsMetricsByPath as $path => $m) {
            $entry = array_filter([
                'cc' => $m->cyclomaticComplexity,
                'mi' => $m->maintainabilityIndex !== null ? round($m->maintainabilityIndex, 1) : null,
                'bugs' => $m->bugs !== null ? round($m->bugs, 3) : null,
                'lloc' => $m->logicalLinesOfCode,
                'methods' => $m->functionCount,
            ], fn ($v) => $v !== null);

            $before = $jsMetricsBefore[$path] ?? null;
            if ($before !== null) {
                $beforeEntry = array_filter([
                    'cc' => $before->cyclomaticComplexity,
                    'mi' => $before->maintainabilityIndex !== null ? round($before->maintainabilityIndex, 1) : null,
                    'bugs' => $before->bugs !== null ? round($before->bugs, 3) : null,
                    'lloc' => $before->logicalLinesOfCode,
                    'methods' => $before->functionCount,
                ], fn ($v) => $v !== null);
                if (! empty($beforeEntry)) {
                    $entry['before'] = $beforeEntry;
                }
            }

            if (! empty($entry)) {
                $metricsData[$path] = $entry;
            }
        }

        $this->progress('line', '  JS metrics computed for '.count($jsMetricsByPath).' files.');

        return compact('hotSpots', 'metricsData');
    }

    /**
     * Split the raw diff string into per-file hunk blocks.
     *
     * @return array<string, string>
     */
    private function extractFileDiffs(): array
    {
        $fileDiffs = [];
        $diffBlocks = preg_split('/^diff --git /m', $this->diff);

        foreach ($diffBlocks as $block) {
            if (empty(trim($block))) {
                continue;
            }
            if (! preg_match('#^a/\S+ b/(\S+)#', $block, $m)) {
                continue;
            }
            $hunkStart = strpos($block, '@@');
            if ($hunkStart === false) {
                continue;
            }
            $fileDiffs[$m[1]] = substr($block, $hunkStart);
        }

        return $fileDiffs;
    }

    /**
     * @param  array<string, PhpMetrics>  $metricsByFqcn
     */
    private function countHotSpots(array $metricsByFqcn): int
    {
        $count = 0;
        foreach ($metricsByFqcn as $metrics) {
            if (($metrics->cyclomaticComplexity ?? 0) > 10
                || ($metrics->maintainabilityIndex ?? 100) < 85
                || ($metrics->bugs ?? 0) > 0.1
                || ($metrics->efferentCoupling ?? 0) > 15) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, JsMetrics>  $metricsByPath
     */
    private function countJsHotSpots(array $metricsByPath): int
    {
        $count = 0;
        foreach ($metricsByPath as $metrics) {
            if (($metrics->cyclomaticComplexity ?? 0) > 10
                || ($metrics->maintainabilityIndex ?? 100) < 85
                || ($metrics->bugs ?? 0) > 0.1) {
                $count++;
            }
        }

        return $count;
    }

    private function progress(string $level, string $message): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($level, $message);
        }
    }

    private function matchesWatchPattern(string $path, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }
        // Directory prefix: pattern ending with '/' matches any file under that directory
        if (str_ends_with($pattern, '/')) {
            return str_starts_with($path, $pattern);
        }

        return fnmatch($pattern, $path) || $path === $pattern;
    }

    private function classifyFile(array $file, array $fileDiffMap): array
    {
        $path = $file['path'];
        $add = $file['additions'];
        $del = $file['deletions'];

        $fileDiff = $fileDiffMap[$path] ?? null;
        $status = $fileDiff->status ?? FileStatus::MODIFIED;

        $group = $this->groupResolver->resolve($path);
        $label = $this->generateLabel($path);
        $hash = hash('sha256', $path);
        $desc = $this->generateDescription($path, $group, $status, $add, $del);
        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: basename($path);
        $folder = dirname($path);
        $folder = preg_replace('#^app/#', '', $folder);
        $folder = preg_replace('#^tests/(Unit|Feature)/#', 'tests/', $folder);
        if ($folder === '.' || $folder === '') {
            $folder = '';
        }

        $domain = explode('/', $folder)[0] ?: '(root)';

        return [
            'id' => $label,
            'path' => $path,
            'add' => $add,
            'del' => $del,
            'status' => $status->value,
            'group' => $group->value,
            'hash' => $hash,
            'desc' => $desc,
            'ext' => $ext,
            'folder' => $folder,
            'domain' => $domain,
        ];
    }

    private function generateLabel(string $path): string
    {
        if (str_ends_with($path, '.php')) {
            $base = basename($path, '.php');
            if (str_contains($path, 'database/migrations')) {
                $base = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $base);
            }
            $dir = basename(dirname($path));

            if (in_array($dir, ['Concerns', 'Pivots', 'Policies', 'Resources', 'Middleware', 'Controllers'])) {
                $parentDir = basename(dirname(dirname($path)));

                return "{$parentDir}\\{$base}";
            }

            if (str_contains($path, 'tests/')) {
                $base = preg_replace('/Test$/', '', $base);

                return "Test:{$base}";
            }

            return $base;
        }

        $base = basename($path);

        return strlen($base) > 30 ? '...'.substr($base, -27) : $base;
    }

    private function generateDescription(string $path, NodeGroup $group, FileStatus $status, int $add, int $del): string
    {
        $action = match ($status) {
            FileStatus::ADDED => 'New',
            FileStatus::DELETED => 'Deleted',
            FileStatus::RENAMED => 'Renamed',
            FileStatus::MODIFIED => 'Modified',
        };

        return $group->description($action, basename($path));
    }

    /**
     * Extract class references from PHP source, classified by how they are used.
     *
     * @return array<string, string> FQCN/short-name → dependency type
     */
    private function extractReferences(string $content): array
    {
        return (new PhpDependencyExtractor)->extract($content);
    }

    private function addEdge(string $sourceId, string $targetId, string $type = PhpDependencyExtractor::USE): void
    {
        if ($sourceId === $targetId) {
            return;
        }

        $key = "{$sourceId}->{$targetId}";
        if (isset($this->edgeSet[$key])) {
            return;
        }

        $this->edges[] = [$sourceId, $targetId, $type];
        $this->edgeSet[$key] = true;
    }

    /**
     * @param  array<string, string>  $references  FQCN/short-name → dependency type
     */
    private function matchReferences(array $references, string $sourceNodeId): void
    {
        foreach ($references as $ref => $type) {
            $ref = ltrim($ref, '\\');

            if (isset($this->fqcnToNode[$ref])) {
                $this->addEdge($sourceNodeId, $this->fqcnToNode[$ref], $type);

                continue;
            }

            $shortName = basename(str_replace('\\', '/', $ref));
            foreach ($this->fqcnToNode as $fqcn => $nodeId) {
                $fqcnShort = basename(str_replace('\\', '/', $fqcn));
                if ($fqcnShort === $shortName) {
                    $this->addEdge($sourceNodeId, $nodeId, $type);
                    break;
                }
            }
        }
    }

    private function matchViewReferences(string $content, string $sourceNodeId): void
    {
        preg_match_all('/(?:Inertia::render|inertia)\s*\(\s*[\'"]([^\'"]+)[\'"]/m', $content, $inertiaMatches);
        foreach ($inertiaMatches[1] as $page) {
            foreach (['jsx', 'tsx', 'vue'] as $ext) {
                $path = "resources/js/Pages/{$page}.{$ext}";
                if (isset($this->pathToNode[$path])) {
                    $this->addEdge($sourceNodeId, $this->pathToNode[$path]);
                    break;
                }
            }
        }

        preg_match_all('/\bview\s*\(\s*[\'"]([^\'"]+)[\'"]/m', $content, $viewMatches);
        foreach ($viewMatches[1] as $view) {
            $viewPath = 'resources/views/'.str_replace('.', '/', $view).'.blade.php';
            if (isset($this->pathToNode[$viewPath])) {
                $this->addEdge($sourceNodeId, $this->pathToNode[$viewPath]);
            }
        }
    }

    private function matchComponentReferences(string $content, string $sourceNodeId, array $componentNameToNode): void
    {
        preg_match_all('/<([A-Z][A-Za-z0-9]+)(?:[\s\/>.])/m', $content, $jsxMatches);
        $components = array_unique($jsxMatches[1]);

        foreach ($components as $component) {
            if (isset($componentNameToNode[$component]) && $componentNameToNode[$component] !== $sourceNodeId) {
                $this->addEdge($sourceNodeId, $componentNameToNode[$component]);
            }
        }
    }

    private function pathToFqcn(string $path): ?string
    {
        if (preg_match('#^app/(.+)\.php$#', $path, $m)) {
            return 'App\\'.str_replace('/', '\\', $m[1]);
        }
        if (preg_match('#^database/factories/(.+)\.php$#', $path, $m)) {
            return 'Database\\Factories\\'.str_replace('/', '\\', $m[1]);
        }
        if (preg_match('#^tests/(.+)\.php$#', $path, $m)) {
            return 'Tests\\'.str_replace('/', '\\', $m[1]);
        }

        return null;
    }

    private function fqcnToPath(string $fqcn): ?string
    {
        if (str_starts_with($fqcn, 'App\\')) {
            return 'app/'.str_replace('\\', '/', substr($fqcn, 4)).'.php';
        }
        if (str_starts_with($fqcn, 'Database\\Factories\\')) {
            return 'database/factories/'.str_replace('\\', '/', substr($fqcn, 19)).'.php';
        }
        if (str_starts_with($fqcn, 'Database\\Seeders\\')) {
            return 'database/seeders/'.str_replace('\\', '/', substr($fqcn, 17)).'.php';
        }
        if (str_starts_with($fqcn, 'Tests\\')) {
            return 'tests/'.str_replace('\\', '/', substr($fqcn, 6)).'.php';
        }

        return null;
    }

    private function extractFqcnFromContent(string $content): ?string
    {
        if (! preg_match('/^namespace\s+([^;{]+)/m', $content, $nsMatch)) {
            return null;
        }
        if (! preg_match('/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $content, $classMatch)) {
            return null;
        }

        return trim($nsMatch[1]).'\\'.trim($classMatch[1]);
    }
}
