<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelConsoleRule implements Rule
{
    use AnalyzesLaravelCode;

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects Artisan command and scheduled task changes';
    }

    public function description(): string
    {
        return 'Detects console and scheduling changes: Artisan command signature modifications, handle() logic changes, and scheduled task additions/removals/frequency changes.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];
        $path = $file->effectivePath();

        if ($this->pathContains($path, 'Console/Commands/') || $this->pathContains($path, 'Commands/')) {
            $this->analyzeCommandSignature($comparison, $changes);
            $this->analyzeCommandHandle($comparison, $changes);
        }

        if ($path === 'routes/console.php') {
            $this->analyzeSchedule($comparison, $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeCommandSignature(array $comparison, array &$changes): void
    {
        foreach ($comparison['properties'] as $key => $pair) {
            if (! str_ends_with($key, '::$description')) {
                continue;
            }

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $oldVal = $this->printer->prettyPrint([$pair['old']]);
                $newVal = $this->printer->prettyPrint([$pair['new']]);

                if ($oldVal !== $newVal) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::INFO,
                        description: 'Artisan command description changed on '.$this->getClassName($key),
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeCommandHandle(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            if ($this->getMethodName($key) !== 'handle') {
                continue;
            }

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $oldBody = $this->printer->prettyPrint($pair['old']->stmts ?? []);
                $newBody = $this->printer->prettyPrint($pair['new']->stmts ?? []);

                if ($oldBody !== $newBody) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: "Artisan command handle() changed: {$key}",
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeSchedule(array $comparison, array &$changes): void
    {
        if ($comparison['ast_identical']) {
            return;
        }

        $oldSchedules = $this->extractScheduleCalls($comparison['old_nodes']);
        $newSchedules = $this->extractScheduleCalls($comparison['new_nodes']);

        $added = count($newSchedules) - count($oldSchedules);

        if ($added > 0) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::MEDIUM,
                description: "{$added} scheduled task(s) added",
            );
        } elseif ($added < 0) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::MEDIUM,
                description: abs($added).' scheduled task(s) removed',
            );
        }

        // Compare individual schedules by command name
        $oldByCommand = $this->indexSchedulesByCommand($oldSchedules);
        $newByCommand = $this->indexSchedulesByCommand($newSchedules);

        foreach (array_intersect_key($oldByCommand, $newByCommand) as $command => $oldCall) {
            $newCall = $newByCommand[$command];
            $oldPrinted = $this->printer->prettyPrint([$oldCall]);
            $newPrinted = $this->printer->prettyPrint([$newCall]);

            if ($oldPrinted !== $newPrinted) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Schedule changed for: {$command} (frequency or options modified)",
                );
            }
        }
    }

    /**
     * @param  ?array<int, Node>  $nodes
     * @return list<Expr\MethodCall|Expr\StaticCall>
     */
    private function extractScheduleCalls(?array $nodes): array
    {
        if ($nodes === null) {
            return [];
        }

        $calls = [];

        // Schedule::command() style (Laravel 11+)
        $staticCalls = $this->finder->findInstanceOf($nodes, Expr\StaticCall::class);
        foreach ($staticCalls as $call) {
            if ($call->class instanceof Node\Name && $call->class->getLast() === 'Schedule') {
                $calls[] = $call;
            }
        }

        // $schedule->command() style
        $methodCalls = $this->finder->findInstanceOf($nodes, Expr\MethodCall::class);
        foreach ($methodCalls as $call) {
            if ($call->var instanceof Expr\Variable
                && $call->var->name === 'schedule'
                && $call->name instanceof Node\Identifier
                && $call->name->toString() === 'command') {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * @param  list<Expr\MethodCall|Expr\StaticCall>  $calls
     * @return array<string, Expr\MethodCall|Expr\StaticCall>
     */
    private function indexSchedulesByCommand(array $calls): array
    {
        $indexed = [];

        foreach ($calls as $call) {
            $command = $this->getFirstStringArg($call) ?? 'unknown';
            $indexed[$command] = $call;
        }

        return $indexed;
    }
}
