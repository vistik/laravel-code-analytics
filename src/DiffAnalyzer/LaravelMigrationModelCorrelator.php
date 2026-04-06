<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileReport;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelMigrationModelCorrelator
{
    use AnalyzesLaravelCode;

    private Parser $parser;

    public function __construct()
    {
        $this->initializeAnalyzer();
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    /**
     * Cross-reference migration files with the models that use their tables.
     * Appends MIGRATION_MODEL_LINK changes to migration FileReports.
     *
     * @param  array<string, FileReport>  $fileReports
     * @param  array<string, string|null>  $headContents  Source of all PR PHP files
     * @param  ?string  $repoDir  Local repo clone for scanning non-PR models
     * @return array<string, FileReport>
     */
    public function correlate(array $fileReports, array $headContents, ?string $repoDir): array
    {
        $migrationTables = $this->extractMigrationTables($headContents);

        if (empty($migrationTables)) {
            return $fileReports;
        }

        $tableToModel = $this->buildTableModelMap($headContents, $repoDir);

        foreach ($migrationTables as $migrationPath => $tables) {
            if (! isset($fileReports[$migrationPath])) {
                continue;
            }

            $additionalChanges = [];

            foreach ($tables as $tableName) {
                $modelPath = $tableToModel[$tableName] ?? null;

                if ($modelPath === null) {
                    continue;
                }

                if (isset($fileReports[$modelPath])) {
                    $additionalChanges[] = new ClassifiedChange(
                        category: ChangeCategory::MIGRATION_MODEL_LINK,
                        severity: Severity::INFO,
                        description: "Migration touches table `{$tableName}` — related model also updated in this PR: {$modelPath}",
                    );
                } else {
                    $additionalChanges[] = new ClassifiedChange(
                        category: ChangeCategory::MIGRATION_MODEL_LINK,
                        severity: Severity::MEDIUM,
                        description: "Migration touches table `{$tableName}` — check related model: {$modelPath}",
                    );
                }
            }

            if (! empty($additionalChanges)) {
                $existing = $fileReports[$migrationPath];
                $merged = [...$existing->changes, ...$additionalChanges];
                usort($merged, fn ($a, $b) => $b->severity->score() <=> $a->severity->score());
                $fileReports[$migrationPath] = new FileReport(
                    path: $existing->path,
                    status: $existing->status,
                    changes: $merged,
                );
            }
        }

        return $fileReports;
    }

    /**
     * Extract table names from migration files in the PR.
     *
     * @param  array<string, string|null>  $headContents
     * @return array<string, list<string>> migration path => list of table names
     */
    private function extractMigrationTables(array $headContents): array
    {
        $result = [];

        foreach ($headContents as $path => $source) {
            if ($source === null || ! str_contains($path, 'database/migrations/')) {
                continue;
            }

            try {
                $ast = $this->parser->parse($source);
            } catch (\Throwable) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $tables = [];

            foreach ($this->finder->findInstanceOf($ast, Node\Expr\StaticCall::class) as $call) {
                if (! ($call->class instanceof Node\Name && $call->class->getLast() === 'Schema')) {
                    continue;
                }

                $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

                if (! in_array($methodName, ['create', 'table', 'drop', 'dropIfExists', 'rename'], true)) {
                    continue;
                }

                $tableName = $this->getFirstStringArg($call);

                if ($tableName !== null) {
                    $tables[] = $tableName;
                }
            }

            if (! empty($tables)) {
                $result[$path] = array_values(array_unique($tables));
            }
        }

        return $result;
    }

    /**
     * Build a map of table name => model file path.
     * Checks PR files first, then scans app/Models/ on disk if repoDir is available.
     *
     * @param  array<string, string|null>  $headContents
     * @return array<string, string>
     */
    private function buildTableModelMap(array $headContents, ?string $repoDir): array
    {
        $map = [];

        foreach ($headContents as $path => $source) {
            if ($source === null || ! $this->isModelPath($path)) {
                continue;
            }

            $tableName = $this->resolveTableName($path, $source);

            $map[$tableName] = $path;
        }

        if ($repoDir !== null) {
            $modelsDir = rtrim($repoDir, '/').'/app/Models';

            if (is_dir($modelsDir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($modelsDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }

                    $absolutePath = $file->getPathname();
                    $relativePath = ltrim(str_replace(rtrim($repoDir, '/'), '', $absolutePath), '/');

                    if (array_key_exists($relativePath, $headContents)) {
                        continue;
                    }

                    $source = file_get_contents($absolutePath);

                    if ($source === false) {
                        continue;
                    }

                    $tableName = $this->resolveTableName($relativePath, $source);

                    if (! isset($map[$tableName])) {
                        $map[$tableName] = $relativePath;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Determine a model's table name from its file path and source code.
     * Checks for an explicit $table property first, then falls back to Laravel convention.
     */
    private function resolveTableName(string $path, string $source): string
    {
        try {
            $ast = $this->parser->parse($source);

            if ($ast !== null) {
                foreach ($this->finder->findInstanceOf($ast, Node\Stmt\PropertyProperty::class) as $prop) {
                    if ($prop->name->toString() === 'table' && $prop->default instanceof Node\Scalar\String_) {
                        return $prop->default->value;
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to convention
        }

        $className = pathinfo($path, PATHINFO_FILENAME);

        return Str::snake(Str::plural($className));
    }

    private function isModelPath(string $path): bool
    {
        return str_contains($path, 'app/Models/');
    }
}
