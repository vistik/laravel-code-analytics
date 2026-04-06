<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileReport;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\AssignmentRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\AttributeRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ClassStructureRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ConstructorInjectionRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ControlFlowRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\CosmeticRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\DateTimeRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\DependencyRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\EnumRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ErrorHandlingRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\FileLevelRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ImportRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelApiResourceRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelAuthRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelCacheRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConfigRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConsoleRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelDataMigrationRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelEloquentRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelEnvironmentRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelLivewireRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelMigrationRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelNotificationRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelQueueRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelRouteRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelServiceContainerRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelTableMigrationRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelUnauthorizedRouteRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MagicMethodRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodAddedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodChangedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodRemovedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodSignatureRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\OperatorRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Rule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\SideEffectRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\StrictTypesRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\TypeSystemRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ValueRule;

class ChangeClassifier
{
    /** @var list<Rule> */
    private array $rules;

    public function __construct(AstComparer $comparer, bool $isLaravel = true)
    {
        $this->rules = [
            // Generic rules
            new FileLevelRule,
            new DependencyRule,
            new CosmeticRule,
            new ImportRule,
            new StrictTypesRule,
            new TypeSystemRule,
            new MethodAddedRule,
            new MethodChangedRule($comparer),
            new MethodRemovedRule,
            new MethodSignatureRule,
            new ConstructorInjectionRule,
            new ClassStructureRule,
            new EnumRule,
            new AttributeRule,
            new MagicMethodRule,
            new ControlFlowRule,
            new OperatorRule,
            new ValueRule,
            new SideEffectRule,
            new ErrorHandlingRule,
            new AssignmentRule,
            new DateTimeRule,
        ];

        if ($isLaravel) {
            $this->rules = [
                ...$this->rules,
                new LaravelMigrationRule,
                new LaravelTableMigrationRule,
                new LaravelDataMigrationRule,
                new LaravelRouteRule,
                new LaravelUnauthorizedRouteRule,
                new LaravelEloquentRule,
                new LaravelAuthRule,
                new LaravelQueueRule,
                new LaravelNotificationRule,
                new LaravelServiceContainerRule,
                new LaravelConfigRule,
                new LaravelApiResourceRule,
                new LaravelLivewireRule,
                new LaravelConsoleRule,
                new LaravelEnvironmentRule,
                new LaravelCacheRule,
            ];
        }
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
