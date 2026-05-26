<?php

namespace Vistik\LaravelCodeAnalytics\ScheduledJobs;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

/**
 * Parses Laravel console/schedule files and builds an index of handler file paths
 * to their scheduled job definitions.
 *
 * Handles class-based entries only:
 *   $schedule->command(ClassName::class)->daily()
 *   Schedule::command(ClassName::class)->daily()
 *   $schedule->job(new ClassName)->hourly()
 */
class ScheduledJobIndexBuilder
{
    /**
     * @param  array<string, string|null>  $consoleFileContents  path => source
     * @param  callable(string): ?string  $fqcnToPath
     * @return array<string, ScheduledJobDefinition[]>  handler file path => jobs
     */
    public function build(array $consoleFileContents, callable $fqcnToPath): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $index = [];

        foreach ($consoleFileContents as $source) {
            if ($source === null || $source === '') {
                continue;
            }

            try {
                $stmts = $parser->parse($source);
            } catch (\Throwable) {
                continue;
            }

            if ($stmts === null) {
                continue;
            }

            $traverser = new NodeTraverser;
            $traverser->addVisitor(new ParentConnectingVisitor);
            $visitor = new ScheduledJobCollectorVisitor;
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            foreach ($visitor->jobs as $job) {
                $path = $fqcnToPath($job->handlerFqcn);
                if ($path === null) {
                    continue;
                }

                $index[$path][] = $job;
            }
        }

        return $index;
    }
}

/** @internal */
class ScheduledJobCollectorVisitor extends NodeVisitorAbstract
{
    /** @var array<string, string> short alias => FQCN */
    private array $useStatements = [];

    /** @var ScheduledJobDefinition[] */
    public array $jobs = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $alias = $use->alias !== null ? $use->alias->name : $use->name->getLast();
                $this->useStatements[$alias] = $use->name->toString();
            }
        }

        // $schedule->command(ClassName::class) or $schedule->job(new ClassName)
        if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $methodName = $node->name->name;

            if ($methodName === 'command') {
                $fqcn = $this->resolveClassConstArg($node->args[0] ?? null);
                if ($fqcn !== null) {
                    $this->jobs[] = new ScheduledJobDefinition(
                        handlerFqcn: $fqcn,
                        scheduleExpression: $this->extractScheduleExpression($node),
                    );
                }
            } elseif ($methodName === 'job') {
                $fqcn = $this->resolveNewArg($node->args[0] ?? null);
                if ($fqcn !== null) {
                    $this->jobs[] = new ScheduledJobDefinition(
                        handlerFqcn: $fqcn,
                        scheduleExpression: $this->extractScheduleExpression($node),
                    );
                }
            }
        }

        // Schedule::command(ClassName::class) / Schedule::job(new ClassName) (facade style)
        if ($node instanceof Expr\StaticCall
            && $node->name instanceof Node\Identifier
            && $node->class instanceof Node\Name
        ) {
            $methodName = $node->name->name;
            $fqcn = match ($methodName) {
                'command' => $this->resolveClassConstArg($node->args[0] ?? null),
                'job'     => $this->resolveNewArg($node->args[0] ?? null),
                default   => null,
            };
            if ($fqcn !== null) {
                $this->jobs[] = new ScheduledJobDefinition(
                    handlerFqcn: $fqcn,
                    scheduleExpression: $this->extractScheduleExpression($node),
                );
            }
        }

        return null;
    }

    private function extractScheduleExpression(Expr\MethodCall|Expr\StaticCall $callNode): string
    {
        $parts = [];
        $parent = $callNode->getAttribute('parent');

        while ($parent instanceof Expr\MethodCall) {
            $name = $parent->name instanceof Node\Identifier ? $parent->name->name : '';
            $argStr = '';

            if (! empty($parent->args) && $parent->args[0] instanceof Node\Arg) {
                $val = $parent->args[0]->value;
                if ($val instanceof Node\Scalar\String_) {
                    $argStr = "'".$val->value."'";
                } elseif ($val instanceof Node\Scalar\LNumber) {
                    $argStr = (string) $val->value;
                }
            }

            $parts[] = '->'.$name.'('.$argStr.')';
            $parent = $parent->getAttribute('parent');
        }

        return $parts ? implode('', $parts) : '->run()';
    }

    private function resolveClassConstArg(?Node\Arg $arg): ?string
    {
        if ($arg === null) {
            return null;
        }
        $value = $arg->value;
        if ($value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name) {
            $shortName = $value->class->getLast();

            return $this->useStatements[$shortName] ?? $value->class->toString();
        }

        return null;
    }

    private function resolveNewArg(?Node\Arg $arg): ?string
    {
        if ($arg === null) {
            return null;
        }
        $value = $arg->value;
        if ($value instanceof Expr\New_ && $value->class instanceof Node\Name) {
            $shortName = $value->class->getLast();

            return $this->useStatements[$shortName] ?? $value->class->toString();
        }

        return null;
    }
}
