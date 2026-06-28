<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use ReflectionClass;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileReport;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Rule;
use Vistik\LaravelCodeAnalytics\Support\Detection\ProjectType;

class ChangeClassifier
{
    /** @var list<Rule> */
    private array $rules;

    /**
     * @param  list<string>  $criticalTables
     */
    public function __construct(AstComparer $comparer, ProjectType $projectType = ProjectType::Unknown, ?string $repoPath = null, array $criticalTables = [])
    {
        $context = [
            'comparer' => $comparer,
            'criticalTables' => $criticalTables,
            'repoPath' => $repoPath,
        ];

        $genericClasses = config('laravel-code-analytics.rules.generic', []);
        $projectClasses = config('laravel-code-analytics.rules.'.$projectType->value, []);

        $this->rules = array_map(
            fn (string $class) => $this->instantiateRule($class, $context),
            [...$genericClasses, ...$projectClasses],
        );
    }

    /**
     * Classify changes for a single file.
     *
     * @param  array<string, mixed>  $comparison  Output from AstComparer::compare()
     */
    public function classify(FileDiff $file, array $comparison, ?string $newSource = null): FileReport
    {
        $allChanges = [];

        foreach ($this->rules as $rule) {
            $ruleChanges = $rule->analyze($file, $comparison);
            $allChanges = [...$allChanges, ...$ruleChanges];
        }

        if ($newSource !== null) {
            $allChanges = $this->attachSnippets($allChanges, $newSource);
        }

        $classLineMap = $this->buildClassLineMap($comparison);
        $primaryClassLine = $classLineMap === [] ? 1 : min($classLineMap);
        $allChanges = $this->backfillLines($allChanges, $classLineMap, $primaryClassLine);

        usort($allChanges, fn (ClassifiedChange $a, ClassifiedChange $b) => $b->severity->score() <=> $a->severity->score());

        return new FileReport(
            path: $file->effectivePath(),
            status: $file->status,
            changes: $allChanges,
            primaryClassLine: $primaryClassLine,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function instantiateRule(string $class, array $context): Rule
    {
        $ref = new ReflectionClass($class);
        $constructor = $ref->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $ref->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            if (array_key_exists($param->getName(), $context)) {
                $args[] = $context[$param->getName()];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            }
        }

        return $ref->newInstanceArgs($args);
    }

    private const SNIPPET_CONTEXT_LINES = 3;

    /**
     * Attach source code snippets to changes that have line numbers.
     *
     * @param  list<ClassifiedChange>  $changes
     * @return list<ClassifiedChange>
     */
    private function attachSnippets(array $changes, string $source): array
    {
        $lines = explode("\n", $source);
        $totalLines = count($lines);

        return array_map(function (ClassifiedChange $change) use ($lines, $totalLines) {
            if ($change->line === null || $change->snippet !== null) {
                return $change;
            }

            $start = max(0, $change->line - 1 - self::SNIPPET_CONTEXT_LINES);
            $end = min($totalLines, $change->line - 1 + self::SNIPPET_CONTEXT_LINES + 1);

            $snippetLines = [];
            for ($i = $start; $i < $end; $i++) {
                $lineNum = $i + 1;
                $marker = $lineNum === $change->line ? ' > ' : '   ';
                $snippetLines[] = $marker.$lineNum.' | '.$lines[$i];
            }

            return new ClassifiedChange(
                category: $change->category,
                severity: $change->severity,
                description: $change->description,
                location: $change->location,
                line: $change->line,
                snippet: implode("\n", $snippetLines),
            );
        }, $changes);
    }

    /**
     * Ensure every change carries a line number. Findings tied to a class member
     * (a non-null location) anchor to their class declaration line; file-level
     * findings (no location) anchor to line 1.
     *
     * @param  list<ClassifiedChange>  $changes
     * @param  array<string, int>  $classLineMap
     * @return list<ClassifiedChange>
     */
    private function backfillLines(array $changes, array $classLineMap, int $primaryClassLine): array
    {
        return array_map(function (ClassifiedChange $change) use ($classLineMap, $primaryClassLine) {
            if ($change->line !== null) {
                return $change;
            }

            return new ClassifiedChange(
                category: $change->category,
                severity: $change->severity,
                description: $change->description,
                location: $change->location,
                line: $this->resolveFallbackLine($change->location, $classLineMap, $primaryClassLine),
                snippet: $change->snippet,
            );
        }, $changes);
    }

    /**
     * Map every class-like declaration name to its starting line, preferring the
     * new source and falling back to the old source (e.g. for deleted files).
     *
     * @param  array<string, mixed>  $comparison
     * @return array<string, int>
     */
    private function buildClassLineMap(array $comparison): array
    {
        $nodes = $comparison['new_nodes'] ?? $comparison['old_nodes'] ?? null;

        if ($nodes === null) {
            return [];
        }

        $map = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, ClassLike::class) as $classLike) {
            $name = $classLike->name?->toString();
            if ($name !== null) {
                $map[$name] = $classLike->getStartLine();
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $classLineMap
     */
    private function resolveFallbackLine(?string $location, array $classLineMap, int $primaryClassLine): int
    {
        // File-level findings have no member location — anchor them to line 1.
        if ($location === null) {
            return 1;
        }

        // Class member findings (e.g. "ClassName::method") anchor to the class declaration.
        if (str_contains($location, '::')) {
            $className = explode('::', $location, 2)[0];
            if (isset($classLineMap[$className])) {
                return $classLineMap[$className];
            }
        }

        // Location names a member but the class could not be resolved precisely;
        // fall back to the file's first class declaration (defaults to line 1
        // when the file has no class).
        return $primaryClassLine;
    }
}
