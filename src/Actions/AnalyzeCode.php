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
use Vistik\LaravelCodeAnalytics\Support\PhpMetrics;
use Vistik\LaravelCodeAnalytics\Support\PhpMetricsRunner;

class AnalyzeCode
{
    private RiskScoring $riskScorer;

    private FileSignalScoring $fileSignalScorer;

    private string $repoPath;

    private string $baseBranch;

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

    /** @var list<array{0: string, 1: string}> */
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
     * @return array{files: array<string, string>, risk: RiskScore}
     */
    public function execute(
        string $repoPath = '',
        ?string $outputPath = null,
        string $baseBranch = 'main',
        ?string $prUrl = null,
        ?string $title = null,
        ?string $view = null,
        OutputFormat $format = OutputFormat::HTML,
        ?Closure $onProgress = null,
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
            $files = $init['files'];
            $totalAdditions = $init['totalAdditions'];
            $totalDeletions = $init['totalDeletions'];
            $repoName = $init['repoName'];
            $prTitle = $init['prTitle'];
            $prLinkUrl = $prUrl;

            if (empty($files)) {
                return ['files' => [], 'risk' => new RiskScore(0)];
            }
        } else {
            // ── Local mode ───────────────────────────────────────────────────
            $this->repoPath = rtrim(realpath($repoPath) ?: $repoPath, '/');
            $this->baseBranch = $baseBranch;

            // ── Validate git repo ─────────────────────────────────────────────
            $gitDir = trim(shell_exec("git -C {$this->repoPath} rev-parse --git-dir 2>/dev/null") ?? '');
            if ($gitDir === '') {
                throw new RuntimeException("Not a git repository: {$this->repoPath}");
            }

            // ── Resolve commits ───────────────────────────────────────────────
            $this->headCommit = trim(shell_exec("git -C {$this->repoPath} rev-parse HEAD 2>/dev/null") ?? '');
            $this->baseCommit = trim(shell_exec("git -C {$this->repoPath} rev-parse {$baseBranch} 2>/dev/null") ?? '');
            $this->branchName = trim(shell_exec("git -C {$this->repoPath} rev-parse --abbrev-ref HEAD 2>/dev/null") ?? 'HEAD');

            if (empty($this->headCommit)) {
                throw new RuntimeException('Could not resolve HEAD commit.');
            }
            if (empty($this->baseCommit)) {
                throw new RuntimeException("Could not resolve base: {$baseBranch}");
            }

            $repoName = basename($this->repoPath);

            // ── Detect uncommitted changes ─────────────────────────────────────
            $hasUncommitted = trim(shell_exec("git -C {$this->repoPath} status --porcelain 2>/dev/null") ?? '') !== '';
            $uncommittedSuffix = $hasUncommitted ? ' + uncommitted' : '';
            $prTitle = $title ?? "{$this->branchName} vs {$baseBranch}{$uncommittedSuffix}";
            $prLinkUrl = '';

            $this->progress('info', "Analyzing {$repoName}: {$prTitle}...");
            $this->progress('line', '  HEAD: '.substr($this->headCommit, 0, 7).'  Base: '.substr($this->baseCommit, 0, 7));
            if ($hasUncommitted) {
                $this->progress('line', '  Including staged and unstaged working tree changes.');
            }

            // ── Detect Laravel ────────────────────────────────────────────────
            $this->isLaravel = file_exists("{$this->repoPath}/artisan");

            // ── Get diff ──────────────────────────────────────────────────────
            // Compare working tree directly against base so staged/unstaged changes
            // are included in addition to committed ones.
            $this->diff = shell_exec("git -C {$this->repoPath} diff {$baseBranch} 2>/dev/null") ?? '';

            if (! str_contains($this->diff, 'diff --git')) {
                $this->progress('warn', "No changes found between working tree and {$baseBranch}.");

                return ['files' => [], 'risk' => new RiskScore(0)];
            }

            // ── Get file list with numstat ─────────────────────────────────────
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

        $nodes = [];
        $labelCounts = [];

        foreach ($files as $file) {
            $node = $this->classifyFile($file, $fileDiffMap);
            $labelCounts[$node['id']] = ($labelCounts[$node['id']] ?? 0) + 1;
            $nodes[] = $node;
        }

        // Resolve label collisions — use domain as prefix
        foreach ($nodes as &$node) {
            if ($labelCounts[$node['id']] > 1) {
                $folder = $node['folder'] ?: '(root)';
                $domain = explode('/', $folder)[0];
                $node['id'] = "{$domain}/{$node['id']}";
            }
        }
        unset($node);

        $labelCounts2 = [];
        foreach ($nodes as $node) {
            $labelCounts2[$node['id']] = ($labelCounts2[$node['id']] ?? 0) + 1;
        }
        foreach ($nodes as &$node) {
            if ($labelCounts2[$node['id']] > 1) {
                $folder = $node['folder'] ?: '(root)';
                $node['id'] = "{$folder}/".basename($node['path'], '.php');
            }
        }
        unset($node);

        // ── Collect extensions, domains, severity counts ─────────────────────
        $domainPalette = [
            '#3fb950', '#58a6ff', '#d29922', '#f78166', '#d2a8ff',
            '#f778ba', '#79c0ff', '#7ee787', '#ff7b72', '#e3b341',
            '#ffa657', '#8957e5', '#56d4dd', '#db61a2', '#c9d1d9',
            '#a5d6ff', '#ffdf5d', '#ff9bce', '#b4f5a3', '#d4a4eb',
        ];

        $domainCounts = [];
        foreach ($nodes as $node) {
            $domainCounts[$node['domain']] = ($domainCounts[$node['domain']] ?? 0) + 1;
        }
        ksort($domainCounts);

        $domainColorMap = [];
        $i = 0;
        foreach (array_keys($domainCounts) as $domain) {
            $domainColorMap[$domain] = $domainPalette[$i % count($domainPalette)];
            $i++;
        }

        foreach ($nodes as &$node) {
            $node['domainColor'] = $domainColorMap[$node['domain']] ?? '#8b949e';
        }
        unset($node);

        // ── Fetch file contents from HEAD via git cat-file --batch ───────────
        $this->progress('info', 'Reading file contents...');

        $frontendExts = ['jsx', 'tsx', 'vue', 'js', 'ts'];
        $phpFiles = array_filter($nodes, fn ($n) => str_ends_with($n['path'], '.php'));
        $frontendFiles = array_filter($nodes, fn ($n) => in_array($n['ext'], $frontendExts));

        /** @var array<string, string|null> */
        $headContents = [];

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
        $changeClassifier = new ChangeClassifier($astComparer, $this->isLaravel);

        // Fetch old sources (base commit) in parallel
        $needsOldSource = [];
        foreach ($phpFiles as $node) {
            $filePath = $node['path'];
            $fileDiff = $fileDiffMap[$filePath] ?? null;
            if ($fileDiff && $fileDiff->status !== FileStatus::ADDED) {
                $needsOldSource[] = $filePath;
            }
        }

        $oldSources = [];
        if (! empty($needsOldSource)) {
            $gitDir = $this->repoDir ?? $this->repoPath;
            $baseCommit = $this->baseCommit;

            $results = Process::pool(function ($pool) use ($needsOldSource, $gitDir, $baseCommit): void {
                foreach ($needsOldSource as $path) {
                    $pool->as($path)->command("git -C {$gitDir} show {$baseCommit}:{$path}");
                }
            })->start()->wait();

            foreach ($needsOldSource as $path) {
                $output = $results[$path]->output();
                if ($output !== '') {
                    $oldSources[$path] = $output;
                }
            }
        }

        // Compare ASTs
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

        // Cross-file: link migrations to their models
        if ($this->isLaravel) {
            // In PR URL mode the bare clone can't be used to scan non-PR models, so pass null.
            $fileReports = (new LaravelMigrationModelCorrelator)->correlate($fileReports, $headContents, $this->repoDir !== null ? null : $this->repoPath);
        }

        // ── Enrich nodes with analysis data ──────────────────────────────────
        foreach ($nodes as &$node) {
            $report = $fileReports[$node['path']] ?? null;
            $changes = $report?->changes ?? [];
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

        // ── Run PhpMetrics for quality scoring ────────────────────────────────
        $phpHotSpots = 0;
        $metricsData = [];
        if (! empty($headContents)) {
            $this->progress('info', 'Running PhpMetrics...');

            $runner = new PhpMetricsRunner;

            // Run on base state to get "before" metrics for diff display
            $metricsBefore = [];
            if (! empty($oldSources)) {
                // Build FQCN → path map from old sources for correct path resolution
                $oldFqcnToPath = [];
                foreach ($oldSources as $path => $content) {
                    if ($content === null || $content === '') {
                        continue;
                    }
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

            $metricsByFqcn = $runner->run($headContents);
            $phpHotSpots = $this->countHotSpots($metricsByFqcn);

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

                $beforeMetrics = $metricsBefore[$path] ?? null;
                if ($beforeMetrics !== null) {
                    $beforeEntry = array_filter([
                        'cc' => $beforeMetrics->cyclomaticComplexity,
                        'mi' => $beforeMetrics->maintainabilityIndex !== null ? round($beforeMetrics->maintainabilityIndex, 1) : null,
                        'bugs' => $beforeMetrics->bugs !== null ? round($beforeMetrics->bugs, 3) : null,
                        'coupling' => $beforeMetrics->efferentCoupling,
                        'lloc' => $beforeMetrics->logicalLinesOfCode,
                        'methods' => $beforeMetrics->methodsCount,
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
        }

        // ── Extract per-file diffs ────────────────────────────────────────────
        $fileDiffs = [];
        $diffBlocks = preg_split('/^diff --git /m', $this->diff);
        foreach ($diffBlocks as $block) {
            if (empty(trim($block))) {
                continue;
            }
            if (! preg_match('#^a/(.+?) b/#', $block, $m)) {
                continue;
            }
            $hunkStart = strpos($block, '@@');
            if ($hunkStart === false) {
                continue;
            }
            $fileDiffs[$m[1]] = substr($block, $hunkStart);
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

        // ── Compute overall risk score ────────────────────────────────────────
        $riskResult = $this->riskScorer->calculate($nodes, $totalAdditions, $totalDeletions, $fileCount, $phpHotSpots);

        // ── Generate report ───────────────────────────────────────────────────
        $outputDir = base_path('output');
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $reportGenerator = $format->generator();

        $this->progress('info', "Generating {$format->value} report...");

        if ($outputPath === null) {
            $safeBranch = preg_replace('/[^a-zA-Z0-9._-]/', '-', $this->branchName);
            $ext = $format->fileExtension();
            $outputPath = $this->repoDir !== null
                ? "{$outputDir}/pr-".preg_replace('/[^0-9]/', '', $this->branchName).".{$ext}"
                : "{$outputDir}/local-{$safeBranch}.{$ext}";
        }

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
            riskScore: $riskResult,
            metricsData: $metricsData,
        );
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
        $this->baseBranch = $prJson['baseRefName'] ?? 'main';
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
        $blobShas = $matches[1] ?? [];

        if (empty($blobShas)) {
            return;
        }

        $shaArgs = implode(' ', $blobShas);
        shell_exec("git -C {$repoDir} fetch origin {$shaArgs} 2>/dev/null");
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

    private function progress(string $level, string $message): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($level, $message);
        }
    }

    private function classifyFile(array $file, array $fileDiffMap): array
    {
        $path = $file['path'];
        $add = $file['additions'];
        $del = $file['deletions'];

        $fileDiff = $fileDiffMap[$path] ?? null;
        $status = $fileDiff?->status ?? FileStatus::MODIFIED;

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

    private function extractReferences(string $content): array
    {
        preg_match_all('/^\s*use\s+([A-Z][A-Za-z0-9_\\\\]+)/m', $content, $useMatches);
        $references = $useMatches[1] ?? [];

        preg_match_all('/(?:extends|implements|new|instanceof)\s+([A-Z][A-Za-z0-9_\\\\]+)/m', $content, $classMatches);
        $references = array_merge($references, $classMatches[1] ?? []);

        preg_match_all('/([A-Z][A-Za-z0-9_]+)::/m', $content, $staticMatches);
        $references = array_merge($references, $staticMatches[1] ?? []);

        preg_match_all('/(?:protected|private|public|readonly)\s+([A-Z][A-Za-z0-9_\\\\]+)\s+\$/m', $content, $typeHintMatches);
        $references = array_merge($references, $typeHintMatches[1] ?? []);

        preg_match_all('/\)\s*:\s*\??\s*([A-Z][A-Za-z0-9_\\\\]+)/m', $content, $returnTypeMatches);
        $references = array_merge($references, $returnTypeMatches[1] ?? []);

        return array_unique($references);
    }

    private function addEdge(string $sourceId, string $targetId): void
    {
        if ($sourceId === $targetId) {
            return;
        }

        $key = "{$sourceId}->{$targetId}";
        if (isset($this->edgeSet[$key])) {
            return;
        }

        $this->edges[] = [$sourceId, $targetId];
        $this->edgeSet[$key] = true;
    }

    private function matchReferences(array $references, string $sourceNodeId): void
    {
        foreach ($references as $ref) {
            $ref = ltrim($ref, '\\');

            if (isset($this->fqcnToNode[$ref])) {
                $this->addEdge($sourceNodeId, $this->fqcnToNode[$ref]);

                continue;
            }

            $shortName = basename(str_replace('\\', '/', $ref));
            foreach ($this->fqcnToNode as $fqcn => $nodeId) {
                $fqcnShort = basename(str_replace('\\', '/', $fqcn));
                if ($fqcnShort === $shortName) {
                    $this->addEdge($sourceNodeId, $nodeId);
                    break;
                }
            }
        }
    }

    private function matchViewReferences(string $content, string $sourceNodeId): void
    {
        preg_match_all('/(?:Inertia::render|inertia)\s*\(\s*[\'"]([^\'"]+)[\'"]/m', $content, $inertiaMatches);
        foreach (($inertiaMatches[1] ?? []) as $page) {
            foreach (['jsx', 'tsx', 'vue'] as $ext) {
                $path = "resources/js/Pages/{$page}.{$ext}";
                if (isset($this->pathToNode[$path])) {
                    $this->addEdge($sourceNodeId, $this->pathToNode[$path]);
                    break;
                }
            }
        }

        preg_match_all('/\bview\s*\(\s*[\'"]([^\'"]+)[\'"]/m', $content, $viewMatches);
        foreach (($viewMatches[1] ?? []) as $view) {
            $viewPath = 'resources/views/'.str_replace('.', '/', $view).'.blade.php';
            if (isset($this->pathToNode[$viewPath])) {
                $this->addEdge($sourceNodeId, $this->pathToNode[$viewPath]);
            }
        }
    }

    private function matchComponentReferences(string $content, string $sourceNodeId, array $componentNameToNode): void
    {
        preg_match_all('/<([A-Z][A-Za-z0-9]+)(?:[\s\/>.])/m', $content, $jsxMatches);
        $components = array_unique($jsxMatches[1] ?? []);

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
