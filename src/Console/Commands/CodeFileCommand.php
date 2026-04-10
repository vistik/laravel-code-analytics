<?php

namespace Vistik\LaravelCodeAnalytics\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Vistik\LaravelCodeAnalytics\Actions\GenerateHtmlReport;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;
use Vistik\LaravelCodeAnalytics\Support\MethodCallGraphExtractor;

class CodeFileCommand extends Command
{
    protected $signature = 'code:file
        {path : Path to the PHP file to analyze}
        {output? : Output file path (HTML). When omitted, force/tree/grouped layouts are written to a temp directory}
        {--depth=0 : Recursive dependency depth (0 = entry file only, 1+ = follow callees into their source files)}
        {--view=force : Default graph view when a single output file is requested (force, tree, grouped)}
        {--open : Open the generated file in the browser when done}';

    protected $description = 'Generate a method call-graph for a single PHP file, optionally following dependencies recursively';

    public function handle(GenerateHtmlReport $generator): int
    {
        try {
            $path = $this->argument('path');
            $fullPath = realpath($path);

            if ($fullPath === false || ! is_file($fullPath)) {
                throw new RuntimeException("File not found: {$path}");
            }

            $repoRoot = $this->findRepoRoot($fullPath);
            $relPath = $repoRoot !== ''
                ? ltrim(str_replace(rtrim($repoRoot, '/'), '', $fullPath), '/')
                : basename($fullPath);

            $depth = max(0, (int) $this->option('depth'));
            $depthLabel = $depth === 0 ? 'single file' : "depth {$depth}";
            $this->line("Analyzing <info>{$relPath}</info> ({$depthLabel})...");

            $extractor = new MethodCallGraphExtractor;
            ['nodes' => $nodes, 'edges' => $edges] = $depth === 0
                ? $extractor->extract($fullPath, $repoRoot)
                : $extractor->extractRecursive($fullPath, $repoRoot, $depth);

            if (empty($nodes)) {
                throw new RuntimeException('No PHP classes or methods found in the file.');
            }

            $focalCount = count(array_filter($nodes, fn ($n) => $n['focal']));
            $externalCount = count($nodes) - $focalCount;

            $this->line("Found <info>{$focalCount}</info> focal methods, <info>{$externalCount}</info> external callees, <info>".count($edges).'</info> call edges.');

            $prNumber = pathinfo(basename($fullPath), PATHINFO_FILENAME);
            $title = "Method graph: {$relPath}";
            $view = $this->option('view') ?? 'force';
            $outputArg = $this->argument('output');
            $openFile = $this->option('open');

            // Show connected (external callee) nodes by default in method graph
            $filterDefaults = ['hide_connected' => false];

            // Single-file mode: visibility rings; multi-file: architectural layers
            $layerStack = $depth === 0 ? LayerStack::forMethodGraph() : LayerStack::default();

            if ($outputArg !== null) {
                // Single output path: one layout file, no switcher needed
                $html = $generator->execute(
                    nodes: $nodes,
                    edges: $edges,
                    fileDiffs: [],
                    analysisData: [],
                    prNumber: $prNumber,
                    prTitle: $title,
                    prUrl: '',
                    prAdditions: 0,
                    prDeletions: 0,
                    repo: $repoRoot,
                    headCommit: '',
                    fileCount: $focalCount,
                    connectedCount: $externalCount,
                    extTogglesHtml: '',
                    folderTogglesHtml: '',
                    layout: $view,
                    layerStack: $layerStack,
                    filterDefaults: $filterDefaults,
                );

                file_put_contents($outputArg, $html);
                $this->info("Written: {$outputArg}");

                if ($openFile) {
                    shell_exec('open '.escapeshellarg($outputArg));
                }

                return self::SUCCESS;
            }

            // No output path: generate force/tree/grouped with layout switcher
            $outputDir = sys_get_temp_dir().'/code-file-'.substr(md5($fullPath), 0, 8);
            if (! is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $files = $generator->writeFiles(
                nodes: $nodes,
                edges: $edges,
                fileDiffs: [],
                analysisData: [],
                prNumber: $prNumber,
                prTitle: $title,
                prUrl: '',
                prAdditions: 0,
                prDeletions: 0,
                repo: $repoRoot,
                headCommit: '',
                fileCount: $focalCount,
                connectedCount: $externalCount,
                extTogglesHtml: '',
                folderTogglesHtml: '',
                severityTogglesHtml: '',
                outputDir: $outputDir,
                layerStack: $layerStack,
                filterDefaults: $filterDefaults,
                onlyLayouts: ['force', 'tree', 'grouped'],
            );

            foreach ($files as $layout => $file) {
                $this->line("  <comment>{$layout}</comment>: {$file}");
            }

            if ($openFile) {
                $first = reset($files);
                shell_exec('open '.escapeshellarg($first));
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function findRepoRoot(string $filePath): string
    {
        $dir = dirname($filePath);
        while ($dir !== '/' && $dir !== '') {
            if (is_dir($dir.'/.git')) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        return '';
    }
}
