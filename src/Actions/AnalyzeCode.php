<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Closure;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Vistik\LaravelCodeAnalytics\Actions\DependencyRules\BladeDependencyRule;
use Vistik\LaravelCodeAnalytics\Actions\DependencyRules\ViewFileDependencyRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\ArrayFileGroupResolver;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\ChangeClassifier;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts\FileGroupResolver;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\DiffParser;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\LaravelMigrationModelCorrelator;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\PatternBasedGroupResolver;
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

    /** Bare-clone path when analyzing a remote GitHub PR (null in local mode) */
    private ?string $repoDir = null;

    /** Whether file contents should be read from a specific git commit rather than the filesystem */
    private bool $readContentsFromCommit = false;

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

    /** @var array<string, array> Non-diff dependency nodes discovered during dependency extraction */
    private array $connectedNodes = [];

    /** @var array<string, string> FQCN → connected node ID */
    private array $connectedNodeFqcn = [];

    /** @var array<string, string>|null PSR-4 namespace prefix → relative directory (loaded from composer.json) */
    private ?array $psr4Map = null;

    /** Count of outbound network calls made to GitHub during this analysis */
    private int $githubCallCount = 0;

    private ?Closure $onProgress;

    private float $analyzeStart = 0.0;

    private bool $groupResolverIsDefault;

    public function __construct(
        private FileGroupResolver $groupResolver = new PatternBasedGroupResolver,
        ?RiskScoring $riskScorer = null,
        ?FileSignalScoring $fileSignalScorer = null,
    ) {
        $this->groupResolverIsDefault = $this->groupResolver instanceof PatternBasedGroupResolver;
        $this->riskScorer = $riskScorer ?? new CalculateRiskScore;
        $this->fileSignalScorer = $fileSignalScorer ?? new CalculateFileSignal;
    }

    /**
     * @return array{files: array<string, string>, risk: RiskScore, content?: string}
     */
    public function execute(
        string $repoPath = '',
        ?string $outputPath = null,
        string $baseBranch = 'main',
        ?string $prUrl = null,
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
    ): array {
        $this->onProgress = $onProgress;
        $this->analyzeStart = microtime(true);
        $this->resetState();

        $t = microtime(true);
        if ($prUrl !== null) {
            $init = $this->initFromPrUrl($prUrl);
            $init['prLinkUrl'] = $prUrl;
        } elseif ($fromCommit !== null) {
            // ── Two-commit range mode ────────────────────────────────────────
            $init = $this->initTwoCommitMode($repoPath, $fromCommit, $toCommit, $title);
        } else {
            $init = $this->initLocalMode($repoPath, $baseBranch, $title, $full);
        }
        $this->progress('timing', '  ↳ init: '.$this->elapsed($t));

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
        $this->progress('timing', '  ↳ '.$this->elapsed($t));

        $t = microtime(true);
        [$phpFiles, $frontendFiles, $headContents] = $this->resolveHeadContents($nodes);
        $this->progress('timing', '  ↳ '.$this->elapsed($t));

        $t = microtime(true);
        [$fqcnToFilePath, $fileReferences] = $this->buildDependencyGraph($nodes, $phpFiles, $frontendFiles, $headContents);
        $this->progress('timing', '  ↳ '.$this->elapsed($t));

        if ($this->connectedNodes !== []) {
            $nodes = array_merge($nodes, array_values($this->connectedNodes));
            $this->progress('line', '  Found '.count($this->connectedNodes).' connected (non-diff) dependencies.');
        }

        $nodes = $this->enrichNodesWithKind($nodes, $headContents);

        $t = microtime(true);
        [$nodes, $cycleMap] = $this->detectAndAnnotateCycles($nodes);
        $this->progress('timing', '  ↳ '.$this->elapsed($t));

        $t = microtime(true);
        [$fileReports, $oldSources] = $this->runAstAnalysis($phpFiles, $headContents, $fileDiffMap, $criticalTables);
        $this->progress('timing', '  ↳ '.$this->elapsed($t));

        $nodes = $this->enrichNodesWithAnalysis($nodes, $fileReports);

        $analysisData = $this->buildAnalysisData($fileReports, $fileReferences);

        [$nodes, $analysisData] = $this->injectCycleFindings($nodes, $analysisData, $cycleMap);

        $t = microtime(true);
        ['hotSpots' => $phpHotSpots, 'metricsData' => $metricsData] = $this->computePhpMetrics($headContents, $oldSources, $fqcnToFilePath);
        $this->progress('timing', '  ↳ '.$this->elapsed($t));

        $t = microtime(true);
        ['hotSpots' => $jsHotSpots, 'metricsData' => $jsMetricsData] = $this->computeJsMetrics($frontendFiles, $headContents, $oldSources);
        $this->progress('timing', '  ↳ '.$this->elapsed($t));

        $metricsData = array_merge($metricsData, $jsMetricsData);

        $fileDiffs = $this->extractFileDiffs();

        $t = microtime(true);
        $fileContents = $includeFileContents ? $this->collectFileContents($fileDiffs, $headContents) : [];
        if ($includeFileContents) {
            $this->progress('timing', '  ↳ '.$this->elapsed($t).' reading diff file contents');
        }

        // Always load file contents for connected nodes so their code can be viewed in the panel.
        if ($this->connectedNodes !== []) {
            $connectedPaths = array_column(array_values($this->connectedNodes), 'path');
            // Prefetch blobs in a single batch fetch so git doesn't lazily pull them one-by-one.
            $t = microtime(true);
            if ($this->repoDir !== null) {
                $this->prefetchBlobs($this->repoDir, $this->headCommit, $connectedPaths);
                $this->progress('timing', '  ↳ '.$this->elapsed($t).' prefetching '.count($connectedPaths).' connected node blobs');
                $t = microtime(true);
            }
            $fileContents = array_merge($fileContents, $this->loadConnectedNodeContents($connectedPaths));
            $this->progress('timing', '  ↳ '.$this->elapsed($t).' reading connected node contents');
        }

        $t = microtime(true);
        $nodes = $this->computeSignalScores($nodes, $analysisData, $metricsData, $cycleMap);
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' computing signal scores');

        if ($minSeverity !== null) {
            $t = microtime(true);
            ['nodes' => $nodes, 'analysisData' => $analysisData, 'metricsData' => $metricsData,
                'fileDiffs' => $fileDiffs, 'fileContents' => $fileContents,
                'fileCount' => $fileCount, 'totalAdditions' => $totalAdditions, 'totalDeletions' => $totalDeletions]
                = $this->applyMinSeverityFilter($nodes, $analysisData, $metricsData, $fileDiffs, $fileContents, $minSeverity);
            $this->progress('timing', '  ↳ '.$this->elapsed($t).' severity filter');
        }

        $t = microtime(true);
        $riskResult = $this->computeRiskScore($nodes, $totalAdditions, $totalDeletions, $fileCount, $phpHotSpots + $jsHotSpots, $riskScoringConfig);
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' computing risk score');

        $t = microtime(true);
        $this->progress('info', "Generating {$format->value} report...");

        $reportGenerator = $format->generator(['metrics' => $githubMetrics]);
        $content = $reportGenerator->generate(
            layerStack: LayerStack::fromConfig($this->projectType),
            payload: new GraphPayload(
                nodes: $nodes,
                edges: $this->edges,
                fileDiffs: $fileDiffs,
                analysisData: $analysisData,
                metricsData: $metricsData,
                fileContents: $fileContents,
                filterDefaults: $this->resolveFilterDefaults($filterDefaults),
                riskScore: $riskResult,
            ),
            pr: new PullRequestContext(
                prTitle: $prTitle,
                repo: $repoName,
                headCommit: $this->headCommit,
                prAdditions: $totalAdditions,
                prDeletions: $totalDeletions,
                fileCount: $fileCount,
                prUrl: $prLinkUrl,
                connectedCount: count($this->connectedNodes),
            ),
        );
        $this->progress('timing', '  ↳ '.$this->elapsed($t));

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

        $this->progress('line', "  Generated: {$outputPath}");
        if ($this->githubCallCount > 0) {
            $rateLimitSuffix = $this->formatRateLimitSuffix();
            $this->progress('timing', "  GitHub API/fetch calls: {$this->githubCallCount}{$rateLimitSuffix}");
        }
        $this->progress('info', 'Done! ('.$this->elapsed($this->analyzeStart).' total)');

        return ['files' => ['all' => $outputPath], 'risk' => $riskResult];
    }

    // ── State ────────────────────────────────────────────────────────────────

    private function resetState(): void
    {
        $this->fqcnToNode = [];
        $this->pathToNode = [];
        $this->edges = [];
        $this->edgeSet = [];
        $this->connectedNodes = [];
        $this->connectedNodeFqcn = [];
        $this->psr4Map = null;
        $this->repoPath = '';
        $this->repoDir = null;
        $this->prRepo = '';
        $this->readContentsFromCommit = false;
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
        $outputDir = rtrim($outputDir ?? base_path('output'), '/');
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $safeBranch = preg_replace('/[^a-zA-Z0-9._-]/', '-', $this->branchName);
        $ext = $format->fileExtension();

        return $this->repoDir !== null
            ? "{$outputDir}/pr-".preg_replace('/[^0-9]/', '', $this->branchName).".{$ext}"
            : "{$outputDir}/local-{$safeBranch}.{$ext}";
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

        $this->progress('line', '  Found '.count($this->edges).' dependencies.');

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
            $this->pathToNode[$node['path']] = $node['id'];
            if (str_ends_with($node['path'], '.php')) {
                $fqcn = $filePathToFqcn[$node['path']] ?? $this->pathToFqcn($node['path']);
                if ($fqcn) {
                    $this->fqcnToNode[$fqcn] = $node['id'];
                }
            }
        }
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
        foreach ($phpFiles as $node) {
            $content = $headContents[$node['path']] ?? null;
            if ($content === null || $content === '') {
                continue;
            }
            $references = $this->extractReferences($content);
            $this->matchReferences($references, $node['id']);
            $this->matchViewReferences($content, $node['id'], $node['path']);
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
     * Detect circular dependencies and annotate each node with cycleId/cycleColor.
     *
     * @return array{0: array, 1: array<string, int>}
     */
    private function detectAndAnnotateCycles(array $nodes): array
    {
        $cycleMap = $this->detectCycles($nodes, $this->edges);
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

    /**
     * @return array{0: array, 1: array<string, string>}
     */
    private function runAstAnalysis(array $phpFiles, array $headContents, array $fileDiffMap, array $criticalTables = []): array
    {
        $this->progress('info', 'Running AST analysis...');

        $astComparer = new AstComparer;
        $changeClassifier = new ChangeClassifier($astComparer, $this->projectType, $this->repoPath ?: null, $criticalTables);

        $t = microtime(true);
        $oldSources = $this->fetchOldSources($phpFiles, $fileDiffMap);
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' fetching base sources ('.count($oldSources).' files)');

        $t = microtime(true);
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
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' AST parse + classify');

        return [$this->correlateWithMigrations($fileReports, $headContents), $oldSources];
    }

    private function correlateWithMigrations(array $fileReports, array $headContents): array
    {
        if ($this->projectType !== ProjectType::LaravelApp) {
            return $fileReports;
        }

        return (new LaravelMigrationModelCorrelator)->correlate(
            $fileReports,
            $headContents,
            $this->repoDir !== null ? null : $this->repoPath,
        );
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
                'line' => $c->line,
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

    private function computeSignalScores(array $nodes, array $analysisData, array $metricsData, array $cycleMap = []): array
    {
        $cycleCfg = config('laravel-code-analytics.file_signal.circular_dependency', []);
        $cycleBoostBase = (int) ($cycleCfg['base'] ?? 100);
        $cycleBoostPct = (float) ($cycleCfg['signal_pct'] ?? 0.20);

        foreach ($nodes as &$node) {
            $base = $this->fileSignalScorer->calculate(
                $node,
                $analysisData[$node['path']] ?? [],
                $metricsData[$node['path']] ?? null,
            );
            if (($node['cycleId'] ?? null) !== null) {
                $boost = (int) round($cycleBoostBase + $cycleBoostPct * $base);
                $node['_signal'] = $base + $boost;
                $node['_cycleBoost'] = $boost;
            } else {
                $node['_signal'] = $base;
            }
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

    // ── PR mode ──────────────────────────────────────────────────────────────

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

        $t = microtime(true);
        $this->githubCallCount++;
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

        $this->progress('line', '  Title: '.$prJson['title']);
        $this->progress('line', '  HEAD: '.substr($this->headCommit, 0, 7).'  Base: '.substr($this->baseCommit, 0, 7));
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' gh pr view');

        $t = microtime(true);
        $this->resolveGitObjectsCache(array_column($prJson['files'], 'path'));
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' git objects');

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

    private function resolveGitObjectsCache(array $changedPaths): void
    {
        if (empty($this->headCommit)) {
            return;
        }

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
            $this->fetchGitObjectsForPr($persistentDir, $changedPaths);
        }

        if ($this->repoDir !== null) {
            $this->projectType = (new ProjectTypeDetector)->fromGit($this->repoDir, $this->headCommit);
            $this->applyProjectTypeGroupResolver();
        }
    }

    private function fetchGitObjectsForPr(string $persistentDir, array $changedPaths): void
    {
        $phpPaths = array_values(array_filter($changedPaths, fn ($p) => str_ends_with($p, '.php')));

        $this->githubCallCount++;
        shell_exec("git -C {$persistentDir} fetch --depth 1 --filter=blob:none origin {$this->headCommit} 2>&1");

        if (! empty($this->baseCommit)) {
            $this->githubCallCount++;
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

        $gitDir = trim(shell_exec("git -C {$this->repoPath} rev-parse --git-dir 2>/dev/null") ?? '');
        if ($gitDir === '') {
            throw new RuntimeException("Not a git repository: {$this->repoPath}");
        }

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
        $hasUncommitted = ! $isHeadBase && trim(shell_exec("git -C {$this->repoPath} status --porcelain 2>/dev/null") ?? '') !== '';
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

        $t = microtime(true);
        $metricsBefore = $this->buildBeforePhpMetrics($oldSources);
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' base metrics ('.count($metricsBefore).' classes)');

        $t = microtime(true);
        $metricsByFqcn = (new PhpMetricsRunner)->run($headContents);
        $hotSpots = $this->countHotSpots($metricsByFqcn);
        $metricsData = [];

        foreach ($metricsByFqcn as $fqcn => $m) {
            $path = $fqcnToFilePath[$fqcn] ?? $this->fqcnToPath($fqcn);
            if ($path === null) {
                continue;
            }
            $entry = $this->buildPhpMetricsEntry($m, $metricsBefore[$path] ?? null);
            if (! empty($entry)) {
                $metricsData[$path] = $entry;
            }
        }

        $this->progress('line', '  Metrics computed for '.count($metricsByFqcn).' classes.');
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' head metrics');

        $t = microtime(true);
        $metricsData = $this->enrichWithMethodMetrics($metricsData, $headContents, $oldSources);
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' method metrics');

        return compact('hotSpots', 'metricsData');
    }

    private function buildBeforePhpMetrics(array $oldSources): array
    {
        if (empty($oldSources)) {
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
        foreach ((new PhpMetricsRunner)->run($oldSources) as $fqcn => $m) {
            $path = $oldFqcnToPath[$fqcn] ?? $this->fqcnToPath($fqcn);
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

        return $metricsData;
    }

    // ── JS metrics ───────────────────────────────────────────────────────────

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

        $jsContents = $this->collectJsContents($frontendFiles, $headContents);
        if (empty($jsContents)) {
            return ['hotSpots' => 0, 'metricsData' => []];
        }

        $this->progress('info', 'Running JS complexity analysis...');

        $t = microtime(true);
        $jsMetricsByPath = (new JsMetricsRunner)->run($jsContents);
        $this->progress('line', '  JS metrics computed for '.count($jsMetricsByPath).' files.');
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' head metrics');

        $t = microtime(true);
        $jsMetricsBefore = $this->computeJsMetricsBefore($jsContents, $oldSources);
        $this->progress('timing', '  ↳ '.$this->elapsed($t).' base metrics');

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

    private function computeJsMetricsBefore(array $jsContents, array $oldSources): array
    {
        if (empty($oldSources)) {
            return [];
        }

        $oldJsContents = array_intersect_key($oldSources, $jsContents);
        if (empty($oldJsContents)) {
            return [];
        }

        return (new JsMetricsRunner)->run($oldJsContents);
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

    // ── Utilities ────────────────────────────────────────────────────────────

    private function progress(string $level, string $message): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($level, $message);
        }
    }

    private function elapsed(float $start): string
    {
        $sec = microtime(true) - $start;

        return $sec >= 1.0
            ? sprintf('%.2fs', $sec)
            : sprintf('%dms', (int) round($sec * 1000));
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
            $matched = false;
            foreach ($this->fqcnToNode as $fqcn => $nodeId) {
                $fqcnShort = basename(str_replace('\\', '/', $fqcn));
                if ($fqcnShort === $shortName) {
                    $this->addEdge($sourceNodeId, $nodeId, $type);
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $connectedId = $this->ensureConnectedNode($ref);
                if ($connectedId !== null) {
                    $this->addEdge($sourceNodeId, $connectedId, $type);
                }
            }
        }
    }

    /**
     * Derive the likely file path for a given FQCN using PSR-4 mappings from composer.json.
     */
    private function fqcnToExpectedPath(string $fqcn): ?string
    {
        $map = $this->loadPsr4Map();

        // Sort by prefix length descending so the most-specific prefix wins
        foreach ($map as $prefix => $dir) {
            if (str_starts_with($fqcn, $prefix)) {
                return $dir.str_replace('\\', '/', substr($fqcn, strlen($prefix))).'.php';
            }
        }

        return null;
    }

    /**
     * Load PSR-4 namespace→directory mappings from composer.json, falling back to
     * Laravel conventions when composer.json is unavailable or unreadable.
     *
     * @return array<string, string> namespace prefix (with trailing \\) → relative dir (with trailing /)
     */
    private function loadPsr4Map(): array
    {
        if ($this->psr4Map !== null) {
            return $this->psr4Map;
        }

        $composerJson = $this->readComposerJson();
        $map = $this->parsePsr4Map($composerJson);

        // Fall back to Laravel conventions when composer.json is unavailable
        if (empty($map)) {
            $map = [
                'App\\' => 'app/',
                'Database\\Factories\\' => 'database/factories/',
                'Database\\Seeders\\' => 'database/seeders/',
                'Tests\\' => 'tests/',
            ];
        }

        // Sort by prefix length descending so the most-specific prefix wins
        uksort($map, fn ($a, $b) => strlen($b) - strlen($a));

        return $this->psr4Map = $map;
    }

    private function readComposerJson(): ?string
    {
        // Local mode: read directly from the filesystem
        if ($this->repoPath !== '') {
            $path = "{$this->repoPath}/composer.json";

            return is_file($path) ? (file_get_contents($path) ?: null) : null;
        }

        // Remote PR mode: read from the bare clone via git cat-file
        if ($this->repoDir !== null && $this->headCommit !== '') {
            $content = shell_exec("git -C {$this->repoDir} cat-file blob {$this->headCommit}:composer.json 2>/dev/null");

            return ($content !== null && $content !== '') ? $content : null;
        }

        return null;
    }

    /** @return array<string, string> */
    private function parsePsr4Map(?string $json): array
    {
        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach (['autoload', 'autoload-dev'] as $key) {
            foreach ($decoded[$key]['psr-4'] ?? [] as $ns => $dirs) {
                $ns = rtrim($ns, '\\').'\\';
                foreach ((array) $dirs as $dir) {
                    $map[$ns] = rtrim($dir, '/').'/';
                }
            }
        }

        return $map;
    }

    /**
     * Find or create a connected (non-diff) node for the given FQCN.
     * Returns the node ID, or null if the FQCN cannot be resolved.
     */
    private function ensureConnectedNode(string $fqcn): ?string
    {
        if (isset($this->connectedNodeFqcn[$fqcn])) {
            return $this->connectedNodeFqcn[$fqcn];
        }

        $path = $this->fqcnToExpectedPath($fqcn);
        if ($path === null) {
            return null;
        }

        // If this path already exists as a diff node, return that node's ID
        if (isset($this->pathToNode[$path])) {
            return $this->pathToNode[$path];
        }

        // For local repos, verify the file actually exists
        if ($this->repoDir === null && $this->repoPath !== '' && ! is_file("{$this->repoPath}/{$path}")) {
            return null;
        }

        $label = $this->generateLabel($path);
        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: basename($path);
        $folder = dirname($path);
        $folder = (string) preg_replace('#^app/#', '', $folder);
        $folder = (string) preg_replace('#^tests/(Unit|Feature)/#', 'tests/', $folder);
        if ($folder === '.' || $folder === '') {
            $folder = '';
        }
        $domain = explode('/', $folder)[0] ?: '(root)';

        $node = [
            'id' => $label,
            'path' => $path,
            'add' => 0,
            'del' => 0,
            'status' => 'modified',
            'group' => $this->groupResolver->resolve($path)->value,
            'hash' => hash('sha256', $path),
            'ext' => $ext,
            'folder' => $folder,
            'domain' => $domain,
            'domainColor' => '#484f58',
            'isConnected' => true,
        ];

        $this->connectedNodes[$label] = $node;
        $this->connectedNodeFqcn[$fqcn] = $label;
        $this->pathToNode[$path] = $label;

        return $label;
    }

    private function matchViewReferences(string $content, string $sourceNodeId, string $sourcePath = ''): void
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

        foreach ((new ViewFileDependencyRule)->resolve($content, $sourcePath) as $viewPath) {
            if (isset($this->pathToNode[$viewPath])) {
                $this->addEdge($sourceNodeId, $this->pathToNode[$viewPath]);
            }
        }

        if (str_ends_with($sourcePath, '.blade.php')) {
            foreach ((new BladeDependencyRule)->resolve($content) as $viewPath) {
                if (isset($this->pathToNode[$viewPath])) {
                    $this->addEdge($sourceNodeId, $this->pathToNode[$viewPath]);
                }
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
