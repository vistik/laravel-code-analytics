<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelEnvironmentRule implements Rule
{
    use AnalyzesLaravelCode;

    /** @var list<string> */
    private const ENV_METHODS = [
        'environment',
        'isProduction',
        'isLocal',
        'runningUnitTests',
        'runningInConsole',
        'hasDebugModeEnabled',
        'isDownForMaintenance',
    ];

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects per-environment conditional checks';
    }

    public function description(): string
    {
        return 'Detects environment-specific logic: app()->isProduction(), App::environment(...), and similar calls that make code behave differently per environment.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['new'] === null) {
                continue;
            }

            $newChecks = $this->extractEnvironmentChecks($pair['new']);

            if ($pair['old'] === null) {
                foreach ($newChecks as $check) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: "Environment check added in {$key}: {$check}",
                        location: $key,
                    );
                }

                continue;
            }

            $oldChecks = $this->extractEnvironmentChecks($pair['old']);

            $added = array_diff($newChecks, $oldChecks);
            $removed = array_diff($oldChecks, $newChecks);

            foreach ($added as $check) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Environment check added in {$key}: {$check}",
                    location: $key,
                );
            }

            foreach ($removed as $check) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::INFO,
                    description: "Environment check removed in {$key}: {$check}",
                    location: $key,
                );
            }
        }

        return $changes;
    }

    /**
     * @return list<string>
     */
    private function extractEnvironmentChecks(Node $node): array
    {
        $checks = [];

        // app()->environment(), app()->isProduction(), etc.
        foreach ($this->finder->findInstanceOf([$node], Expr\MethodCall::class) as $call) {
            if (! $call->var instanceof Expr\FuncCall) {
                continue;
            }

            if (! $call->var->name instanceof Node\Name || $call->var->name->toString() !== 'app') {
                continue;
            }

            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $method = $call->name->toString();

            if (! in_array($method, self::ENV_METHODS, true)) {
                continue;
            }

            $args = $this->extractStringArgs($call);
            $checks[] = $args
                ? 'app()->'.$method.'('.implode(', ', array_map(fn (string $a) => "'{$a}'", $args)).')'
                : "app()->{$method}()";
        }

        // App::environment(), App::isProduction(), etc.
        foreach ($this->finder->findInstanceOf([$node], Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || $call->class->getLast() !== 'App') {
                continue;
            }

            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $method = $call->name->toString();

            if (! in_array($method, self::ENV_METHODS, true)) {
                continue;
            }

            $args = $this->extractStringArgs($call);
            $checks[] = $args
                ? 'App::'.$method.'('.implode(', ', array_map(fn (string $a) => "'{$a}'", $args)).')'
                : "App::{$method}()";
        }

        return array_unique($checks);
    }

    /**
     * @return list<string>
     */
    private function extractStringArgs(Expr\MethodCall|Expr\StaticCall $call): array
    {
        $args = [];

        foreach ($call->args as $arg) {
            if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
                $args[] = $arg->value->value;
            }
        }

        return $args;
    }
}
