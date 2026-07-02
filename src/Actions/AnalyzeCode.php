<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Closure;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Vistik\LaravelCodeAnalytics\Actions\DependencyGraph\ConnectedNodeFactory;
use Vistik\LaravelCodeAnalytics\Actions\DependencyGraph\DependencyGraph;
use Vistik\LaravelCodeAnalytics\Actions\DependencyGraph\FqcnNodeIndex;
use Vistik\LaravelCodeAnalytics\Actions\DependencyGraph\Psr4Resolver;
use Vistik\LaravelCodeAnalytics\Actions\DependencyRules\BladeDependencyRule;
use Vistik\LaravelCodeAnalytics\Actions\DependencyRules\ViewFileDependencyRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\ArrayFileGroupResolver;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\ChangeClassifier;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts\FileGroupResolver;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileReport;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\DiffParser;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\LaravelMigrationModelCorrelator;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\PatternBasedGroupResolver;
use Vistik\LaravelCodeAnalytics\Endpoints\AffectedEndpoint;
use Vistik\LaravelCodeAnalytics\Endpoints\AffectedEndpointResolver;
use Vistik\LaravelCodeAnalytics\Endpoints\RouteIndexBuilder;
use Vistik\LaravelCodeAnalytics\Enums\GraphLayout;
use Vistik\LaravelCodeAnalytics\Enums\NodeKind;
use Vistik\LaravelCodeAnalytics\Enums\OutputFormat;
use Vistik\LaravelCodeAnalytics\FileSignal\CalculateFileSignal;
use Vistik\LaravelCodeAnalytics\FileSignal\FileSignalScoring;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;
use Vistik\LaravelCodeAnalytics\RiskScoring\CalculateRiskScore;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScoring;
use Vistik\LaravelCodeAnalytics\ScheduledJobs\AffectedScheduledJob;
use Vistik\LaravelCodeAnalytics\ScheduledJobs\AffectedScheduledJobResolver;
use Vistik\LaravelCodeAnalytics\ScheduledJobs\ScheduledJobIndexBuilder;
use Vistik\LaravelCodeAnalytics\Support\Detection\ProjectType;
use Vistik\LaravelCodeAnalytics\Support\Detection\ProjectTypeDetector;
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

    private ProjectType $projectType = ProjectType::Unknown;

    /** Bare-clone path when analyzing a remote GitHub PR or repo URL (null in local mode) */
    private ?string $repoDir = null;

    /** Whether file contents should be read from a specific git commit rather than the filesystem */
    private bool $readContentsFromCommit = false;

    /** GitHub "owner/repo" when analyzing a remote PR or repo URL (empty in local mode) */
    private string $prRepo = '';

    /** Fetched PR comments (general + reviews + inline), populated in PR mode only */
    private array $prComments = [];

    /** Whether the current analysis was initiated from a bare repo URL (vs a PR URL) */
    private bool $isRepoUrl = false;

    /** Inline review comment threads keyed by file path, populated in PR mode only */
    private array $inlineComments = [];

    private DependencyGraph $graph;

    private FqcnNodeIndex $fqcnIndex;

    private ConnectedNodeFactory $connectedNodeFactory;

    private ?Psr4Resolver $psr4Resolver = null;

    /** Count of outbound network calls made to GitHub during this analysis */
    private int $githubCallCount = 0;

    private ?Closure $onProgress = null;

    private float $analyzeStart = 0.0;

    /** @var array<string, float> step label → accumulated seconds, for the timing breakdown */
    private array $stepTimings = [];

    private bool $groupResolverIsDefault;

    public function __construct(
        private FileGroupResolver $groupResolver = new PatternBasedGroupResolver,
        ?RiskScoring $riskScorer = null,
        ?FileSignalScoring $fileSignalScorer = null,
    ) {
        $this->groupResolverIsDefault = $this->groupResolver instanceof PatternBasedGroupResolver;
        $this->riskScorer = $riskScorer ?? new CalculateRiskScore;
        $this->fileSignalScorer = $fileSignalScorer ?? new CalculateFileSignal;
        $this->graph = new DependencyGraph;
        $this->fqcnIndex = new FqcnNodeIndex;
        $this->connectedNodeFactory = new ConnectedNodeFactory($this->groupResolver);
    }

    /**
     * @return array{files: array<string, string>, risk: RiskScore, content?: string}
     */
    public function execute(
        string $repoPath = '',
        ?string $outputPath = null,
        ?string $baseBranch = null,
        ?string $prUrl = null,
        ?string $repoUrl = null,
        bool $full = false,
        ?string $title = null,
        GraphLayout $view = GraphLayout::Force,
        OutputFormat $format = OutputFormat::HTML,
        ?Severity $minSeverity = null,
        ?Closure $onProgress = null,
        ?array $watchedFiles = null,
        ?array $filePatterns = null,
        bool $raw = false,
        bool $includeFileContents = false,
        bool $githubMetrics = false,
        array $filterDefaults = [],
        array $riskScoringConfig = [],
        array $criticalTables = [],
        ?string $fromCommit = null,
        ?string $toCommit = null,
        ?array $focusFiles = null,
        ?Closure $onPayloadReady = null,
        array $fileSignalConfig = [],
    ): array {
        $this->onProgress = $onProgress;
        $this->analyzeStart = microtime(true);
        $this->stepTimings = [];
        $this->resetState();

        $t = microtime(true);
        if ($repoUrl !== null) {
            $init = $this->initFromRepoUrl($repoUrl, $baseBranch);
        } elseif ($prUrl !== null) {
            $init = $this->initFromPrUrl($prUrl, $full);
            $init['prLinkUrl'] = $prUrl;
        } elseif ($fromCommit !== null) {
            // ── Two-commit range mode ────────────────────────────────────────
            $init = $this->initTwoCommitMode($repoPath, $fromCommit, $toCommit, $title);
        } else {
            $init = $this->initLocalMode($repoPath, $baseBranch ?? 'main', $title, $full);
        }
        $this->recordStep('init (diff/PR fetch)', $t);

        $files = $init['files'];
        $totalAdditions = $init['totalAdditions'];
        $totalDeletions = $init['totalDeletions'];
        $repoName = $init['repoName'];
        $prTitle = $init['prTitle'];
        $prLinkUrl = $init['prLinkUrl'];

        if (empty($files)) {
            return ['files' => [], 'risk' => new RiskScore(0)];
        }

        if ($filePatterns !== null) {
            [$files, $totalAdditions, $totalDeletions] = $this->applyFilePatternFilter($files, $filePatterns);

            if (empty($files)) {
                return ['files' => [], 'risk' => new RiskScore(0)];
            }
        }

        $fileCount = count($files);
        $this->progress('line', "  Files: {$fileCount}, +{$totalAdditions} -{$totalDeletions}");

        $fileDiffMap = $this->buildFileDiffMap();

        $t = microtime(true);
        $nodes = $this->buildNodes($files, $fileDiffMap, $this->resolveWatchedFiles($watchedFiles));
        $this->recordStep('classify + build nodes', $t);

        $t = microtime(true);
        [$phpFiles, $frontendFiles, $headContents] = $this->resolveHeadContents($nodes);
        $this->recordStep('read head file contents', $t);

        $t = microtime(true);
        [$fqcnToFilePath, $fileReferences] = $this->buildDependencyGraph($nodes, $phpFiles, $frontendFiles, $headContents);
        $this->recordStep('build dependency graph', $t);

        if ($this->graph->connectedNodes !== []) {
            $nodes = array_merge($nodes, array_values($this->graph->connectedNodes));
            $this->progress('line', '  Found '.count($this->graph->connectedNodes).' connected (non-diff) dependencies.');
        }

        $nodes = $this->enrichNodesWithKind($nodes, $headContents);

        $t = microtime(true);
        [$nodes, $cycleMap] = $this->detectAndAnnotateCycles($nodes);
        [$nodes] = $this->detectAndAnnotateClusters($nodes);
        $this->recordStep('detect cycles + clusters', $t);

        $t = microtime(true);
        [$fileReports, $oldSources] = $this->runAstAnalysis($phpFiles, $headContents, $fileDiffMap, $criticalTables);
        $this->recordStep('AST analysis', $t);

        $nodes = $this->enrichNodesWithAnalysis($nodes, $fileReports);

        $analysisData = $this->buildAnalysisData($fileReports, $fileReferences);

        [$nodes, $analysisData] = $this->injectCycleFindings($nodes, $analysisData, $cycleMap);

        [$nodes, $analysisData] = $this->injectComposerAuditFindings($nodes, $analysisData);

        $t = microtime(true);
        $externalMetrics = $this->runExternalMetrics($headContents, $oldSources, $frontendFiles);
        $this->recordStep('external metrics (php+js, concurrent)', $t);

        $t = microtime(true);
        ['hotSpots' => $phpHotSpots, 'metricsData' => $metricsData] = $this->computePhpMetrics(
            $externalMetrics['php'], $externalMetrics['phpBefore'], $headContents, $oldSources, $fqcnToFilePath,
        );
        $this->recordStep('php metrics (build + method metrics)', $t);

        ['hotSpots' => $jsHotSpots, 'metricsData' => $jsMetricsData] = $this->computeJsMetrics(
            $externalMetrics['js'], $externalMetrics['jsBefore'], $frontendFiles,
        );

        $metricsData = array_merge($metricsData, $jsMetricsData);

        $fileDiffs = $this->extractFileDiffs();

        $t = microtime(true);
        if ($includeFileContents) {
            if (! empty($fileDiffs)) {
                // Diff mode: collect contents of changed files only.
                $fileContents = $this->collectFileContents($fileDiffs, $headContents);
                $this->recordStep('read diff file contents', $t);
            } else {
                // Full/repo mode: scope to PHP/frontend files already in headContents.
                // Fetching all 700+ node paths (JSON, YAML, markdown, etc.) wastes memory
                // and those file types aren't useful in the code viewer anyway.
                $analyzedPaths = array_fill_keys(
                    array_keys(array_filter($headContents, fn ($c) => $c !== null)),
                    '',
                );
                $fileContents = $this->collectFileContents($analyzedPaths, $headContents);
                $this->recordStep('read full-repo file contents', $t);
            }
        } else {
            $fileContents = [];
        }

        // Always load file contents for connected nodes so their code can be viewed in the panel.
        if ($this->graph->connectedNodes !== []) {
            $connectedPaths = array_column(array_values($this->graph->connectedNodes), 'path');
            // Prefetch blobs in a single batch fetch so git doesn't lazily pull them one-by-one.
            $t = microtime(true);
            if ($this->repoDir !== null) {
                $this->prefetchBlobs($this->repoDir, $this->headCommit, $connectedPaths);
                $this->recordStep('prefetch connected node blobs', $t, ' ('.count($connectedPaths).')');
                $t = microtime(true);
            }
            $connectedContents = $this->loadConnectedNodeContents($connectedPaths);
            $fileContents = array_merge($fileContents, $connectedContents);
            $this->recordStep('read connected node contents', $t);
            $nodes = $this->enrichNodesWithKind($nodes, $connectedContents);
        }

        $t = microtime(true);
        $nodes = $this->computeSignalScores($nodes, $analysisData, $metricsData, $cycleMap, $fileSignalConfig);
        $this->recordStep('compute signal scores', $t);

        if ($minSeverity !== null) {
            $t = microtime(true);
            ['nodes' => $nodes, 'analysisData' => $analysisData, 'metricsData' => $metricsData,
                'fileDiffs' => $fileDiffs, 'fileContents' => $fileContents,
                'fileCount' => $fileCount, 'totalAdditions' => $totalAdditions, 'totalDeletions' => $totalDeletions]
                = $this->applyMinSeverityFilter($nodes, $analysisData, $metricsData, $fileDiffs, $fileContents, $minSeverity);
            $this->recordStep('severity filter', $t);
        }

        $t = microtime(true);
        $riskResult = $this->computeRiskScore($nodes, $totalAdditions, $totalDeletions, $fileCount, $phpHotSpots + $jsHotSpots, $riskScoringConfig);
        $this->recordStep('compute risk score', $t);

        $this->progress('info', "Generating {$format->value} report...");

        $t = microtime(true);
        $affectedEndpoints = $this->findAffectedEndpoints($nodes, $this->graph->edges, $fqcnToFilePath);
        $this->recordStep('find affected endpoints', $t, ' ('.count($affectedEndpoints).')');

        $t = microtime(true);
        $affectedScheduledJobs = $this->findAffectedScheduledJobs($nodes, $this->graph->edges, $fqcnToFilePath);
        $this->recordStep('find affected scheduled jobs', $t, ' ('.count($affectedScheduledJobs).')');

        $t = microtime(true);
        $layerStack = LayerStack::fromConfig($this->projectType);
        $payload = new GraphPayload(
            nodes: $nodes,
            edges: $this->graph->edges,
            fileDiffs: $fileDiffs,
            analysisData: $analysisData,
            metricsData: $metricsData,
            fileContents: $fileContents,
            filterDefaults: $this->resolveFilterDefaults($filterDefaults),
            riskScore: $riskResult,
            affectedEndpoints: array_map(fn ($e) => $e->toArray(), $affectedEndpoints),
            affectedScheduledJobs: array_map(fn ($j) => $j->toArray(), $affectedScheduledJobs),
        );
        $pr = new PullRequestContext(
            prTitle: $prTitle,
            repo: $repoName,
            headCommit: $this->headCommit,
            prAdditions: $totalAdditions,
            prDeletions: $totalDeletions,
            fileCount: $fileCount,
            prUrl: $prLinkUrl,
            connectedCount: count($this->graph->connectedNodes),
            prComments: $this->prComments,
            inlineComments: $this->inlineComments,
        );

        $extraOptions = $onPayloadReady !== null ? ($onPayloadReady)($payload, $pr, $layerStack) ?? [] : [];

        $reportGenerator = $format->generator(array_merge(
            ['metrics' => $githubMetrics, 'focus' => $focusFiles],
            $extraOptions,
        ));
        $content = $reportGenerator->generate(
            layerStack: $layerStack,
            payload: $payload,
            pr: $pr,
            defaultView: $view,
        );
        $this->recordStep('generate report', $t);

        if ($raw) {
            return ['files' => [], 'risk' => $riskResult, 'content' => $content];
        }

        if ($outputPath !== null && (is_dir($outputPath) || str_ends_with($outputPath, '/'))) {
            $outputPath = $this->resolveOutputPath($format, $outputPath);
        } elseif ($outputPath !== null) {
            $dir = dirname($outputPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $outputPath ??= $this->resolveOutputPath($format);

        $reportGenerator->writeFile($outputPath, $content);

        if (! empty($this->headCommit) && $this->repoDir !== null && ! $this->isRepoUrl) {
            file_put_contents(dirname($outputPath).DIRECTORY_SEPARATOR.'commit', $this->headCommit."\n");
        }

        $this->progress('line', "  Generated: {$outputPath}");
        if ($this->githubCallCount > 0) {
            $rateLimitSuffix = $this->formatRateLimitSuffix();
            $this->progress('timing', "  GitHub API/fetch calls: {$this->githubCallCount}{$rateLimitSuffix}");
        }
        $this->logTimingBreakdown();
        $this->progress('info', 'Done! ('.$this->elapsed($this->analyzeStart).' total)');

        return ['files' => ['all' => $outputPath], 'risk' => $riskResult];
    }

    // ── State ────────────────────────────────────────────────────────────────

    private function resetState(): void
    {
        $this->graph = new DependencyGraph;
        $this->fqcnIndex = new FqcnNodeIndex;
        $this->psr4Resolver = null;
        $this->repoPath = '';
        $this->repoDir = null;
        $this->prRepo = '';
        $this->prComments = [];
        $this->inlineComments = [];
        $this->readContentsFromCommit = false;
        $this->isRepoUrl = false;
    }

    private function resolveWatchedFiles(?array $watchedFiles): array
    {
        return $watchedFiles ?? config('analysis.watched_files') ?? config('laravel-code-analytics.watched_files', []);
    }

    private function resolveFilterDefaults(array $filterDefaults): array
    {
        return empty($filterDefaults) ? config('laravel-code-analytics.filter_defaults', []) : $filterDefaults;
    }

    private function resolveOutputPath(OutputFormat $format, ?string $outputDir = null): string
    {
        $ext = $format->fileExtension();

        // PR mode default: structured reports/<org>/<repo>/pr-<number>/ directory
        if ($outputDir === null && $this->repoDir !== null && ! $this->isRepoUrl && $this->prRepo !== '') {
            $prNumber = preg_replace('/[^0-9]/', '', $this->branchName);
            [$org, $repo] = array_pad(explode('/', $this->prRepo, 2), 2, 'unknown');
            $dir = getcwd()."/reports/{$org}/{$repo}/pr-{$prNumber}";
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            return "{$dir}/report.{$ext}";
        }

        $outputDir = rtrim($outputDir ?? getcwd().'/output', '/');
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $safeBranch = preg_replace('/[^a-zA-Z0-9._-]/', '-', $this->branchName);

        if ($this->repoDir !== null) {
            if ($this->isRepoUrl) {
                $safeRepo = preg_replace('/[^a-zA-Z0-9._-]/', '-', str_replace('/', '-', $this->prRepo));

                return "{$outputDir}/repo-{$safeRepo}-{$safeBranch}.{$ext}";
            }

            return "{$outputDir}/pr-".preg_replace('/[^0-9]/', '', $this->branchName).".{$ext}";
        }

        return "{$outputDir}/local-{$safeBranch}.{$ext}";
    }

    // ── Pipeline steps ───────────────────────────────────────────────────────

    /**
     * @param  list<array{path: string, additions: int, deletions: int}>  $files
     * @param  list<string>  $patterns
     * @return array{0: list<array>, 1: int, 2: int}
     */
    private function applyFilePatternFilter(array $files, array $patterns): array
    {
        $files = array_values(array_filter($files, function (array $file) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (str_starts_with($pattern, '*.')) {
                    $ext = substr($pattern, 2);
                    if (str_ends_with($file['path'], '.'.$ext)) {
                        return true;
                    }

                    continue;
                }
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
        } else {
            $this->progress('line', '  File filter applied: '.implode(', ', $patterns));
        }

        return [$files, $totalAdditions, $totalDeletions];
    }

    private function buildFileDiffMap(): array
    {
        $parsedFileDiffs = (new DiffParser)->parse($this->diff);
        $fileDiffMap = [];
        foreach ($parsedFileDiffs as $fd) {
            $fileDiffMap[$fd->effectivePath()] = $fd;
        }

        return $fileDiffMap;
    }

    private function buildNodes(array $files, array $fileDiffMap, array $watchedFiles): array
    {
        $this->progress('info', 'Classifying files...');

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

        return $this->assignDomainColors($nodes);
    }

    /**
     * @return array{0: array, 1: array, 2: array<string, string|null>}
     */
    private function resolveHeadContents(array $nodes): array
    {
        $this->progress('info', 'Reading file contents...');

        $frontendExts = ['jsx', 'tsx', 'vue', 'js', 'ts'];
        $phpFiles = array_filter($nodes, fn ($n) => str_ends_with($n['path'], '.php'));
        $frontendFiles = array_filter($nodes, fn ($n) => in_array($n['ext'], $frontendExts));

        $allFilePaths = array_merge(
            array_column(array_values($phpFiles), 'path'),
            array_column(array_values($frontendFiles), 'path'),
        );

        if ($this->repoDir !== null) {
            $headContents = $this->readFileContentsFromGit($allFilePaths);
        } elseif ($this->readContentsFromCommit) {
            // Two-commit range mode: read file contents at the "to" commit
            $headContents = $this->readFileContentsFromLocalCommit($allFilePaths);
        } else {
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

        return [$phpFiles, $frontendFiles, $headContents];
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, array>}
     */
    private function buildDependencyGraph(array $nodes, array $phpFiles, array $frontendFiles, array $headContents): array
    {
        $this->progress('info', 'Extracting dependencies...');

        $fqcnToFilePath = $this->buildFqcnToFilePath($headContents);
        $this->populateNodeLookupMaps($nodes, array_flip($fqcnToFilePath));

        $componentNameToNode = $this->buildComponentNameMap($nodes);
        $fileReferences = $this->processPhpDependencies($phpFiles, $headContents);
        $this->processFrontendDependencies($frontendFiles, $headContents, $componentNameToNode);
        $this->processBladeDependents();

        $this->progress('line', '  Found '.count($this->graph->edges).' dependencies.');

        return [$fqcnToFilePath, $fileReferences];
    }

    private function buildFqcnToFilePath(array $headContents): array
    {
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

        return $fqcnToFilePath;
    }

    private function populateNodeLookupMaps(array $nodes, array $filePathToFqcn): void
    {
        foreach ($nodes as $node) {
            $this->graph->pathToNode[$node['path']] = $node['id'];
            if (str_ends_with($node['path'], '.php')) {
                $fqcn = $filePathToFqcn[$node['path']] ?? $this->psr4Resolver()->fqcnForPath($node['path']);
                if ($fqcn) {
                    $this->fqcnIndex->diffNodes[$fqcn] = $node['id'];
                }
            }
        }
    }

    private function psr4Resolver(): Psr4Resolver
    {
        return $this->psr4Resolver ??= new Psr4Resolver($this->repoPath, $this->repoDir, $this->headCommit);
    }

    private function buildComponentNameMap(array $nodes): array
    {
        $frontendExts = ['jsx', 'tsx', 'vue', 'js', 'ts'];
        $componentNameToNode = [];
        foreach ($nodes as $n) {
            if (in_array($n['ext'], $frontendExts)) {
                $basename = pathinfo($n['path'], PATHINFO_FILENAME);
                if (strtolower($basename) !== 'index') {
                    $componentNameToNode[$basename] = $n['id'];
                }
            }
        }

        return $componentNameToNode;
    }

    private function processPhpDependencies(array $phpFiles, array $headContents): array
    {
        $fileReferences = [];
        $commandSignatureIndex = $this->buildCommandSignatureIndex();
        foreach ($phpFiles as $node) {
            $content = $headContents[$node['path']] ?? null;
            if ($content === null || $content === '') {
                continue;
            }
            $references = $this->extractReferences($content);
            $this->matchReferences($references, $node['id']);
            $this->matchViewReferences($content, $node['id'], $node['path']);
            $this->matchScheduleReferences($content, $node['id'], $commandSignatureIndex);
            $fileReferences[$node['path']] = $references;
        }

        return $fileReferences;
    }

    private function processFrontendDependencies(array $frontendFiles, array $headContents, array $componentNameToNode): void
    {
        foreach ($frontendFiles as $node) {
            $content = $headContents[$node['path']] ?? null;
            if ($content === null || $content === '') {
                continue;
            }
            $this->matchComponentReferences($content, $node['id'], $componentNameToNode);
        }
    }

    /**
     * Scan every Blade file in the repo and add reverse edges:
     * non-diff files that @extend/@include a changed Blade file become connected nodes.
     */
    private function processBladeDependents(): void
    {
        $changedBladePaths = array_filter(
            array_keys($this->graph->pathToNode),
            fn ($p) => str_ends_with($p, '.blade.php')
        );

        if (empty($changedBladePaths)) {
            return;
        }

        $changedBladePathSet = array_flip($changedBladePaths);

        $allBladePaths = $this->listAllBladeFiles();
        $nonDiffPaths = array_values(array_filter($allBladePaths, fn ($p) => ! isset($this->graph->pathToNode[$p])));

        if (empty($nonDiffPaths)) {
            return;
        }

        $contents = $this->readBulkFileContents($nonDiffPaths);
        $rule = new BladeDependencyRule;

        foreach ($contents as $path => $content) {
            if ($content === null || $content === '') {
                continue;
            }

            foreach ($rule->resolve($content) as $depPath) {
                if (! isset($changedBladePathSet[$depPath])) {
                    continue;
                }

                $dependentNodeId = $this->ensureConnectedBladeNode($path);
                if ($dependentNodeId !== null) {
                    $this->graph->addEdge($dependentNodeId, $this->graph->pathToNode[$depPath]);
                }
            }
        }
    }

    private function ensureConnectedBladeNode(string $path): ?string
    {
        $existing = $this->graph->nodeIdForPath($path);
        if ($existing !== null) {
            return $existing;
        }

        if ($this->repoDir === null && $this->repoPath !== '' && ! is_file("{$this->repoPath}/{$path}")) {
            return null;
        }

        $label = $this->generateLabel($path);
        $node = $this->connectedNodeFactory->make($path, $label);

        return $this->graph->registerConnectedNode($node);
    }

    /**
     * Returns repo-relative paths of all tracked .blade.php files.
     *
     * @return list<string>
     */
    private function listAllBladeFiles(): array
    {
        if ($this->repoDir !== null) {
            $output = trim(shell_exec("git -C {$this->repoDir} ls-tree -r {$this->headCommit} --name-only 2>/dev/null") ?? '');
        } elseif ($this->repoPath !== '') {
            $output = trim(shell_exec("git -C {$this->repoPath} ls-files '*.blade.php' 2>/dev/null") ?? '');
        } else {
            return [];
        }

        if (empty($output)) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", $output),
            fn ($p) => str_ends_with($p, '.blade.php')
        ));
    }

    /**
     * @return array<string, string> artisan command name → relative file path
     */
    private function buildCommandSignatureIndex(): array
    {
        $paths = $this->listCommandFiles();
        if (empty($paths)) {
            return [];
        }

        $contents = $this->readBulkFileContents($paths);
        $index = [];

        foreach ($contents as $path => $content) {
            if ($content === null || $content === '') {
                continue;
            }
            if (preg_match('/\$signature\s*=\s*[\'"]([^\'"]+)[\'"]/m', $content, $m)) {
                $commandName = explode(' ', trim($m[1]))[0];
                if ($commandName !== '') {
                    $index[$commandName] = $path;
                }
            }
        }

        return $index;
    }

    /** @return list<string> */
    private function listCommandFiles(): array
    {
        if ($this->repoDir !== null) {
            $output = trim(shell_exec("git -C {$this->repoDir} ls-tree -r {$this->headCommit} --name-only 2>/dev/null") ?? '');
            if (empty($output)) {
                return [];
            }

            return array_values(array_filter(
                explode("\n", $output),
                fn ($p) => str_contains($p, '/Commands/') && str_ends_with($p, '.php'),
            ));
        }

        if ($this->repoPath !== '') {
            $output = trim(shell_exec("git -C {$this->repoPath} ls-files 2>/dev/null") ?? '');
            if (empty($output)) {
                return [];
            }

            return array_values(array_filter(
                array_map('trim', explode("\n", $output)),
                fn ($p) => str_contains($p, '/Commands/') && str_ends_with($p, '.php'),
            ));
        }

        return [];
    }

    /**
     * Read file contents for the given paths using whichever strategy is active.
     *
     * @param  list<string>  $paths
     * @return array<string, string|null>
     */
    private function readBulkFileContents(array $paths): array
    {
        if (empty($paths)) {
            return [];
        }

        if ($this->repoDir !== null) {
            return $this->readFileContentsFromGit($paths);
        }

        if ($this->readContentsFromCommit) {
            return $this->readFileContentsFromLocalCommit($paths);
        }

        $contents = [];
        foreach ($paths as $path) {
            $fullPath = "{$this->repoPath}/{$path}";
            if (is_file($fullPath)) {
                $content = file_get_contents($fullPath);
                $contents[$path] = $content !== false && $content !== '' ? $content : null;
            } else {
                $contents[$path] = null;
            }
        }

        return $contents;
    }

    /**
     * Detect circular dependencies and annotate each node with cycleId/cycleColor.
     *
     * @return array{0: array, 1: array<string, int>}
     */
    private function detectAndAnnotateCycles(array $nodes): array
    {
        $cycleMap = $this->detectCycles($nodes, $this->graph->edges);
        $cycleColorPalette = ['#f0883e', '#a371f7', '#3dcfcf', '#ff6b9d', '#ffd93d', '#6bcb77', '#4d96ff', '#ff6b6b'];

        foreach ($nodes as &$node) {
            $cycleId = $cycleMap[$node['id']] ?? null;
            $node['cycleId'] = $cycleId;
            $node['cycleColor'] = $cycleId !== null
                ? $cycleColorPalette[($cycleId - 1) % count($cycleColorPalette)]
                : null;
        }
        unset($node);

        if (! empty($cycleMap)) {
            $cycleGroupCount = count(array_unique($cycleMap));
            $cycleNodeCount = count($cycleMap);
            $this->progress('line', "  Detected {$cycleGroupCount} circular dependency group(s) across {$cycleNodeCount} file(s).");
        }

        return [$nodes, $cycleMap];
    }

    /** @return array{0: array, 1: array<string, int>} */
    private function detectAndAnnotateClusters(array $nodes): array
    {
        $clusterColorPalette = ['#4d96ff', '#6bcb77', '#ffd93d', '#a371f7', '#ff6b9d', '#3dcfcf', '#f0883e', '#ff6b6b'];

        $diffNodes = array_values(array_filter($nodes, fn ($n) => empty($n['isConnected']) && ! $this->isTestFile($n['path'])));
        $diffIds = array_flip(array_column($diffNodes, 'id'));

        $diffEdges = array_values(array_filter(
            $this->graph->edges,
            fn ($e) => isset($diffIds[$e[0]], $diffIds[$e[1]])
        ));

        $clusterMap = $this->detectClusters($diffNodes, $diffEdges);

        $multiNodeClusters = array_count_values($clusterMap);

        foreach ($nodes as &$node) {
            $clusterId = $clusterMap[$node['id']] ?? null;
            $size = $clusterId !== null ? ($multiNodeClusters[$clusterId] ?? 1) : 0;
            if ($size <= 1) {
                $clusterId = null;
            }
            $node['clusterId'] = $clusterId;
            $node['clusterSize'] = $clusterId !== null ? $size : null;
        }
        unset($node);

        // Re-number surviving clusters to contiguous 1-based integers so no two clusters
        // ever share the same display label (gaps appear when singletons are removed).
        $finalRemap = [];
        $nextFinalId = 0;
        foreach ($nodes as &$node) {
            if ($node['clusterId'] !== null) {
                if (! isset($finalRemap[$node['clusterId']])) {
                    $finalRemap[$node['clusterId']] = ++$nextFinalId;
                }
                $node['clusterId'] = $finalRemap[$node['clusterId']];
            }
            $node['clusterColor'] = $node['clusterId'] !== null
                ? $clusterColorPalette[($node['clusterId'] - 1) % count($clusterColorPalette)]
                : null;
        }
        unset($node);

        // Compute a descriptive name for each surviving cluster from its file paths.
        $clusterPaths = [];
        foreach ($nodes as $node) {
            if ($node['clusterId'] !== null) {
                $clusterPaths[$node['clusterId']][] = $node['path'];
            }
        }

        $clusterNames = [];
        foreach ($clusterPaths as $id => $paths) {
            $clusterNames[$id] = $this->clusterNameFromPaths($paths);
        }

        // Deduplicate: when a name appears more than once, number all occurrences (1, 2, 3 …).
        $nameCounts = array_count_values($clusterNames);
        $nameCounters = [];
        foreach ($clusterNames as $id => $name) {
            if ($nameCounts[$name] > 1) {
                $nameCounters[$name] = ($nameCounters[$name] ?? 0) + 1;
                $clusterNames[$id] = $name.' '.$nameCounters[$name];
            }
        }

        foreach ($nodes as &$node) {
            $node['clusterName'] = $node['clusterId'] !== null
                ? ($clusterNames[$node['clusterId']] ?? null)
                : null;
        }
        unset($node);

        $multiCount = count(array_filter($multiNodeClusters, fn ($c) => $c > 1));
        if ($multiCount > 0) {
            $this->progress('line', "  Detected {$multiCount} review cluster(s) among changed files.");
        }

        return [$nodes, $clusterMap];
    }

    /**
     * Derive a short, human-readable name for a cluster from its constituent file paths.
     *
     * Strategy:
     *  1. Find the longest common directory prefix across all paths.
     *  2. Strip generic leading segments (app, src, resources, js, ts) that carry no domain meaning.
     *  3. Return the last 1-2 remaining segments as a slash-separated label (e.g. "Http/Controllers").
     *  4. If no common prefix survives, fall back to the most frequently occurring directory segment.
     *
     * @param  list<string>  $paths
     */
    private function clusterNameFromPaths(array $paths): string
    {
        if (empty($paths)) {
            return 'Mixed';
        }

        $generic = ['app', 'src', 'resources', 'js', 'ts', 'lib', 'source'];

        $allDirSegs = array_map(function (string $path) use ($generic): array {
            $dir = dirname($path);
            if ($dir === '.' || $dir === '') {
                return [];
            }
            $segs = explode('/', $dir);
            // Strip generic leading segments (e.g. app/Http/... → Http/...)
            while (! empty($segs) && in_array($segs[0], $generic, true)) {
                array_shift($segs);
            }

            return $segs;
        }, $paths);

        // Longest common prefix of the (already-stripped) dir segment arrays.
        $common = $allDirSegs[0];
        foreach (array_slice($allDirSegs, 1) as $segs) {
            $newCommon = [];
            $limit = min(count($common), count($segs));
            for ($i = 0; $i < $limit; $i++) {
                if ($common[$i] !== $segs[$i]) {
                    break;
                }
                $newCommon[] = $common[$i];
            }
            $common = $newCommon;
        }

        if (! empty($common)) {
            // Keep at most the last 2 segments so names stay short.
            return implode('/', array_slice($common, -2));
        }

        // Fallback: pick the most common individual directory segment.
        $freq = [];
        $skip = array_merge($generic, ['.', '']);
        foreach ($allDirSegs as $segs) {
            foreach ($segs as $seg) {
                if (! in_array($seg, $skip, true)) {
                    $freq[$seg] = ($freq[$seg] ?? 0) + 1;
                }
            }
        }

        if (! empty($freq)) {
            arsort($freq);

            return (string) array_key_first($freq);
        }

        return 'Mixed';
    }

    /**
     * Greedy Louvain-style modularity clustering.
     *
     * Unlike plain BFS (which makes everything one cluster when the graph is dense),
     * Louvain penalises merging high-degree nodes: an edge only contributes a positive
     * modularity gain when it exceeds the expected number of edges by chance.  Hub files
     * that many actions share therefore cannot force unrelated files into the same cluster.
     *
     * @return array<string, int> nodeId → clusterId (1-based)
     */
    private function detectClusters(array $nodes, array $edges): array
    {
        if (empty($nodes)) {
            return [];
        }

        $ids = array_column($nodes, 'id');
        $idSet = array_flip($ids);
        $degree = array_fill_keys($ids, 0);
        $adj = array_fill_keys($ids, []);

        foreach ($edges as [$src, $tgt]) {
            if ($src === $tgt || ! isset($idSet[$src], $idSet[$tgt])) {
                continue;
            }
            $adj[$src][] = $tgt;
            $adj[$tgt][] = $src;
            $degree[$src]++;
            $degree[$tgt]++;
        }

        $m = array_sum($degree) / 2;

        if ($m == 0) {
            $clusters = [];
            $i = 1;
            foreach ($ids as $id) {
                $clusters[$id] = $i++;
            }

            return $clusters;
        }

        // Each node starts in its own community.
        $community = [];
        $commDegSum = []; // Σ_C: sum of degrees in community C
        foreach ($ids as $i => $id) {
            $community[$id] = $i;
            $commDegSum[$i] = $degree[$id];
        }

        $twoM = 2.0 * $m;

        for ($iter = 0; $iter < 20; $iter++) {
            $moved = false;

            foreach ($ids as $nodeId) {
                $currentComm = $community[$nodeId];
                $ki = $degree[$nodeId];

                // Tally edges to each neighbouring community.
                $edgesToComm = [];
                foreach ($adj[$nodeId] as $nb) {
                    $nc = $community[$nb];
                    $edgesToComm[$nc] = ($edgesToComm[$nc] ?? 0) + 1;
                }

                // Modularity gain of leaving the current community.
                $eInCurrent = $edgesToComm[$currentComm] ?? 0;
                $scoreLeaveCurrent = $eInCurrent - $ki * ($commDegSum[$currentComm] - $ki) / $twoM;

                $bestComm = $currentComm;
                $bestGain = 0.0;

                foreach ($edgesToComm as $nc => $eToNc) {
                    if ($nc === $currentComm) {
                        continue;
                    }
                    // Modularity gain of joining community $nc.
                    $gain = ($eToNc - $ki * $commDegSum[$nc] / $twoM) - $scoreLeaveCurrent;
                    if ($gain > $bestGain) {
                        $bestGain = $gain;
                        $bestComm = $nc;
                    }
                }

                if ($bestComm !== $currentComm) {
                    $commDegSum[$currentComm] -= $ki;
                    $commDegSum[$bestComm] += $ki;
                    $community[$nodeId] = $bestComm;
                    $moved = true;
                }
            }

            if (! $moved) {
                break;
            }
        }

        // Compact community IDs to 1-based contiguous integers.
        $remap = [];
        $next = 0;
        $clusters = [];
        foreach ($ids as $id) {
            $c = $community[$id];
            if (! isset($remap[$c])) {
                $remap[$c] = ++$next;
            }
            $clusters[$id] = $remap[$c];
        }

        return $clusters;
    }

    private function isTestFile(string $path): bool
    {
        return str_starts_with($path, 'tests/')
            || str_starts_with($path, 'test/')
            || str_ends_with($path, 'Test.php')
            || (bool) preg_match('/\.(test|spec)\.[jt]sx?$/', $path);
    }

    /**
     * @return array{0: array, 1: array<string, string>}
     */
    private function runAstAnalysis(array $phpFiles, array $headContents, array $fileDiffMap, array $criticalTables = []): array
    {
        $this->progress('info', 'Running AST analysis...');

        $t = microtime(true);
        $oldSources = $this->fetchOldSources($phpFiles, $fileDiffMap);
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' fetching base sources ('.count($oldSources).' files)');

        // Only files with a parsed diff get classified.
        $workable = array_values(array_filter(
            $phpFiles,
            fn ($node) => isset($fileDiffMap[$node['path']]),
        ));

        $t = microtime(true);
        $fileReports = $this->classifyFiles($workable, $headContents, $fileDiffMap, $oldSources, $criticalTables);
        $this->progress('line', '  Analyzed '.count($fileReports).' PHP files.');
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' AST parse + classify');

        return [$this->correlateWithMigrations($fileReports, $headContents), $oldSources];
    }

    /**
     * Classify every PHP file, forking worker processes when there are enough
     * files for the parallelism to pay off. AST parsing + the ~50 classifier
     * rules are pure CPU work per file, so forking (which inherits the booted
     * framework copy-on-write — no re-bootstrap, no argument marshalling) scales
     * it across cores. Falls back to a serial pass when pcntl is unavailable,
     * the file count is small, or any worker fails.
     *
     * @param  list<array>  $nodes
     * @param  array<string, string|null>  $headContents
     * @param  array<string, mixed>  $fileDiffMap
     * @param  array<string, string>  $oldSources
     * @param  list<string>  $criticalTables
     * @return array<string, FileReport>
     */
    private function classifyFiles(array $nodes, array $headContents, array $fileDiffMap, array $oldSources, array $criticalTables): array
    {
        $workers = $this->astWorkerCount(count($nodes));

        if ($workers < 2) {
            return $this->classifyChunk($nodes, $headContents, $fileDiffMap, $oldSources, $criticalTables);
        }

        $chunks = array_chunk($nodes, (int) ceil(count($nodes) / $workers));

        $parallel = $this->classifyChunksForked($chunks, $headContents, $fileDiffMap, $oldSources, $criticalTables);
        if ($parallel !== null) {
            $this->progress('timing', '  ↳ classified across '.count($chunks).' worker process(es)');

            return $parallel;
        }

        // A worker failed to fork or return usable data — recompute serially.
        $this->progress('warn', '  Parallel AST workers unavailable; falling back to serial.');

        return $this->classifyChunk($nodes, $headContents, $fileDiffMap, $oldSources, $criticalTables);
    }

    /**
     * Classify a list of file nodes in the current process.
     *
     * @param  list<array>  $nodes
     * @param  array<string, string|null>  $headContents
     * @param  array<string, mixed>  $fileDiffMap
     * @param  array<string, string>  $oldSources
     * @param  list<string>  $criticalTables
     * @return array<string, FileReport>
     */
    private function classifyChunk(array $nodes, array $headContents, array $fileDiffMap, array $oldSources, array $criticalTables): array
    {
        $astComparer = new AstComparer;
        $changeClassifier = new ChangeClassifier($astComparer, $this->projectType, $this->repoPath ?: null, $criticalTables);

        $reports = [];
        foreach ($nodes as $node) {
            $filePath = $node['path'];
            $fileDiff = $fileDiffMap[$filePath] ?? null;
            if ($fileDiff === null) {
                continue;
            }
            $oldSource = $oldSources[$filePath] ?? null;
            $newSource = $fileDiff->status !== FileStatus::DELETED ? ($headContents[$filePath] ?? null) : null;
            $comparison = $astComparer->compare($oldSource, $newSource);
            $reports[$filePath] = $changeClassifier->classify($fileDiff, $comparison, $newSource);
        }

        return $reports;
    }

    /**
     * Fork one worker per chunk, classify in parallel, and merge the serialized
     * FileReports. Success is judged by each worker's output file being present
     * and unserializing to an array — so workers can hard-exit (skipping the
     * parent's shutdown handlers) without the exit status mattering.
     *
     * @param  list<list<array>>  $chunks
     * @param  array<string, string|null>  $headContents
     * @param  array<string, mixed>  $fileDiffMap
     * @param  array<string, string>  $oldSources
     * @param  list<string>  $criticalTables
     * @return array<string, FileReport>|null
     */
    private function classifyChunksForked(array $chunks, array $headContents, array $fileDiffMap, array $oldSources, array $criticalTables): ?array
    {
        $tmpDir = sys_get_temp_dir().'/lca_ast_'.uniqid();
        if (! @mkdir($tmpDir, 0700, true) && ! is_dir($tmpDir)) {
            return null;
        }

        $children = []; // pid => output file
        foreach ($chunks as $i => $chunk) {
            $outFile = $tmpDir.'/'.$i.'.bin';
            $pid = pcntl_fork();

            if ($pid === -1) {
                // Fork failed mid-loop: reap whatever started, then bail to serial.
                foreach (array_keys($children) as $startedPid) {
                    $st = 0;
                    pcntl_waitpid($startedPid, $st);
                }
                $this->cleanupTmpDir($tmpDir, $children);

                return null;
            }

            if ($pid === 0) {
                // ── Child ──
                try {
                    $reports = $this->classifyChunk($chunk, $headContents, $fileDiffMap, $oldSources, $criticalTables);
                    file_put_contents($outFile, serialize($reports));
                } catch (\Throwable) {
                    // Leave the output file absent → the parent falls back to serial.
                }
                $this->terminateChild();
            }

            $children[$pid] = $outFile;
        }

        // ── Parent: wait for every worker ──
        foreach (array_keys($children) as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
        }

        $reports = [];
        foreach ($children as $outFile) {
            $data = is_file($outFile) ? file_get_contents($outFile) : false;
            $chunkReports = ($data !== false && $data !== '') ? @unserialize($data) : false;

            if (! is_array($chunkReports)) {
                $this->cleanupTmpDir($tmpDir, $children);

                return null;
            }

            // Keys are file paths, unique across chunks.
            $reports += $chunkReports;
        }

        $this->cleanupTmpDir($tmpDir, $children);

        return $reports;
    }

    /**
     * Number of worker processes to use for AST classification. Returns 1 (serial)
     * when forking is unavailable or the file count is too small to be worth it.
     */
    private function astWorkerCount(int $fileCount): int
    {
        // Escape hatch / override: LCA_AST_WORKERS=1 forces serial.
        $override = getenv('LCA_AST_WORKERS');
        if ($override !== false && is_numeric($override)) {
            return max(1, (int) $override);
        }

        if ($fileCount < 8 || ! function_exists('pcntl_fork')) {
            return 1;
        }

        // Aim for ~4+ files per worker, capped at the available CPU cores.
        return max(1, min($this->cpuCount(), intdiv($fileCount, 4)));
    }

    private function cpuCount(): int
    {
        $nproc = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
        if ($nproc > 0) {
            return $nproc;
        }

        $sysctl = (int) trim((string) @shell_exec('sysctl -n hw.ncpu 2>/dev/null'));

        return $sysctl > 0 ? $sysctl : 4;
    }

    /**
     * Terminate a forked worker. Results are already flushed to disk, so skip the
     * parent's shutdown handlers / destructors to avoid duplicated side effects.
     */
    private function terminateChild(): never
    {
        if (function_exists('posix_kill') && function_exists('posix_getpid')) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        exit(0);
    }

    /**
     * @param  array<int, string>  $children  pid => output file
     */
    private function cleanupTmpDir(string $tmpDir, array $children): void
    {
        foreach ($children as $outFile) {
            if (is_file($outFile)) {
                @unlink($outFile);
            }
        }
        if (is_dir($tmpDir)) {
            @rmdir($tmpDir);
        }
    }

    private function correlateWithMigrations(array $fileReports, array $headContents): array
    {
        if ($this->projectType !== ProjectType::LaravelApp) {
            return $fileReports;
        }

        [$fileReports, $pairs] = (new LaravelMigrationModelCorrelator)->correlate(
            $fileReports,
            $headContents,
            $this->repoDir !== null ? null : $this->repoPath,
        );

        foreach ($pairs as [$migrationPath, $modelPath]) {
            $migrationNodeId = $this->graph->pathToNode[$migrationPath] ?? null;
            $modelNodeId = $this->graph->pathToNode[$modelPath] ?? null;

            if ($migrationNodeId !== null && $modelNodeId !== null) {
                $this->graph->addEdge($migrationNodeId, $modelNodeId, PhpDependencyExtractor::MIGRATION_MODEL);
            }
        }

        return $fileReports;
    }

    private function enrichNodesWithAnalysis(array $nodes, array $fileReports): array
    {
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

        return $nodes;
    }

    private function enrichNodesWithKind(array $nodes, array $headContents): array
    {
        foreach ($nodes as &$node) {
            $content = $headContents[$node['path']] ?? null;
            if ($content !== null) {
                $node['kind'] = $this->extractNodeKind($content, $node['ext']);
            }
        }
        unset($node);

        return $nodes;
    }

    private function extractNodeKind(string $content, string $ext): ?string
    {
        if ($ext === 'php') {
            if (preg_match('/^\s*(?:(abstract)\s+)?(?:final\s+|readonly\s+)*(class|interface|trait|enum)\s+/m', $content, $m)) {
                $keyword = $m[2];
                if ($keyword === 'class' && ! empty($m[1])) {
                    return NodeKind::ABSTRACT->value;
                }

                return NodeKind::from($keyword)->value;
            }

            return null;
        }

        if (in_array($ext, ['ts', 'tsx', 'js', 'jsx'])) {
            if (preg_match('/\bexport\s+(?:default\s+)?(?:abstract\s+)?class\s+/m', $content)) {
                return NodeKind::CLASS_KIND->value;
            }
            if (preg_match('/\bexport\s+interface\s+\w/m', $content)) {
                return NodeKind::INTERFACE->value;
            }
            if (preg_match('/\bexport\s+(?:const\s+)?enum\s+\w/m', $content)) {
                return NodeKind::ENUM->value;
            }
            if (preg_match('/\bexport\s+type\s+\w/m', $content)) {
                return NodeKind::TYPE->value;
            }

            return null;
        }

        return null;
    }

    private function buildAnalysisData(array $fileReports, array $fileReferences): array
    {
        $analysisData = [];
        foreach ($fileReports as $filePath => $report) {
            $analysisData[$filePath] = array_map(fn ($c) => array_filter([
                'category' => $c->category->value,
                'severity' => $c->severity->value,
                'description' => $c->description,
                'location' => $c->location,
                // Every finding carries a line: a precise one where the rule
                // captured it, otherwise the class declaration line for class
                // member findings, or line 1 for file-level findings.
                'line' => $c->line ?? ($c->location !== null ? $report->primaryClassLine : 1),
            ], fn ($v) => $v !== null), $report->changes);
        }

        $depTypeLabels = [
            PhpDependencyExtractor::CONSTRUCTOR_INJECTION => 'constructor injection',
            PhpDependencyExtractor::METHOD_INJECTION => 'method injection',
            PhpDependencyExtractor::NEW_INSTANCE => 'new instance',
            PhpDependencyExtractor::CONTAINER_RESOLVED => 'container resolved',
            PhpDependencyExtractor::STATIC_CALL => 'static call',
            PhpDependencyExtractor::EXTENDS_REFERENCE => 'extends',
            PhpDependencyExtractor::IMPLEMENTS_REFERENCE => 'implements',
            PhpDependencyExtractor::PROPERTY_TYPE => 'property type',
        ];
        $skipTypes = [PhpDependencyExtractor::RETURN_TYPE, PhpDependencyExtractor::USE];

        foreach ($fileReferences as $filePath => $references) {
            if (! isset($analysisData[$filePath])) {
                continue;
            }
            foreach ($references as $class => $type) {
                if (in_array($type, $skipTypes, true)) {
                    continue;
                }
                $shortName = basename(str_replace('\\', '/', ltrim($class, '\\')));
                $entry = array_filter([
                    'category' => ChangeCategory::DEPENDENCY->value,
                    'severity' => Severity::INFO->value,
                    'description' => 'Depends on '.$shortName.' ('.($depTypeLabels[$type] ?? $type).')',
                    'location' => $type === PhpDependencyExtractor::CONSTRUCTOR_INJECTION ? '__construct' : null,
                    // A dependency belongs to this file's class — anchor it to the class declaration.
                    'line' => $fileReports[$filePath]->primaryClassLine,
                ], fn ($v) => $v !== null);
                $analysisData[$filePath][] = $entry;
            }
        }

        return $analysisData;
    }

    /**
     * @return array{0: array, 1: array}
     */
    private function injectCycleFindings(array $nodes, array $analysisData, array $cycleMap): array
    {
        if (empty($cycleMap)) {
            return [$nodes, $analysisData];
        }

        $cycleMembers = $this->buildCycleMemberMap($nodes);

        foreach ($nodes as &$node) {
            if (($node['cycleId'] ?? null) === null) {
                continue;
            }
            [$analysisData, $node] = $this->addCycleFinding($node, $analysisData, $cycleMembers);
        }
        unset($node);

        return [$nodes, $analysisData];
    }

    private function buildCycleMemberMap(array $nodes): array
    {
        $cycleMembers = [];
        foreach ($nodes as $n) {
            if (($n['cycleId'] ?? null) !== null) {
                $cycleMembers[$n['cycleId']][] = $n['path'];
            }
        }

        return $cycleMembers;
    }

    private function addCycleFinding(array $node, array $analysisData, array $cycleMembers): array
    {
        $others = array_filter($cycleMembers[$node['cycleId']], fn ($p) => $p !== $node['path']);
        $description = 'Circular dependency (cycle '.$node['cycleId'].'): '
            .implode(', ', array_map(fn ($p) => basename($p), $others));

        $analysisData[$node['path']] ??= [];
        $analysisData[$node['path']][] = [
            'category' => ChangeCategory::CIRCULAR_DEPENDENCY->value,
            'severity' => Severity::VERY_HIGH->value,
            'description' => $description,
        ];

        $node['severity'] = Severity::VERY_HIGH->value;
        $node['veryHighCount'] = ($node['veryHighCount'] ?? 0) + 1;
        $node['analysisCount'] = ($node['analysisCount'] ?? 0) + 1;

        return [$analysisData, $node];
    }

    private function collectFileContents(array $fileDiffs, array $preloaded = []): array
    {
        $diffPaths = array_keys($fileDiffs);
        if (empty($diffPaths)) {
            return [];
        }

        $toFetch = empty($preloaded)
            ? $diffPaths
            : array_values(array_filter($diffPaths, fn ($p) => ! array_key_exists($p, $preloaded)));

        if ($this->repoDir !== null) {
            $rawContents = $this->readFileContentsFromGit($toFetch);
        } elseif ($this->readContentsFromCommit) {
            $rawContents = $this->readFileContentsFromLocalCommit($toFetch);
        } else {
            $rawContents = $this->collectLocalFileContents($toFetch);
        }

        $rawContents = array_merge($preloaded, $rawContents);

        $fileContents = [];
        foreach ($diffPaths as $path) {
            $content = $rawContents[$path] ?? null;
            if ($content !== null && strlen($content) <= 500_000) {
                $fileContents[$path] = $content;
            }
        }

        return $fileContents;
    }

    /**
     * Read the current-state file contents for connected (non-diff) nodes so they
     * can be shown in the panel without needing --full-files.
     *
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function loadConnectedNodeContents(array $paths): array
    {
        if (empty($paths)) {
            return [];
        }

        if ($this->repoDir !== null) {
            $raw = $this->readFileContentsFromGit($paths);
        } elseif ($this->readContentsFromCommit) {
            $raw = $this->readFileContentsFromLocalCommit($paths);
        } else {
            $raw = $this->collectLocalFileContents($paths);
        }

        $result = [];
        foreach ($paths as $path) {
            $content = $raw[$path] ?? null;
            if ($content !== null && $content !== '' && strlen($content) <= 500_000) {
                $result[$path] = $content;
            }
        }

        return $result;
    }

    /** @return array<string, ?string> */
    private function collectLocalFileContents(array $diffPaths): array
    {
        $rawContents = [];
        foreach ($diffPaths as $path) {
            $fullPath = "{$this->repoPath}/{$path}";
            if (is_file($fullPath)) {
                $content = file_get_contents($fullPath);
                $rawContents[$path] = $content !== false ? $content : null;
            }
        }

        return $rawContents;
    }

    private function computeSignalScores(array $nodes, array $analysisData, array $metricsData, array $cycleMap = [], array $fileSignalConfig = []): array
    {
        $cycleCfg = $fileSignalConfig['circular_dependency'] ?? config('laravel-code-analytics.file_signal.circular_dependency', []);
        $cycleBoostBase = (int) ($cycleCfg['base'] ?? 100);
        $cycleBoostPct = (float) ($cycleCfg['signal_pct'] ?? 0.20);

        $connCfg = $fileSignalConfig['pr_connections'] ?? config('laravel-code-analytics.file_signal.pr_connections', []);
        $connMultiplier = (float) ($connCfg['multiplier'] ?? 5);

        $scorer = $fileSignalConfig !== [] ? new CalculateFileSignal($fileSignalConfig) : $this->fileSignalScorer;

        // Count edges between changed (diff) files only — exclude connected nodes.
        $diffNodes = array_values(array_filter($nodes, fn ($n) => empty($n['isConnected'])));
        $changedIds = array_flip(array_column($diffNodes, 'id'));
        $internalConnections = array_fill_keys(array_column($diffNodes, 'id'), 0);

        foreach ($this->graph->edges as [$sourceId, $targetId]) {
            if (isset($changedIds[$sourceId], $changedIds[$targetId])) {
                $internalConnections[$sourceId]++;
                $internalConnections[$targetId]++;
            }
        }

        foreach ($nodes as &$node) {
            $result = $scorer->calculate(
                $node,
                $analysisData[$node['path']] ?? [],
                $metricsData[$node['path']] ?? null,
            );
            $base = $result['score'];
            $breakdown = $result['breakdown'];

            $node['_baseSignal'] = $base;
            if (($node['cycleId'] ?? null) !== null) {
                $boost = (int) round($cycleBoostBase + $cycleBoostPct * $base);
                $node['_signal'] = $base + $boost;
                $node['_cycleBoost'] = $boost;
                $breakdown['cycle_boost'] = $boost;
            } else {
                $node['_signal'] = $base;
            }

            $connections = $internalConnections[$node['id']] ?? 0;
            if ($connections > 0) {
                $connBoost = (int) round($connections * $connMultiplier);
                $node['_signal'] += $connBoost;
                $node['_connectionBoost'] = $connBoost;
                $node['_connections'] = $connections;
                $breakdown['connection_boost'] = $connBoost;
            }

            $node['_signalBreakdown'] = $breakdown;
        }
        unset($node);

        return $nodes;
    }

    private function applyMinSeverityFilter(array $nodes, array $analysisData, array $metricsData, array $fileDiffs, array $fileContents, Severity $minSeverity): array
    {
        ['nodes' => $nodes, 'analysisData' => $analysisData, 'metricsData' => $metricsData, 'fileDiffs' => $fileDiffs, 'fileContents' => $fileContents]
            = (new MinSeverityFilter)->apply($nodes, $analysisData, $metricsData, $fileDiffs, $minSeverity, $fileContents);

        $fileCount = count($nodes);
        $this->progress('line', "  After min-severity filter ({$minSeverity->value}): {$fileCount} files.");

        return [
            'nodes' => $nodes,
            'analysisData' => $analysisData,
            'metricsData' => $metricsData,
            'fileDiffs' => $fileDiffs,
            'fileContents' => $fileContents,
            'fileCount' => $fileCount,
            'totalAdditions' => array_sum(array_column($nodes, 'add')),
            'totalDeletions' => array_sum(array_column($nodes, 'del')),
        ];
    }

    private function computeRiskScore(array $nodes, int $totalAdditions, int $totalDeletions, int $fileCount, int $hotSpots, array $riskScoringConfig): RiskScore
    {
        $scorer = $riskScoringConfig !== [] ? new CalculateRiskScore($riskScoringConfig) : $this->riskScorer;

        return $scorer->calculate($nodes, $totalAdditions, $totalDeletions, $fileCount, $hotSpots);
    }

    // ── Repo URL mode ────────────────────────────────────────────────────────

    /**
     * Fetch repo metadata from GitHub, shallow-clone all git objects, and return
     * all tracked files at HEAD (or the specified branch) for full-repo analysis.
     *
     * @return array{files: list<array{path: string, additions: int, deletions: int}>, totalAdditions: int, totalDeletions: int, repoName: string, prTitle: string, prLinkUrl: string}
     */
    private function initFromRepoUrl(string $repoUrl, ?string $branch = null): array
    {
        if (! preg_match('~(?:https?://github\.com/)?([a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+?)(?:\.git)?(?:[/?#].*)?$~', $repoUrl, $m)) {
            throw new RuntimeException('Invalid GitHub repo URL. Expected: https://github.com/owner/repo');
        }

        $this->prRepo = $m[1];
        $this->isRepoUrl = true;

        $this->progress('info', "Fetching repo info for {$this->prRepo}...");

        $this->githubCallCount++;
        $repoJson = json_decode(
            trim(shell_exec('gh api '.escapeshellarg("repos/{$this->prRepo}").' 2>/dev/null') ?? ''),
            true,
        );

        if (! $repoJson || empty($repoJson['default_branch'])) {
            throw new RuntimeException('Could not fetch repo data. Make sure `gh` is authenticated and the repo exists.');
        }

        $defaultBranch = $branch ?? $repoJson['default_branch'];

        $this->githubCallCount++;
        $branchJson = json_decode(
            trim(shell_exec('gh api '.escapeshellarg("repos/{$this->prRepo}/branches/{$defaultBranch}").' 2>/dev/null') ?? ''),
            true,
        );

        if (! $branchJson || empty($branchJson['commit']['sha'])) {
            throw new RuntimeException("Could not fetch branch info for '{$defaultBranch}'.");
        }

        $this->headCommit = $branchJson['commit']['sha'];
        $this->baseCommit = $this->headCommit;
        $this->branchName = $defaultBranch;

        $this->progress('line', '  Branch: '.$defaultBranch.'  HEAD: '.substr($this->headCommit, 0, 7));

        $t = microtime(true);
        $this->resolveGitObjectsCache([], true);
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' git objects');

        if ($this->repoDir === null) {
            throw new RuntimeException('Could not fetch git objects for the repository.');
        }

        $allPaths = $this->listAllFilesAtCommit($this->repoDir, $this->headCommit);
        $this->diff = '';

        $repoName = $repoJson['name'] ?? basename($this->prRepo);
        $prTitle = $repoJson['full_name'] ?? $this->prRepo;
        $prLinkUrl = "https://github.com/{$this->prRepo}";

        return [
            'files' => array_map(fn ($p) => ['path' => $p, 'additions' => 0, 'deletions' => 0], $allPaths),
            'totalAdditions' => 0,
            'totalDeletions' => 0,
            'repoName' => $repoName,
            'prTitle' => $prTitle,
            'prLinkUrl' => $prLinkUrl,
        ];
    }

    // ── PR mode ──────────────────────────────────────────────────────────────

    /**
     * Fetch PR metadata and diff from GitHub, shallow-clone git objects, and
     * populate all internal state so the shared analysis pipeline can proceed.
     *
     * When $full is true, lists all files at the PR's HEAD commit instead of just
     * the PR diff — equivalent to --full in local mode.
     *
     * @return array{files: list<array{path: string, additions: int, deletions: int}>, totalAdditions: int, totalDeletions: int, repoName: string, prTitle: string}
     */
    private function initFromPrUrl(string $prUrl, bool $full = false): array
    {
        if (! preg_match('#https?://github\.com/([^/]+/[^/]+)/pull/(\d+)#', $prUrl, $m)) {
            throw new RuntimeException('Invalid GitHub PR URL. Expected: https://github.com/owner/repo/pull/123');
        }

        $this->prRepo = $m[1];
        $prNumber = $m[2];

        $this->progress('info', "Fetching PR #{$prNumber} from {$this->prRepo}...");

        $t = microtime(true);
        $this->githubCallCount++;
        $proc = proc_open(
            ['gh', 'pr', 'view', $prNumber, '--repo', $this->prRepo, '--json', 'title,additions,deletions,files,headRefOid,baseRefOid,headRefName,baseRefName,comments,reviews'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $stdout = $proc ? (string) stream_get_contents($pipes[1]) : '';
        $stderr = $proc ? (string) stream_get_contents($pipes[2]) : '';
        if ($proc) {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
        $prJson = json_decode(trim($stdout), true);

        if (! $prJson || empty($prJson['files'])) {
            $detail = trim($stderr) !== '' ? "\n".trim($stderr) : ' Make sure `gh` is authenticated and the PR URL is valid.';
            throw new RuntimeException('Could not fetch PR data.'.$detail);
        }

        // Fetch inline review comments via REST API (gh pr view --json does not support reviewThreads in older gh versions).
        $this->githubCallCount++;
        $prJson['reviewComments'] = $this->fetchPrReviewComments($prNumber);

        $this->headCommit = $prJson['headRefOid'] ?? '';
        $this->baseCommit = $prJson['baseRefOid'] ?? '';
        $this->branchName = "PR #{$prNumber}";
        $this->inlineComments = $this->extractInlineComments($prJson);
        $this->prComments = $this->extractPrComments($prJson);

        $this->progress('line', '  Title: '.$prJson['title']);
        $this->progress('line', '  Base: '.$prJson['baseRefName'].'  HEAD: '.substr($this->headCommit, 0, 7));
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' gh pr view');

        $t = microtime(true);
        $this->resolveGitObjectsCache(array_column($prJson['files'], 'path'), $full);
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' git objects');

        if ($full && $this->repoDir !== null) {
            $allPaths = $this->listAllFilesAtCommit($this->repoDir, $this->headCommit);
            $this->diff = '';

            return [
                'files' => array_map(fn ($p) => ['path' => $p, 'additions' => 0, 'deletions' => 0], $allPaths),
                'totalAdditions' => 0,
                'totalDeletions' => 0,
                'repoName' => basename($this->prRepo),
                'prTitle' => $prJson['title'],
            ];
        }

        $this->progress('info', 'Fetching diff...');
        $t = microtime(true);
        $diffCachePath = $this->headCommit !== ''
            ? storage_path('app/pr-cache/'.substr($this->headCommit, 0, 2).'/'.$this->headCommit.'.diff')
            : null;

        if ($diffCachePath !== null && is_file($diffCachePath)) {
            $this->diff = file_get_contents($diffCachePath);
            $this->progress('timing', '  ↳ '.$this->elapsed($t).' gh pr diff (cached)');
        } else {
            $this->githubCallCount++;
            $this->diff = trim(shell_exec('gh pr diff '.escapeshellarg($prNumber).' --repo '.escapeshellarg($this->prRepo).' 2>/dev/null') ?? '');
            $this->progress('timing', '  ↳ '.$this->elapsed($t).' gh pr diff');
            if ($diffCachePath !== null && str_contains($this->diff, 'diff --git')) {
                @mkdir(dirname($diffCachePath), 0755, true);
                file_put_contents($diffCachePath, $this->diff);
            }
        }

        if (! str_contains($this->diff, 'diff --git')) {
            throw new RuntimeException('Failed to fetch PR diff. Make sure `gh` is authenticated.');
        }

        return $this->buildPrInitResult($prJson, $this->mapPrFiles($prJson['files']));
    }

    private function mapPrFiles(array $prFiles): array
    {
        return array_map(fn ($f) => [
            'path' => $f['path'],
            'additions' => (int) ($f['additions'] ?? 0),
            'deletions' => (int) ($f['deletions'] ?? 0),
        ], $prFiles);
    }

    private function buildPrInitResult(array $prJson, array $files): array
    {
        return [
            'files' => $files,
            'totalAdditions' => (int) ($prJson['additions'] ?? 0),
            'totalDeletions' => (int) ($prJson['deletions'] ?? 0),
            'repoName' => basename($this->prRepo),
            'prTitle' => $prJson['title'],
        ];
    }

    private function extractPrComments(array $prJson): array
    {
        $entries = [];

        foreach ($prJson['comments'] ?? [] as $c) {
            $body = trim($c['body'] ?? '');
            if ($body === '') {
                continue;
            }
            $entries[] = [
                'type' => 'comment',
                'author' => $c['author']['login'] ?? 'unknown',
                'body' => $body,
                'createdAt' => $c['createdAt'] ?? '',
                'url' => $c['url'] ?? '',
                'state' => null,
                'path' => null,
                'line' => null,
            ];
        }

        foreach ($prJson['reviews'] ?? [] as $r) {
            $body = trim($r['body'] ?? '');
            $state = $r['state'] ?? '';
            if ($body === '' && ! in_array($state, ['APPROVED', 'CHANGES_REQUESTED', 'DISMISSED'], true)) {
                continue;
            }
            $entries[] = [
                'type' => 'review',
                'author' => $r['author']['login'] ?? 'unknown',
                'body' => $body,
                'createdAt' => $r['submittedAt'] ?? $r['createdAt'] ?? '',
                'url' => $r['url'] ?? '',
                'state' => $state,
                'path' => null,
                'line' => null,
            ];
        }

        foreach ($prJson['reviewComments'] ?? [] as $c) {
            if (! empty($c['in_reply_to_id'])) {
                continue;
            }
            $body = trim($c['body'] ?? '');
            if ($body === '') {
                continue;
            }
            $entries[] = [
                'type' => 'inline',
                'author' => $c['user']['login'] ?? 'unknown',
                'body' => $body,
                'createdAt' => $c['created_at'] ?? '',
                'url' => $c['html_url'] ?? '',
                'state' => null,
                'path' => $c['path'] ?? null,
                'line' => $c['line'] ?? $c['original_line'] ?? null,
            ];
        }

        usort($entries, fn ($a, $b) => strcmp($a['createdAt'], $b['createdAt']));

        return $entries;
    }

    private function extractInlineComments(array $prJson): array
    {
        $byPath = [];

        foreach ($prJson['reviewComments'] ?? [] as $c) {
            $path = $c['path'] ?? '';
            if ($path === '') {
                continue;
            }

            $line = $c['line'] ?? $c['original_line'] ?? null;

            $comment = [
                'author' => $c['user']['login'] ?? 'unknown',
                'body' => trim($c['body'] ?? ''),
                'createdAt' => $c['created_at'] ?? '',
                'url' => $c['html_url'] ?? '',
            ];

            if (! isset($byPath[$path])) {
                $byPath[$path] = [];
            }

            // Group comments that share the same line into one thread entry.
            $threadIdx = null;
            foreach ($byPath[$path] as $i => $thread) {
                if ($thread['line'] === $line) {
                    $threadIdx = $i;
                    break;
                }
            }

            if ($threadIdx !== null) {
                $byPath[$path][$threadIdx]['comments'][] = $comment;
            } else {
                $byPath[$path][] = [
                    'line' => $line,
                    'side' => $c['side'] ?? 'RIGHT',
                    'isResolved' => false,
                    'isOutdated' => false,
                    'comments' => [$comment],
                ];
            }
        }

        return $byPath;
    }

    private function fetchPrReviewComments(string $prNumber): array
    {
        $proc = proc_open(
            ['gh', 'api', '--paginate', '--slurp', "repos/{$this->prRepo}/pulls/{$prNumber}/comments"],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $stdout = $proc ? (string) stream_get_contents($pipes[1]) : '';
        if ($proc) {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }

        $pages = json_decode($stdout, true);

        return array_merge(...($pages ?: [[]]));
    }

    private function resolveGitObjectsCache(array $changedPaths, bool $full = false): void
    {
        if (empty($this->headCommit)) {
            return;
        }

        // Full mode uses a separate cache directory since it prefetches all blobs.
        $cacheKey = $full ? $this->headCommit.'-full' : $this->headCommit;
        $persistentDir = storage_path('app/git-objects/'.substr($this->headCommit, 0, 2).'/'.$cacheKey);
        $alreadyCached = is_dir($persistentDir)
            && trim(shell_exec("git -C {$persistentDir} cat-file -t {$this->headCommit} 2>/dev/null") ?? '') === 'commit';

        if ($alreadyCached) {
            $this->repoDir = $persistentDir;
            $this->progress('line', '  Using cached git objects.');
        } else {
            $this->progress('info', 'Fetching git objects (shallow)...');
            if (! is_dir($persistentDir)) {
                mkdir($persistentDir, 0755, true);
            }
            shell_exec("git init --bare {$persistentDir} 2>&1");
            shell_exec("git -C {$persistentDir} remote add origin https://github.com/{$this->prRepo}.git 2>&1");
            $this->fetchGitObjectsForPr($persistentDir, $changedPaths, $full);
        }

        if ($this->repoDir !== null) {
            $this->projectType = (new ProjectTypeDetector)->fromGit($this->repoDir, $this->headCommit);
            $this->applyProjectTypeGroupResolver();
        }
    }

    private function fetchGitObjectsForPr(string $persistentDir, array $changedPaths, bool $full = false): void
    {
        $phpPaths = array_values(array_filter($changedPaths, fn ($p) => str_ends_with($p, '.php')));

        $this->githubCallCount++;
        shell_exec("git -C {$persistentDir} fetch --depth 1 --filter=blob:none origin {$this->headCommit} 2>&1");

        if (! $full && ! empty($this->baseCommit)) {
            $this->githubCallCount++;
            shell_exec("git -C {$persistentDir} fetch --depth 1 --filter=blob:none origin {$this->baseCommit} 2>&1");
        }

        $verify = trim(shell_exec("git -C {$persistentDir} cat-file -t {$this->headCommit} 2>/dev/null") ?? '');
        if ($verify === 'commit') {
            $this->repoDir = $persistentDir;

            if ($full) {
                // Prefetch all PHP blobs for full-repo analysis.
                $allPaths = $this->listAllFilesAtCommit($persistentDir, $this->headCommit);
                $allPhpPaths = array_values(array_filter($allPaths, fn ($p) => str_ends_with($p, '.php')));
                foreach (array_chunk($allPhpPaths, 50) as $chunk) {
                    $this->prefetchBlobs($persistentDir, $this->headCommit, $chunk);
                }
            } else {
                $this->prefetchBlobs($persistentDir, $this->headCommit, $changedPaths);
                if (! empty($this->baseCommit) && ! empty($phpPaths)) {
                    $this->prefetchBlobs($persistentDir, $this->baseCommit, $phpPaths);
                }
            }

            $this->progress('line', '  Cached git objects locally.');
        } else {
            $this->progress('warn', '  Could not fetch git objects; file-level analysis may be limited.');
            shell_exec('rm -rf '.escapeshellarg($persistentDir));
        }
    }

    /**
     * List all files present at the given commit in a bare git clone.
     *
     * @return list<string>
     */
    private function listAllFilesAtCommit(string $repoDir, string $commit): array
    {
        $output = trim(shell_exec("git -C {$repoDir} ls-tree --name-only -r ".escapeshellarg($commit).' 2>/dev/null') ?? '');

        return $output !== '' ? array_values(array_filter(explode("\n", $output))) : [];
    }

    // ── Git helpers ──────────────────────────────────────────────────────────

    /**
     * Read file contents from the bare git clone via cat-file --batch, in chunks.
     *
     * @param  list<string>  $paths
     * @return array<string, string|null>
     */
    private function readFileContentsFromGit(array $paths): array
    {
        if (empty($paths)) {
            return array_fill_keys($paths, null);
        }

        $result = [];
        foreach (array_chunk($paths, 30) as $chunk) {
            $stdin = implode("\n", array_map(fn ($p) => "{$this->headCommit}:{$p}", $chunk))."\n";
            $batchOutput = Process::input($stdin)->timeout(300)->run("git -C {$this->repoDir} cat-file --batch")->output();
            $result = array_merge($result, $this->parseCatFileBatchOutput($batchOutput, $chunk));
        }

        return $result;
    }

    /**
     * Parse the binary output of `git cat-file --batch` into a path → content map.
     *
     * @param  list<string>  $paths
     * @return array<string, string|null>
     */
    private function parseCatFileBatchOutput(string $batchOutput, array $paths): array
    {
        $contents = [];
        $pos = 0;
        $len = strlen($batchOutput);

        foreach ($paths as $path) {
            if ($pos >= $len) {
                $contents[$path] = null;

                continue;
            }

            $nl = strpos($batchOutput, "\n", $pos);
            if ($nl === false) {
                $contents[$path] = null;
                break;
            }

            $header = substr($batchOutput, $pos, $nl - $pos);
            $pos = $nl + 1;

            if (str_ends_with($header, ' missing')) {
                $contents[$path] = null;

                continue;
            }

            $size = (int) (explode(' ', $header)[2] ?? 0);
            $content = $size > 0 ? substr($batchOutput, $pos, $size) : '';
            $pos += $size + 1;

            $contents[$path] = $content !== '' ? $content : null;
        }

        return $contents;
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
        $this->githubCallCount++;
        shell_exec("git -C {$repoDir} fetch origin {$shaArgs} 2>/dev/null");
    }

    private function formatRateLimitSuffix(): string
    {
        $json = json_decode(trim(shell_exec('gh api rate_limit 2>/dev/null') ?? ''), true);
        $core = $json['resources']['core'] ?? null;
        if (! $core) {
            return '';
        }

        $remaining = (int) $core['remaining'];
        $limit = (int) $core['limit'];
        $resetIn = max(0, (int) $core['reset'] - time());
        $resetMin = (int) ceil($resetIn / 60);
        $resetLabel = $resetMin > 0 ? "resets in {$resetMin}m" : 'resets soon';

        return " | rate limit: {$remaining}/{$limit} remaining ({$resetLabel})";
    }

    // ── Local mode ───────────────────────────────────────────────────────────

    /**
     * Initialize state for a two-commit range diff (e.g. git diff abc..def).
     *
     * @return array{files: list<array{path: string, additions: int, deletions: int}>, totalAdditions: int, totalDeletions: int, repoName: string, prTitle: string, prLinkUrl: string}
     */
    private function initTwoCommitMode(string $repoPath, string $fromCommit, ?string $toCommit, ?string $title): array
    {
        $this->repoPath = rtrim(realpath($repoPath) ?: $repoPath, '/');
        $gitDir = trim(shell_exec('git -C '.escapeshellarg($this->repoPath).' rev-parse --git-dir 2>/dev/null') ?? '');
        if ($gitDir === '') {
            throw new RuntimeException("Not a git repository: {$this->repoPath}");
        }

        $resolvedFrom = trim(shell_exec('git -C '.escapeshellarg($this->repoPath).' rev-parse '.escapeshellarg($fromCommit).' 2>/dev/null') ?? '');
        if (empty($resolvedFrom)) {
            throw new RuntimeException("Could not resolve commit: {$fromCommit}");
        }

        $resolvedTo = $this->resolveToCommit($toCommit);

        $this->baseCommit = $resolvedFrom;
        $this->headCommit = $resolvedTo;
        $this->branchName = trim(shell_exec('git -C '.escapeshellarg($this->repoPath).' rev-parse --abbrev-ref HEAD 2>/dev/null') ?? 'HEAD');
        $this->projectType = (new ProjectTypeDetector)->fromFilesystem($this->repoPath);
        $this->applyProjectTypeGroupResolver();
        $this->readContentsFromCommit = true;

        $repoName = basename($this->repoPath);
        $shortFrom = substr($resolvedFrom, 0, 7);
        $shortTo = substr($resolvedTo, 0, 7);
        $prTitle = $title ?? "{$shortFrom}..{$shortTo}";

        $this->progress('info', "Analyzing {$repoName}: {$prTitle}...");
        $this->progress('line', "  From: {$shortFrom}  To: {$shortTo}");

        $rangeSpec = escapeshellarg("{$resolvedFrom}..{$resolvedTo}");

        return $this->parseTwoCommitDiff($rangeSpec, $repoName, $prTitle, $shortFrom, $shortTo);
    }

    private function resolveToCommit(?string $toCommit): string
    {
        if ($toCommit !== null) {
            $resolved = trim(shell_exec('git -C '.escapeshellarg($this->repoPath).' rev-parse '.escapeshellarg($toCommit).' 2>/dev/null') ?? '');
            if (empty($resolved)) {
                throw new RuntimeException("Could not resolve commit: {$toCommit}");
            }

            return $resolved;
        }

        return trim(shell_exec('git -C '.escapeshellarg($this->repoPath).' rev-parse HEAD 2>/dev/null') ?? '');
    }

    /** @return array{files: list<array{path: string, additions: int, deletions: int}>, totalAdditions: int, totalDeletions: int, repoName: string, prTitle: string, prLinkUrl: string} */
    private function parseTwoCommitDiff(string $rangeSpec, string $repoName, string $prTitle, string $shortFrom, string $shortTo): array
    {
        $this->diff = shell_exec('git -C '.escapeshellarg($this->repoPath)." diff {$rangeSpec} 2>/dev/null") ?? '';

        if (! str_contains($this->diff, 'diff --git')) {
            $this->progress('warn', "No changes found between {$shortFrom} and {$shortTo}.");

            return ['files' => [], 'totalAdditions' => 0, 'totalDeletions' => 0, 'repoName' => $repoName, 'prTitle' => $prTitle, 'prLinkUrl' => ''];
        }

        $numstat = trim(shell_exec('git -C '.escapeshellarg($this->repoPath)." diff --numstat {$rangeSpec} 2>/dev/null") ?? '');
        [$files, $totalAdditions, $totalDeletions] = $this->parseNumstatIntoFiles($numstat);

        if (empty($files)) {
            throw new RuntimeException('No files found in diff output.');
        }

        return compact('files', 'totalAdditions', 'totalDeletions', 'repoName', 'prTitle') + ['prLinkUrl' => ''];
    }

    /**
     * Read file contents from a specific local git commit using cat-file --batch.
     *
     * @param  list<string>  $paths
     * @return array<string, ?string>
     */
    private function readFileContentsFromLocalCommit(array $paths): array
    {
        if (empty($paths)) {
            return [];
        }

        $result = [];
        foreach (array_chunk($paths, 30) as $chunk) {
            $stdin = implode("\n", array_map(fn ($p) => "{$this->headCommit}:{$p}", $chunk))."\n";
            $batchOutput = Process::input($stdin)->timeout(300)->run('git -C '.escapeshellarg($this->repoPath).' cat-file --batch')->output();
            $result = array_merge($result, $this->parseCatFileBatchOutput($batchOutput, $chunk));
        }

        return $result;
    }

    /**
     * Initialize state from a local git repository.
     *
     * @return array{files: list<array{path: string, additions: int, deletions: int}>, totalAdditions: int, totalDeletions: int, repoName: string, prTitle: string, prLinkUrl: string}
     */
    private function initLocalMode(string $repoPath, string $baseBranch, ?string $title, bool $full = false): array
    {
        $this->repoPath = rtrim(realpath($repoPath) ?: $repoPath, '/');

        // Normalize to the actual git root so that file-path resolution is correct
        // even when the command is invoked from a subdirectory of the repository.
        $gitRoot = trim(shell_exec("git -C {$this->repoPath} rev-parse --show-toplevel 2>/dev/null") ?? '');
        if ($gitRoot === '') {
            throw new RuntimeException("Not a git repository: {$this->repoPath}");
        }
        $this->repoPath = rtrim($gitRoot, '/');

        $this->headCommit = trim(shell_exec("git -C {$this->repoPath} rev-parse HEAD 2>/dev/null") ?? '');
        $this->branchName = trim(shell_exec("git -C {$this->repoPath} rev-parse --abbrev-ref HEAD 2>/dev/null") ?? 'HEAD');

        if (empty($this->headCommit)) {
            throw new RuntimeException('Could not resolve HEAD commit.');
        }

        $remoteUrl = trim(shell_exec("git -C {$this->repoPath} remote get-url origin 2>/dev/null") ?? '');
        $repoName = $this->resolveRepoName($remoteUrl);
        $this->projectType = (new ProjectTypeDetector)->fromFilesystem($this->repoPath);
        $this->applyProjectTypeGroupResolver();

        if ($full) {
            return $this->initAllFilesMode($repoName, $title);
        }

        return $this->initDiffMode($baseBranch, $repoName, $title);
    }

    private function resolveRepoName(string $remoteUrl): string
    {
        if (preg_match('#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?$#', $remoteUrl, $rm)) {
            return $rm[1];
        }

        return basename($this->repoPath);
    }

    private function applyProjectTypeGroupResolver(): void
    {
        if (! $this->groupResolverIsDefault) {
            return;
        }

        $patterns = config('laravel-code-analytics.file_group_patterns.'.$this->projectType->value);

        if (is_array($patterns) && ! empty($patterns)) {
            $this->groupResolver = new ArrayFileGroupResolver($patterns);
        }
    }

    private function initAllFilesMode(string $repoName, ?string $title): array
    {
        $prTitle = $title ?? "{$this->branchName} (all files)";
        $this->diff = '';

        $this->progress('info', "Analyzing {$repoName}: {$prTitle}...");
        $this->progress('line', '  HEAD: '.substr($this->headCommit, 0, 7));

        $lsOutput = trim(shell_exec("git -C {$this->repoPath} ls-files 2>/dev/null") ?? '');
        if (empty($lsOutput)) {
            throw new RuntimeException("No tracked files found in: {$this->repoPath}");
        }

        $files = array_map(
            fn ($path) => ['path' => $path, 'additions' => 0, 'deletions' => 0],
            array_filter(explode("\n", $lsOutput))
        );

        return compact('files', 'repoName', 'prTitle') + ['totalAdditions' => 0, 'totalDeletions' => 0, 'prLinkUrl' => ''];
    }

    private function initDiffMode(string $baseBranch, string $repoName, ?string $title): array
    {
        $this->baseCommit = trim(shell_exec("git -C {$this->repoPath} rev-parse {$baseBranch} 2>/dev/null") ?? '');

        if (empty($this->baseCommit)) {
            throw new RuntimeException("Could not resolve base: {$baseBranch}");
        }

        $isHeadBase = $this->baseCommit === $this->headCommit;
        $hasUncommitted = trim(shell_exec("git -C {$this->repoPath} status --porcelain 2>/dev/null") ?? '') !== '';
        $prTitle = $this->logAndResolveDiffTitle($isHeadBase, $hasUncommitted, $baseBranch, $repoName, $title);

        $this->diff = shell_exec("git -C {$this->repoPath} diff {$baseBranch} 2>/dev/null") ?? '';

        if (! str_contains($this->diff, 'diff --git')) {
            if ($hasUncommitted) {
                // The uncommitted changes cancel out the branch commits vs base — fall back to
                // analyzing just the uncommitted changes (working tree vs HEAD).
                $this->progress('warn', "No net changes between working tree and {$baseBranch} (uncommitted changes cancel branch commits). Showing uncommitted changes only.");

                return $this->initHeadDiffMode($repoName, $title);
            }

            $this->progress('warn', $isHeadBase ? 'No uncommitted changes found.' : "No changes found between working tree and {$baseBranch}.");

            return ['files' => [], 'totalAdditions' => 0, 'totalDeletions' => 0, 'repoName' => $repoName, 'prTitle' => $prTitle, 'prLinkUrl' => ''];
        }

        if ($hasUncommitted) {
            $this->progress('line', '  Including staged and unstaged working tree changes.');
            if (! $isHeadBase) {
                $this->progress('line', '  Tip: use --base=HEAD to analyze only uncommitted changes.');
            }
        }

        $numstat = trim(shell_exec("git -C {$this->repoPath} diff --numstat {$baseBranch} 2>/dev/null") ?? '');
        [$files, $totalAdditions, $totalDeletions] = $this->parseNumstatIntoFiles($numstat);

        if (empty($files)) {
            throw new RuntimeException('No files found in diff output.');
        }

        return compact('files', 'totalAdditions', 'totalDeletions', 'repoName', 'prTitle') + ['prLinkUrl' => ''];
    }

    private function initHeadDiffMode(string $repoName, ?string $title): array
    {
        $this->baseCommit = $this->headCommit;
        $prTitle = $title ?? "uncommitted changes on {$this->branchName}";
        $this->progress('line', '  HEAD: '.substr($this->headCommit, 0, 7).' (uncommitted only)');

        $this->diff = shell_exec("git -C {$this->repoPath} diff HEAD 2>/dev/null") ?? '';

        if (! str_contains($this->diff, 'diff --git')) {
            $this->progress('warn', 'No uncommitted changes found.');

            return ['files' => [], 'totalAdditions' => 0, 'totalDeletions' => 0, 'repoName' => $repoName, 'prTitle' => $prTitle, 'prLinkUrl' => ''];
        }

        $numstat = trim(shell_exec("git -C {$this->repoPath} diff --numstat HEAD 2>/dev/null") ?? '');
        [$files, $totalAdditions, $totalDeletions] = $this->parseNumstatIntoFiles($numstat);

        if (empty($files)) {
            throw new RuntimeException('No files found in diff output.');
        }

        return compact('files', 'totalAdditions', 'totalDeletions', 'repoName', 'prTitle') + ['prLinkUrl' => ''];
    }

    private function logAndResolveDiffTitle(bool $isHeadBase, bool $hasUncommitted, string $baseBranch, string $repoName, ?string $title): string
    {
        if ($isHeadBase) {
            $prTitle = $title ?? "uncommitted changes on {$this->branchName}";
            $this->progress('info', "Analyzing {$repoName}: {$prTitle}...");
            $this->progress('line', '  HEAD: '.substr($this->headCommit, 0, 7).' (uncommitted only)');

            return $prTitle;
        }

        $uncommittedSuffix = $hasUncommitted ? ' + uncommitted' : '';
        $prTitle = $title ?? "{$this->branchName} vs {$baseBranch}{$uncommittedSuffix}";
        $this->progress('info', "Analyzing {$repoName}: {$prTitle}...");
        $this->progress('line', '  HEAD: '.substr($this->headCommit, 0, 7).'  Base: '.substr($this->baseCommit, 0, 7));

        return $prTitle;
    }

    private function parseNumstatIntoFiles(string $numstat): array
    {
        $files = [];
        $totalAdditions = 0;
        $totalDeletions = 0;

        foreach (explode("\n", $numstat) as $line) {
            if (empty($line)) {
                continue;
            }

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

        return [$files, $totalAdditions, $totalDeletions];
    }

    // ── Node processing ──────────────────────────────────────────────────────

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

    // ── Endpoint analysis ────────────────────────────────────────────────────

    /**
     * Scan route files, build a controller-path → RouteDefinition[] index, then
     * walk the dependency graph in reverse to find which endpoints this PR touches.
     *
     * @param  array<string, string>  $fqcnToFilePath
     * @return AffectedEndpoint[]
     */
    private function findAffectedEndpoints(array $nodes, array $edges, array $fqcnToFilePath): array
    {
        $routePaths = $this->listRouteFiles();
        if (empty($routePaths)) {
            return [];
        }

        $routeContents = $this->readBulkFileContents($routePaths);
        $filePathToFqcn = array_flip($fqcnToFilePath);

        $routeIndex = (new RouteIndexBuilder)->build(
            routeFileContents: $routeContents,
            fqcnToPath: function (string $fqcn) use ($fqcnToFilePath): ?string {
                return $fqcnToFilePath[$fqcn] ?? $this->psr4Resolver()->pathForFqcn($fqcn);
            },
        );

        if (empty($routeIndex)) {
            return [];
        }

        $this->processControllerDependents(array_keys($routeIndex));

        $nodeIdToPath = array_flip($this->graph->pathToNode);

        $diffNodes = array_values(array_filter($nodes, fn ($n) => empty($n['isConnected'])));

        return (new AffectedEndpointResolver)->resolve(
            routeIndex: $routeIndex,
            edges: $this->graph->edges,
            nodeIdToPath: $nodeIdToPath,
            diffNodes: $diffNodes,
        );
    }

    // ── Scheduled job analysis ───────────────────────────────────────────────

    /** @return AffectedScheduledJob[] */
    private function findAffectedScheduledJobs(array $nodes, array $edges, array $fqcnToFilePath): array
    {
        $consolePaths = $this->listConsoleFiles();
        if (empty($consolePaths)) {
            return [];
        }

        $consoleContents = $this->readBulkFileContents($consolePaths);

        $jobIndex = (new ScheduledJobIndexBuilder)->build(
            consoleFileContents: $consoleContents,
            fqcnToPath: function (string $fqcn) use ($fqcnToFilePath): ?string {
                return $fqcnToFilePath[$fqcn] ?? $this->psr4Resolver()->pathForFqcn($fqcn);
            },
        );

        if (empty($jobIndex)) {
            return [];
        }

        $nodeIdToPath = [];
        foreach ($nodes as $node) {
            $nodeIdToPath[$node['id']] = $node['path'];
        }

        $diffNodes = array_values(array_filter($nodes, fn ($n) => empty($n['isConnected'])));

        return (new AffectedScheduledJobResolver)->resolve(
            jobIndex: $jobIndex,
            edges: $edges,
            nodeIdToPath: $nodeIdToPath,
            diffNodes: $diffNodes,
        );
    }

    /** @return list<string> */
    private function listConsoleFiles(): array
    {
        $consoleFileCandidates = ['routes/console.php', 'app/Console/Kernel.php', 'bootstrap/app.php'];

        if ($this->repoDir !== null) {
            $output = trim(shell_exec("git -C {$this->repoDir} ls-tree -r {$this->headCommit} --name-only 2>/dev/null") ?? '');
            if (empty($output)) {
                return [];
            }

            return array_values(array_filter(
                explode("\n", $output),
                fn ($p) => in_array($p, $consoleFileCandidates, true),
            ));
        }

        if ($this->repoPath !== '') {
            $quoted = implode(' ', array_map(fn ($f) => "'$f'", $consoleFileCandidates));
            $output = trim(shell_exec("git -C {$this->repoPath} ls-files $quoted 2>/dev/null") ?? '');
            if (empty($output)) {
                return [];
            }

            return array_values(array_filter(explode("\n", $output)));
        }

        return [];
    }

    /**
     * For each controller in the route index that is not already in the diff, scan its
     * source for references to diff nodes. When found, register the controller as a
     * connected node and add the dependency edges so the reverse BFS in
     * AffectedEndpointResolver can reach it from a changed Request / Service / etc.
     *
     * @param  list<string>  $controllerPaths
     */
    private function processControllerDependents(array $controllerPaths): void
    {
        $unprocessed = array_values(array_filter(
            $controllerPaths,
            fn ($path) => $this->graph->nodeIdForPath($path) === null,
        ));

        if (empty($unprocessed)) {
            return;
        }

        $contents = $this->readBulkFileContents($unprocessed);

        foreach ($unprocessed as $path) {
            $content = $contents[$path] ?? null;
            if (empty($content)) {
                continue;
            }

            $references = $this->extractReferences($content);

            $touchesDiffNode = false;
            foreach (array_keys($references) as $ref) {
                $ref = ltrim($ref, '\\');
                if (isset($this->fqcnIndex->diffNodes[$ref])) {
                    $touchesDiffNode = true;
                    break;
                }
                $shortName = basename(str_replace('\\', '/', $ref));
                foreach (array_keys($this->fqcnIndex->diffNodes) as $dfqcn) {
                    if (basename(str_replace('\\', '/', $dfqcn)) === $shortName) {
                        $touchesDiffNode = true;
                        break 2;
                    }
                }
            }

            if (! $touchesDiffNode) {
                continue;
            }

            $fqcn = $this->psr4Resolver()->fqcnForPath($path);
            if ($fqcn === null) {
                continue;
            }

            $controllerNodeId = $this->ensureConnectedNode($fqcn);
            if ($controllerNodeId === null) {
                continue;
            }

            $this->matchReferences($references, $controllerNodeId);
        }
    }

    /** @return list<string> */
    private function listRouteFiles(): array
    {
        if ($this->repoDir !== null) {
            $output = trim(shell_exec("git -C {$this->repoDir} ls-tree -r {$this->headCommit} --name-only 2>/dev/null") ?? '');
        } elseif ($this->repoPath !== '') {
            $output = trim(shell_exec("git -C {$this->repoPath} ls-files 'routes/*.php' 2>/dev/null") ?? '');
        } else {
            return [];
        }

        if (empty($output)) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", $output),
            fn ($p) => str_starts_with($p, 'routes/') && str_ends_with($p, '.php'),
        ));
    }

    // ── Old source fetching ──────────────────────────────────────────────────

    /**
     * Fetch base-commit file contents for PHP files that need AST comparison.
     *
     * @param  array<int, array>  $phpFiles
     * @return array<string, string>
     */
    private function fetchOldSources(array $phpFiles, array $fileDiffMap): array
    {
        $needsOldSource = $this->findPathsNeedingOldSource($phpFiles, $fileDiffMap);

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

    private function findPathsNeedingOldSource(array $phpFiles, array $fileDiffMap): array
    {
        $needsOldSource = [];
        foreach ($phpFiles as $node) {
            $fileDiff = $fileDiffMap[$node['path']] ?? null;
            if ($fileDiff && $fileDiff->status !== FileStatus::ADDED) {
                $needsOldSource[] = $node['path'];
            }
        }

        return $needsOldSource;
    }

    // ── PHP metrics ──────────────────────────────────────────────────────────

    /**
     * Run all four external metric tool invocations (PhpMetrics head/base,
     * jsmetrics head/base) concurrently. They are independent OS processes, so
     * launching them in one Process pool collapses four serial subprocess
     * startups + parses into a single wall-clock slot.
     *
     * @param  array<string, string|null>  $headContents
     * @param  array<string, string>  $oldSources
     * @param  array<int, array>  $frontendFiles
     * @return array{php: array<string, PhpMetrics>, phpBefore: array<string, PhpMetrics>, js: array<string, JsMetrics>, jsBefore: array<string, JsMetrics>}
     */
    private function runExternalMetrics(array $headContents, array $oldSources, array $frontendFiles): array
    {
        $phpRunner = new PhpMetricsRunner;
        $jsRunner = new JsMetricsRunner;

        $jsContents = $this->collectJsContents($frontendFiles, $headContents);
        $oldJsContents = $jsContents !== [] ? array_intersect_key($oldSources, $jsContents) : [];

        // Prepare each job (write temp files + build command). null = nothing to run.
        $jobs = array_filter([
            'php' => $headContents !== [] ? $phpRunner->prepare($headContents) : null,
            'phpBefore' => $oldSources !== [] ? $phpRunner->prepare($oldSources) : null,
            'js' => $jsContents !== [] ? $jsRunner->prepare($jsContents) : null,
            'jsBefore' => $oldJsContents !== [] ? $jsRunner->prepare($oldJsContents) : null,
        ]);

        if ($jobs === []) {
            return ['php' => [], 'phpBefore' => [], 'js' => [], 'jsBefore' => []];
        }

        $this->progress('info', 'Running PhpMetrics + JS complexity analysis (concurrent)...');

        $results = Process::pool(function ($pool) use ($jobs): void {
            foreach ($jobs as $key => $job) {
                $pool->as($key)->command($job['cmd']);
            }
        })->start()->wait();

        // Drain the pool, keyed by the job names registered above.
        $raw = ['php' => null, 'phpBefore' => null, 'js' => null, 'jsBefore' => null];
        foreach ($results->collect() as $name => $result) {
            $raw[$name] = ['output' => $result->output(), 'exit' => $result->exitCode()];
        }

        return [
            'php' => $raw['php'] !== null
                ? $phpRunner->collect($jobs['php'], $raw['php']['exit'])
                : [],
            'phpBefore' => $raw['phpBefore'] !== null
                ? $phpRunner->collect($jobs['phpBefore'], $raw['phpBefore']['exit'])
                : [],
            'js' => $raw['js'] !== null
                ? $jsRunner->collect($jobs['js'], $raw['js']['output'], $raw['js']['exit'])
                : [],
            'jsBefore' => $raw['jsBefore'] !== null
                ? $jsRunner->collect($jobs['jsBefore'], $raw['jsBefore']['output'], $raw['jsBefore']['exit'])
                : [],
        ];
    }

    /**
     * Build per-file PHP metric entries from already-computed PhpMetrics.
     *
     * @param  array<string, PhpMetrics>  $metricsByFqcn  Head metrics, keyed by FQCN
     * @param  array<string, PhpMetrics>  $metricsBeforeByFqcn  Base metrics, keyed by FQCN
     * @param  array<string, string|null>  $headContents
     * @param  array<string, string>  $oldSources
     * @param  array<string, string>  $fqcnToFilePath
     * @return array{hotSpots: int, metricsData: array<string, array>}
     */
    private function computePhpMetrics(array $metricsByFqcn, array $metricsBeforeByFqcn, array $headContents, array $oldSources, array $fqcnToFilePath): array
    {
        if (empty($headContents)) {
            return ['hotSpots' => 0, 'metricsData' => []];
        }

        $metricsBefore = $this->buildBeforePhpMetrics($metricsBeforeByFqcn, $oldSources);
        $hotSpots = $this->countHotSpots($metricsByFqcn);
        $metricsData = [];

        foreach ($metricsByFqcn as $fqcn => $m) {
            $path = $fqcnToFilePath[$fqcn] ?? $this->psr4Resolver()->pathForFqcn($fqcn);
            if ($path === null) {
                continue;
            }
            $entry = $this->buildPhpMetricsEntry($m, $metricsBefore[$path] ?? null);
            if (! empty($entry)) {
                $metricsData[$path] = $entry;
            }
        }

        $this->progress('line', '  Metrics computed for '.count($metricsByFqcn).' classes.');

        $t = microtime(true);
        $metricsData = $this->enrichWithMethodMetrics($metricsData, $headContents, $oldSources);
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' method metrics');

        return compact('hotSpots', 'metricsData');
    }

    /**
     * Map already-computed base metrics (keyed by FQCN) to file paths.
     *
     * @param  array<string, PhpMetrics>  $metricsBeforeByFqcn
     * @param  array<string, string>  $oldSources
     * @return array<string, PhpMetrics>
     */
    private function buildBeforePhpMetrics(array $metricsBeforeByFqcn, array $oldSources): array
    {
        if (empty($metricsBeforeByFqcn) || empty($oldSources)) {
            return [];
        }

        $oldFqcnToPath = [];
        foreach ($oldSources as $path => $content) {
            $fqcn = $this->extractFqcnFromContent($content);
            if ($fqcn !== null) {
                $oldFqcnToPath[$fqcn] = $path;
            }
        }

        $metricsBefore = [];
        foreach ($metricsBeforeByFqcn as $fqcn => $m) {
            $path = $oldFqcnToPath[$fqcn] ?? $this->psr4Resolver()->pathForFqcn($fqcn);
            if ($path !== null) {
                $metricsBefore[$path] = $m;
            }
        }

        return $metricsBefore;
    }

    private function buildPhpMetricsEntry(PhpMetrics $m, ?PhpMetrics $before): array
    {
        $entry = array_filter([
            'cc' => $m->cyclomaticComplexity,
            'mi' => $m->maintainabilityIndex !== null ? round($m->maintainabilityIndex, 1) : null,
            'bugs' => $m->bugs !== null ? round($m->bugs, 3) : null,
            'coupling' => $m->efferentCoupling,
            'lloc' => $m->logicalLinesOfCode,
            'methods' => $m->methodsCount,
        ], fn ($v) => $v !== null);

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

        return $entry;
    }

    private function enrichWithMethodMetrics(array $metricsData, array $headContents, array $oldSources): array
    {
        $calculator = new PhpMethodMetricsCalculator;
        $relevantPaths = array_flip(array_keys($metricsData));

        // Single parse pass per file yields both method- and class-level metrics.
        foreach ($calculator->calculateAll(array_intersect_key($headContents, $relevantPaths)) as $path => $metrics) {
            if (! empty($metrics['methods'])) {
                $metricsData[$path]['method_metrics'] = array_map(fn ($m) => $m->toArray(), $metrics['methods']);
                $metricsData[$path]['flog'] = round(array_sum(array_map(fn ($m) => $m->flog, $metrics['methods'])), 1);
            }
            if (! empty($metrics['classes'])) {
                $metricsData[$path]['class_metrics'] = array_map(fn ($c) => $c->toArray(), $metrics['classes']);
            }
        }

        if (! empty($oldSources)) {
            foreach ($calculator->calculateAll(array_intersect_key($oldSources, $relevantPaths)) as $path => $metrics) {
                if (! empty($metrics['methods'])) {
                    $metricsData[$path]['before_method_metrics'] = array_map(fn ($m) => $m->toArray(), $metrics['methods']);
                    $metricsData[$path]['before']['flog'] = round(array_sum(array_map(fn ($m) => $m->flog, $metrics['methods'])), 1);
                }
                if (! empty($metrics['classes'])) {
                    $metricsData[$path]['before_class_metrics'] = array_map(fn ($c) => $c->toArray(), $metrics['classes']);
                }
            }
        }

        return $metricsData;
    }

    // ── JS metrics ───────────────────────────────────────────────────────────

    /**
     * Build per-file JS metric entries from already-computed JsMetrics.
     *
     * @param  array<string, JsMetrics>  $jsMetricsByPath  Head metrics, keyed by path
     * @param  array<string, JsMetrics>  $jsMetricsBefore  Base metrics, keyed by path
     * @param  array<int, array>  $frontendFiles
     * @return array{hotSpots: int, metricsData: array<string, array>}
     */
    private function computeJsMetrics(array $jsMetricsByPath, array $jsMetricsBefore, array $frontendFiles): array
    {
        if (empty($frontendFiles) || empty($jsMetricsByPath)) {
            return ['hotSpots' => 0, 'metricsData' => []];
        }

        $this->progress('line', '  JS metrics computed for '.count($jsMetricsByPath).' files.');

        $hotSpots = $this->countJsHotSpots($jsMetricsByPath);
        $metricsData = [];

        foreach ($jsMetricsByPath as $path => $m) {
            $entry = $this->buildJsMetricsEntry($m, $jsMetricsBefore[$path] ?? null);
            if (! empty($entry)) {
                $metricsData[$path] = $entry;
            }
        }

        return compact('hotSpots', 'metricsData');
    }

    private function collectJsContents(array $frontendFiles, array $headContents): array
    {
        $jsContents = [];
        foreach ($frontendFiles as $node) {
            $content = $headContents[$node['path']] ?? null;
            if ($content !== null && $content !== '') {
                $jsContents[$node['path']] = $content;
            }
        }

        return $jsContents;
    }

    private function buildJsMetricsEntry(JsMetrics $m, ?JsMetrics $before): array
    {
        $entry = array_filter([
            'cc' => $m->cyclomaticComplexity,
            'mi' => $m->maintainabilityIndex !== null ? round($m->maintainabilityIndex, 1) : null,
            'bugs' => $m->bugs !== null ? round($m->bugs, 3) : null,
            'lloc' => $m->logicalLinesOfCode,
            'methods' => $m->functionCount,
        ], fn ($v) => $v !== null);

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

        return $entry;
    }

    // ── Hotspot detection ────────────────────────────────────────────────────

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
        return count(array_filter($metricsByFqcn, fn ($m) => $this->isPhpHotSpot($m)));
    }

    private function isPhpHotSpot(PhpMetrics $metrics): bool
    {
        return ($metrics->cyclomaticComplexity ?? 0) > 10
            || ($metrics->maintainabilityIndex ?? 100) < 85
            || ($metrics->bugs ?? 0) > 0.1
            || ($metrics->efferentCoupling ?? 0) > 15;
    }

    /**
     * @param  array<string, JsMetrics>  $metricsByPath
     */
    private function countJsHotSpots(array $metricsByPath): int
    {
        return count(array_filter($metricsByPath, fn ($m) => $this->isJsHotSpot($m)));
    }

    private function isJsHotSpot(JsMetrics $metrics): bool
    {
        return ($metrics->cyclomaticComplexity ?? 0) > 10
            || ($metrics->maintainabilityIndex ?? 100) < 85
            || ($metrics->bugs ?? 0) > 0.1;
    }

    // ── Composer audit ───────────────────────────────────────────────────────

    /**
     * When composer.json is in the diff, inject three tiers of findings:
     *   HIGH   — each security advisory from `composer audit`
     *   MEDIUM — each newly added dependency (require / require-dev)
     *   INFO   — each removed dependency
     *
     * @return array{0: array, 1: array}
     */
    private function injectComposerAuditFindings(array $nodes, array $analysisData): array
    {
        foreach ($nodes as &$node) {
            if ($node['path'] !== 'composer.json' && ! str_ends_with($node['path'], '/composer.json')) {
                continue;
            }

            $subDir = dirname($node['path']);
            $dir = $this->repoPath !== ''
                ? ($subDir === '.' ? $this->repoPath : $this->repoPath.'/'.$subDir)
                : null;

            $advisories = $dir !== null ? $this->runComposerAudit($dir) : [];
            [$addedDeps, $removedDeps] = $this->diffComposerDependencies($node['path']);

            if (empty($advisories) && empty($addedDeps) && empty($removedDeps)) {
                continue;
            }

            $analysisData[$node['path']] ??= [];

            foreach ($advisories as $advisory) {
                $analysisData[$node['path']][] = [
                    'category' => ChangeCategory::SECURITY_ADVISORY->value,
                    'severity' => Severity::HIGH->value,
                    'description' => ($advisory['packageName'] ?? '?').': '.($advisory['title'] ?? 'Security advisory'),
                ];
                $node['analysisCount'] = ($node['analysisCount'] ?? 0) + 1;
                $node['highCount'] = ($node['highCount'] ?? 0) + 1;
            }

            foreach ($addedDeps as $package => $version) {
                $analysisData[$node['path']][] = [
                    'category' => ChangeCategory::SECURITY_ADVISORY->value,
                    'severity' => Severity::MEDIUM->value,
                    'description' => "New dependency: {$package} {$version}",
                ];
                $node['analysisCount'] = ($node['analysisCount'] ?? 0) + 1;
                $node['mediumCount'] = ($node['mediumCount'] ?? 0) + 1;
            }

            foreach ($removedDeps as $package => $version) {
                $analysisData[$node['path']][] = [
                    'category' => ChangeCategory::SECURITY_ADVISORY->value,
                    'severity' => Severity::INFO->value,
                    'description' => "Dependency removed: {$package} {$version}",
                ];
                $node['analysisCount'] = ($node['analysisCount'] ?? 0) + 1;
                $node['infoCount'] = ($node['infoCount'] ?? 0) + 1;
            }

            foreach (array_reverse(Severity::cases()) as $sev) {
                if (($node[$sev->countKey()] ?? 0) > 0) {
                    $node['severity'] = $sev->value;
                    break;
                }
            }

            if (! empty($advisories)) {
                $this->progress('line', '  composer audit: '.count($advisories).' advisor'.(count($advisories) === 1 ? 'y' : 'ies').' found in '.$node['path'].'.');
            }
        }
        unset($node);

        return [$nodes, $analysisData];
    }

    /** @return list<array<string, mixed>> */
    private function runComposerAudit(string $dir): array
    {
        $result = Process::path($dir)->timeout(30)->run(['composer', 'audit', '--format=json']);
        $json = $result->output();

        if (empty($json)) {
            return [];
        }

        $data = json_decode($json, true);
        if (! is_array($data) || empty($data['advisories'])) {
            return [];
        }

        $advisories = [];
        foreach ($data['advisories'] as $entries) {
            foreach ((array) $entries as $advisory) {
                $advisories[] = $advisory;
            }
        }

        return $advisories;
    }

    /**
     * Compare the require/require-dev sections between base and head commits.
     *
     * @return array{0: array<string, string>, 1: array<string, string>} [added, removed]
     */
    private function diffComposerDependencies(string $path): array
    {
        if (empty($this->baseCommit)) {
            return [[], []];
        }

        $gitDir = $this->repoDir ?? ($this->repoPath !== '' ? $this->repoPath : null);
        if ($gitDir === null) {
            return [[], []];
        }

        $oldJson = trim(shell_exec('git -C '.escapeshellarg($gitDir).' show '.escapeshellarg("{$this->baseCommit}:{$path}").' 2>/dev/null') ?? '');

        if ($this->repoDir !== null) {
            $fetched = $this->readFileContentsFromGit([$path]);
            $newJson = $fetched[$path] ?? '';
        } elseif ($this->readContentsFromCommit) {
            $fetched = $this->readFileContentsFromLocalCommit([$path]);
            $newJson = $fetched[$path] ?? '';
        } else {
            $fullPath = $this->repoPath.'/'.$path;
            $newJson = is_file($fullPath) ? (string) file_get_contents($fullPath) : '';
        }

        $old = $this->extractComposerPackages($oldJson);
        $new = $this->extractComposerPackages($newJson);

        return [array_diff_key($new, $old), array_diff_key($old, $new)];
    }

    /** @return array<string, string> package → version constraint */
    private function extractComposerPackages(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return [];
        }

        return array_merge(
            (array) ($data['require'] ?? []),
            (array) ($data['require-dev'] ?? []),
        );
    }

    // ── Utilities ────────────────────────────────────────────────────────────

    private function progress(string $level, string $message): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($level, $message);
        }
    }

    private function elapsed(float $start): string
    {
        return $this->formatDuration(microtime(true) - $start);
    }

    private function formatDuration(float $sec): string
    {
        return $sec >= 1.0
            ? sprintf('%.2fs', $sec)
            : sprintf('%dms', (int) round($sec * 1000));
    }

    /**
     * Log a pipeline step's elapsed time and record it for the end-of-run breakdown.
     *
     * Durations accumulate per label, so a step that runs more than once (or in a
     * loop) sums into a single breakdown entry.
     */
    private function recordStep(string $label, float $start, string $suffix = ''): void
    {
        $sec = microtime(true) - $start;
        $this->stepTimings[$label] = ($this->stepTimings[$label] ?? 0.0) + $sec;
        $this->progress('timing', '  ↳ '.$this->formatDuration($sec).' '.$label.$suffix);
    }

    /**
     * Print the recorded pipeline steps sorted slowest-first, with each step's
     * share of the measured total, so the bottleneck is obvious at a glance.
     */
    private function logTimingBreakdown(): void
    {
        if ($this->stepTimings === []) {
            return;
        }

        $timings = $this->stepTimings;
        arsort($timings);
        $total = array_sum($timings);

        $this->progress('line', '  Timing breakdown (slowest first, '.$this->formatDuration($total).' measured):');
        foreach ($timings as $label => $sec) {
            $pct = $total > 0.0 ? (int) round($sec / $total * 100) : 0;
            $this->progress('line', sprintf('    %8s  %3d%%  %s', $this->formatDuration($sec), $pct, $label));
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

    /**
     * Extract class references from PHP source, classified by how they are used.
     *
     * @return array<string, string> FQCN/short-name → dependency type
     */
    private function extractReferences(string $content): array
    {
        return (new PhpDependencyExtractor)->extract($content);
    }

    /**
     * @param  array<string, string>  $references  FQCN/short-name → dependency type
     */
    private function matchReferences(array $references, string $sourceNodeId): void
    {
        foreach ($references as $ref => $type) {
            $ref = ltrim($ref, '\\');

            if (isset($this->fqcnIndex->diffNodes[$ref])) {
                $this->graph->addEdge($sourceNodeId, $this->fqcnIndex->diffNodes[$ref], $type);

                continue;
            }

            $shortName = basename(str_replace('\\', '/', $ref));
            $matched = false;
            foreach ($this->fqcnIndex->diffNodes as $fqcn => $nodeId) {
                $fqcnShort = basename(str_replace('\\', '/', $fqcn));
                if ($fqcnShort === $shortName) {
                    $this->graph->addEdge($sourceNodeId, $nodeId, $type);
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $connectedId = $this->ensureConnectedNode($ref);
                if ($connectedId !== null) {
                    $this->graph->addEdge($sourceNodeId, $connectedId, $type);
                }
            }
        }
    }

    /**
     * Find or create a connected (non-diff) node for the given FQCN.
     * Returns the node ID, or null if the FQCN cannot be resolved.
     */
    private function ensureConnectedNode(string $fqcn): ?string
    {
        if (isset($this->fqcnIndex->resolvedNodes[$fqcn])) {
            return $this->fqcnIndex->resolvedNodes[$fqcn];
        }

        $path = $this->psr4Resolver()->pathForFqcn($fqcn);
        if ($path === null) {
            return null;
        }

        $existing = $this->graph->nodeIdForPath($path);
        if ($existing !== null) {
            return $existing;
        }

        if ($this->repoDir === null && $this->repoPath !== '' && ! is_file("{$this->repoPath}/{$path}")) {
            return null;
        }

        $label = $this->generateLabel($path);
        $node = $this->connectedNodeFactory->make($path, $label);
        $this->graph->registerConnectedNode($node);
        $this->fqcnIndex->resolvedNodes[$fqcn] = $label;

        return $label;
    }

    private function matchViewReferences(string $content, string $sourceNodeId, string $sourcePath = ''): void
    {
        preg_match_all('/(?:Inertia::render|inertia)\s*\(\s*[\'"]([^\'"]+)[\'"]/m', $content, $inertiaMatches);
        foreach ($inertiaMatches[1] as $page) {
            foreach (['jsx', 'tsx', 'vue'] as $ext) {
                $path = "resources/js/Pages/{$page}.{$ext}";
                $targetId = $this->graph->nodeIdForPath($path);
                if ($targetId !== null) {
                    $this->graph->addEdge($sourceNodeId, $targetId);
                    break;
                }
            }
        }

        preg_match_all('/\bview\s*\(\s*[\'"]([^\'"]+)[\'"]/m', $content, $viewMatches);
        foreach ($viewMatches[1] as $view) {
            $viewPath = 'resources/views/'.str_replace('.', '/', $view).'.blade.php';
            $targetId = $this->graph->nodeIdForPath($viewPath);
            if ($targetId !== null) {
                $this->graph->addEdge($sourceNodeId, $targetId);
            }
        }

        foreach ((new ViewFileDependencyRule)->resolve($content, $sourcePath) as $viewPath) {
            $targetId = $this->graph->nodeIdForPath($viewPath);
            if ($targetId !== null) {
                $this->graph->addEdge($sourceNodeId, $targetId);
            }
        }

        if (str_ends_with($sourcePath, '.blade.php')) {
            foreach ((new BladeDependencyRule)->resolve($content) as $viewPath) {
                $targetId = $this->graph->nodeIdForPath($viewPath);
                if ($targetId !== null) {
                    $this->graph->addEdge($sourceNodeId, $targetId);
                } else {
                    $targetNodeId = $this->ensureConnectedBladeNode($viewPath);
                    if ($targetNodeId !== null) {
                        $this->graph->addEdge($sourceNodeId, $targetNodeId);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $commandSignatureIndex  command-name → relative-file-path
     */
    private function matchScheduleReferences(string $content, string $sourceNodeId, array $commandSignatureIndex): void
    {
        if (empty($commandSignatureIndex)) {
            return;
        }

        $pattern = '/(?:Schedule::command|\$schedule->command)\s*\(\s*[\'"]([^\'"]+)[\'"]/m';
        if (! preg_match_all($pattern, $content, $matches)) {
            return;
        }

        foreach ($matches[1] as $signature) {
            $commandName = explode(' ', trim($signature))[0];
            $commandPath = $commandSignatureIndex[$commandName] ?? null;
            if ($commandPath === null) {
                continue;
            }

            if (isset($this->graph->pathToNode[$commandPath])) {
                $this->graph->addEdge($sourceNodeId, $this->graph->pathToNode[$commandPath], PhpDependencyExtractor::STATIC_CALL);

                continue;
            }

            $targetId = $this->ensureConnectedBladeNode($commandPath);
            if ($targetId !== null) {
                $this->graph->addEdge($sourceNodeId, $targetId, PhpDependencyExtractor::STATIC_CALL);
            }
        }
    }

    private function matchComponentReferences(string $content, string $sourceNodeId, array $componentNameToNode): void
    {
        preg_match_all('/<([A-Z][A-Za-z0-9]+)(?:[\s\/>.])/m', $content, $jsxMatches);
        $components = array_unique($jsxMatches[1]);

        foreach ($components as $component) {
            if (isset($componentNameToNode[$component]) && $componentNameToNode[$component] !== $sourceNodeId) {
                $this->graph->addEdge($sourceNodeId, $componentNameToNode[$component]);
            }
        }
    }

    /**
     * Detect circular dependencies using Tarjan's strongly connected components algorithm.
     * Returns a map of nodeId → cycleId (1-based) for every node that belongs to a cycle.
     * Nodes not in any cycle are absent from the returned array.
     *
     * @param  array<int, array{id: string}>  $nodes
     * @param  list<array{0: string, 1: string, 2: string}>  $edges
     * @return array<string, int>
     */
    /** @return array<string, int> nodeId → cycleId (1-based) */
    private function detectCycles(array $nodes, array $edges): array
    {
        $adj = $this->buildAdjacencyList($nodes, $edges);

        return $this->runTarjanScc($nodes, $adj);
    }

    private function buildAdjacencyList(array $nodes, array $edges): array
    {
        $adj = [];
        foreach ($nodes as $n) {
            $adj[$n['id']] = [];
        }
        foreach ($edges as [$src, $tgt]) {
            $adj[$src][] = $tgt;
        }

        return $adj;
    }

    /** @return array<string, int> */
    private function runTarjanScc(array $nodes, array $adj): array
    {
        $state = ['index' => 0, 'stack' => [], 'onStack' => [], 'nodeIndex' => [], 'lowlink' => [], 'cycles' => [], 'cycleCounter' => 0];

        foreach ($nodes as $n) {
            if (! isset($state['nodeIndex'][$n['id']])) {
                $this->tarjanVisit($n['id'], $adj, $state);
            }
        }

        return $state['cycles'];
    }

    private function tarjanVisit(string $v, array $adj, array &$state): void
    {
        $state['nodeIndex'][$v] = $state['index'];
        $state['lowlink'][$v] = $state['index'];
        $state['index']++;
        $state['stack'][] = $v;
        $state['onStack'][$v] = true;

        foreach ($adj[$v] ?? [] as $w) {
            if (! isset($state['nodeIndex'][$w])) {
                $this->tarjanVisit($w, $adj, $state);
                $state['lowlink'][$v] = min($state['lowlink'][$v], $state['lowlink'][$w]);
            } elseif ($state['onStack'][$w] ?? false) {
                $state['lowlink'][$v] = min($state['lowlink'][$v], $state['nodeIndex'][$w]);
            }
        }

        if ($state['lowlink'][$v] === $state['nodeIndex'][$v]) {
            $this->extractScc($v, $state);
        }
    }

    private function extractScc(string $v, array &$state): void
    {
        $scc = [];
        do {
            $w = array_pop($state['stack']);
            $state['onStack'][$w] = false;
            $scc[] = $w;
        } while ($w !== $v);

        if (count($scc) > 1) {
            $state['cycleCounter']++;
            foreach ($scc as $nodeId) {
                $state['cycles'][$nodeId] = $state['cycleCounter'];
            }
        }
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
