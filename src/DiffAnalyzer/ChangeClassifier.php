<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer;

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

        usort($allChanges, fn (ClassifiedChange $a, ClassifiedChange $b) => $b->severity->score() <=> $a->severity->score());

        return new FileReport(
            path: $file->effectivePath(),
            status: $file->status,
            changes: $allChanges,
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
}
