<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelQueueRule implements Rule
{
    use AnalyzesLaravelCode;

    /** @var list<string> */
    private const QUEUE_PROPERTIES = ['tries', 'backoff', 'timeout', 'queue', 'connection', 'maxExceptions', 'uniqueFor', 'deleteWhenMissingModels'];

    /** @var list<string> */
    private const QUEUE_INTERFACES = ['ShouldQueue', 'ShouldBeUnique', 'ShouldBeEncrypted', 'ShouldBeUniqueUntilProcessing'];

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects queue job handler, property, and interface changes';
    }

    public function description(): string
    {
        return 'Detects queue and job changes: handle() method modifications, job property changes ($tries, $backoff, $timeout, etc.), and ShouldQueue/ShouldBeUnique interface additions or removals.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];
        $path = $file->effectivePath();

        $isJobFile = $this->pathContains($path, 'Jobs/');
        $isListenerFile = $this->pathContains($path, 'Listeners/');

        $this->analyzeQueueInterfaces($comparison, $changes);
        $this->analyzeQueueProperties($comparison, $changes, $isJobFile || $isListenerFile);

        if ($isJobFile) {
            $this->analyzeJobHandle($comparison, $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeQueueInterfaces(array $comparison, array &$changes): void
    {
        foreach ($comparison['classes'] ?? [] as $name => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldImplements = array_map(
                fn ($n) => $n->getLast(),
                $pair['old']->implements
            );
            $newImplements = array_map(
                fn ($n) => $n->getLast(),
                $pair['new']->implements
            );

            foreach (self::QUEUE_INTERFACES as $interface) {
                $wasImplemented = in_array($interface, $oldImplements, true);
                $isImplemented = in_array($interface, $newImplements, true);

                if (! $wasImplemented && $isImplemented) {
                    $description = match ($interface) {
                        'ShouldQueue' => "{$name} is now asynchronous (ShouldQueue added)",
                        'ShouldBeUnique' => "{$name} is now unique (ShouldBeUnique added) — prevents duplicate jobs",
                        'ShouldBeEncrypted' => "{$name} payload is now encrypted",
                        'ShouldBeUniqueUntilProcessing' => "{$name} now unique until processing starts",
                        default => "{$interface} added to {$name}",
                    };

                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: $description,
                        location: $name,
                        line: $pair['new']->getStartLine(),
                    );
                }

                if ($wasImplemented && ! $isImplemented) {
                    $description = match ($interface) {
                        'ShouldQueue' => "{$name} is now synchronous (ShouldQueue removed)",
                        'ShouldBeUnique' => "{$name} uniqueness removed (ShouldBeUnique removed)",
                        default => "{$interface} removed from {$name}",
                    };

                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: $description,
                        location: $name,
                        line: $pair['new']->getStartLine(),
                    );
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeQueueProperties(array $comparison, array &$changes, bool $isQueueFile): void
    {
        foreach ($comparison['properties'] as $key => $pair) {
            $propName = ltrim(explode('::$', $key)[1] ?? '', '$');

            if (! in_array($propName, self::QUEUE_PROPERTIES, true)) {
                continue;
            }

            // Only report for files that look like queue classes, or check if class implements ShouldQueue
            $className = $this->getClassName($key);
            if (! $isQueueFile && ! $this->classImplements($comparison, $className, 'ShouldQueue')) {
                continue;
            }

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $oldVal = $this->printer->prettyPrint([$pair['old']]);
                $newVal = $this->printer->prettyPrint([$pair['new']]);

                if ($oldVal !== $newVal) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: "Job \${$propName} changed on {$className}",
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
    private function analyzeJobHandle(array $comparison, array &$changes): void
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
                        description: "Job handle() logic changed: {$key}",
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            }
        }
    }
}
