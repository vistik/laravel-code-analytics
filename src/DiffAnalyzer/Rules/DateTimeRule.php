<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class DateTimeRule implements Rule
{
    /** @var list<string> Functions that critically affect global time state */
    private const CRITICAL_FUNCTIONS = [
        'date_default_timezone_set',
    ];

    /** @var list<string> Native PHP time and sleep functions that bypass mockable time */
    private const WARNING_FUNCTIONS = [
        'time',
        'date',
        'mktime',
        'gmmktime',
        'strtotime',
        'strftime',
        'gmdate',
        'microtime',
        'hrtime',
        'sleep',
        'usleep',
        'time_sleep_until',
        'time_nanosleep',
    ];

    /** @var list<string> Laravel/Carbon time helpers that create time instances */
    private const INFO_FUNCTIONS = ['now', 'today'];

    /** @var list<string> Carbon/Date facade classes */
    private const CARBON_CLASSES = ['Carbon', 'CarbonImmutable', 'Date'];

    /** @var list<string> Methods that manipulate the test clock */
    private const TEST_NOW_METHODS = ['setTestNow', 'setTestNowAndTimezone'];

    /** @var list<string> Carbon static constructors that create time instances */
    private const CARBON_CONSTRUCTOR_METHODS = [
        'now',
        'today',
        'yesterday',
        'tomorrow',
        'createFromTimestamp',
        'createFromTimestampMs',
        'createFromTimestampUTC',
        'parse',
        'create',
        'createFromDate',
        'createFromTime',
        'createFromFormat',
    ];

    private NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder;
    }

    public function shortDescription(): string
    {
        return 'Detects time and date manipulation changes';
    }

    public function description(): string
    {
        return 'Detects datetime changes: added/removed native time functions (time(), date(), strtotime()), sleep delays, global timezone overrides, Carbon/Date test clock manipulation (setTestNow), and Carbon/now() time instance creation (info).';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareNodes($key, $pair['old'], $pair['new'], $changes);
        }

        foreach ($comparison['functions'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareNodes($key, $pair['old'], $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareNodes(string $key, Node $old, Node $new, array &$changes): void
    {
        $this->compareFunctionCalls($key, $old, $new, $changes);
        $this->compareTestNowCalls($key, $old, $new, $changes);
        $this->compareCarbonConstructorCalls($key, $old, $new, $changes);
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareFunctionCalls(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldCalls = $this->extractFuncCallNames($old);
        $newCalls = $this->extractFuncCallNames($new);

        foreach (self::CRITICAL_FUNCTIONS as $func) {
            $wasPresent = in_array($func, $oldCalls);
            $isPresent = in_array($func, $newCalls);

            if (! $wasPresent && $isPresent) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::DATETIME,
                    severity: Severity::VERY_HIGH,
                    description: "Timezone override added in {$key}: {$func}()",
                    location: $key,
                );
            } elseif ($wasPresent && ! $isPresent) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::DATETIME,
                    severity: Severity::VERY_HIGH,
                    description: "Timezone override removed in {$key}: {$func}()",
                    location: $key,
                );
            }
        }

        foreach (self::WARNING_FUNCTIONS as $func) {
            $wasPresent = in_array($func, $oldCalls);
            $isPresent = in_array($func, $newCalls);

            if (! $wasPresent && $isPresent) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::DATETIME,
                    severity: Severity::MEDIUM,
                    description: "Native time function added in {$key}: {$func}()",
                    location: $key,
                );
            } elseif ($wasPresent && ! $isPresent) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::DATETIME,
                    severity: Severity::MEDIUM,
                    description: "Native time function removed in {$key}: {$func}()",
                    location: $key,
                );
            }
        }

        foreach (self::INFO_FUNCTIONS as $func) {
            $wasPresent = in_array($func, $oldCalls);
            $isPresent = in_array($func, $newCalls);

            if (! $wasPresent && $isPresent) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::DATETIME,
                    severity: Severity::INFO,
                    description: "Time helper added in {$key}: {$func}()",
                    location: $key,
                );
            } elseif ($wasPresent && ! $isPresent) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::DATETIME,
                    severity: Severity::INFO,
                    description: "Time helper removed in {$key}: {$func}()",
                    location: $key,
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareTestNowCalls(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldCalls = $this->extractTestNowCalls($old);
        $newCalls = $this->extractTestNowCalls($new);

        $added = array_diff($newCalls, $oldCalls);
        $removed = array_diff($oldCalls, $newCalls);

        foreach ($added as $call) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::DATETIME,
                severity: Severity::MEDIUM,
                description: "Test clock manipulation added in {$key}: {$call}",
                location: $key,
            );
        }

        foreach ($removed as $call) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::DATETIME,
                severity: Severity::MEDIUM,
                description: "Test clock manipulation removed in {$key}: {$call}",
                location: $key,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function extractFuncCallNames(Node $node): array
    {
        $names = [];
        $calls = $this->finder->findInstanceOf([$node], Expr\FuncCall::class);

        foreach ($calls as $call) {
            if ($call->name instanceof Node\Name) {
                $names[] = $call->name->toString();
            }
        }

        return array_unique($names);
    }

    /**
     * Extract Carbon/CarbonImmutable/Date::setTestNow() style static calls.
     *
     * @return list<string>
     */
    private function extractTestNowCalls(Node $node): array
    {
        $calls = [];
        $staticCalls = $this->finder->findInstanceOf([$node], Expr\StaticCall::class);

        foreach ($staticCalls as $call) {
            if (! ($call->class instanceof Node\Name)) {
                continue;
            }

            if (! ($call->name instanceof Node\Identifier)) {
                continue;
            }

            $class = $call->class->getLast();
            $method = $call->name->toString();

            if (in_array($class, self::CARBON_CLASSES) && in_array($method, self::TEST_NOW_METHODS)) {
                $calls[] = "{$class}::{$method}()";
            }
        }

        return array_unique($calls);
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareCarbonConstructorCalls(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldCalls = $this->extractCarbonConstructorCalls($old);
        $newCalls = $this->extractCarbonConstructorCalls($new);

        $added = array_diff($newCalls, $oldCalls);
        $removed = array_diff($oldCalls, $newCalls);

        foreach ($added as $call) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::DATETIME,
                severity: Severity::INFO,
                description: "Carbon time instance added in {$key}: {$call}",
                location: $key,
            );
        }

        foreach ($removed as $call) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::DATETIME,
                severity: Severity::INFO,
                description: "Carbon time instance removed in {$key}: {$call}",
                location: $key,
            );
        }
    }

    /**
     * Extract Carbon/CarbonImmutable/Date static constructor calls.
     *
     * @return list<string>
     */
    private function extractCarbonConstructorCalls(Node $node): array
    {
        $calls = [];
        $staticCalls = $this->finder->findInstanceOf([$node], Expr\StaticCall::class);

        foreach ($staticCalls as $call) {
            if (! ($call->class instanceof Node\Name)) {
                continue;
            }

            if (! ($call->name instanceof Node\Identifier)) {
                continue;
            }

            $class = $call->class->getLast();
            $method = $call->name->toString();

            if (in_array($class, self::CARBON_CLASSES) && in_array($method, self::CARBON_CONSTRUCTOR_METHODS)) {
                $calls[] = "{$class}::{$method}()";
            }
        }

        return array_unique($calls);
    }
}
