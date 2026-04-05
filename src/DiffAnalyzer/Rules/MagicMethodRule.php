<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\PrettyPrinter\Standard;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class MagicMethodRule implements Rule
{
    /** @var array<string, array{description: string, severity: Severity}> */
    private const MAGIC_METHODS = [
        '__toString' => ['description' => 'String representation changed', 'severity' => Severity::MEDIUM],
        '__get' => ['description' => 'Magic getter changed — affects dynamic property access', 'severity' => Severity::MEDIUM],
        '__set' => ['description' => 'Magic setter changed — affects dynamic property assignment', 'severity' => Severity::MEDIUM],
        '__call' => ['description' => 'Magic method call handler changed', 'severity' => Severity::MEDIUM],
        '__callStatic' => ['description' => 'Static magic method call handler changed', 'severity' => Severity::MEDIUM],
        '__invoke' => ['description' => 'Invocable behavior changed', 'severity' => Severity::MEDIUM],
        '__isset' => ['description' => 'Magic isset check changed', 'severity' => Severity::INFO],
        '__unset' => ['description' => 'Magic unset behavior changed', 'severity' => Severity::INFO],
        '__destruct' => ['description' => 'Destructor changed — cleanup behavior affected', 'severity' => Severity::MEDIUM],
        '__clone' => ['description' => 'Clone behavior changed', 'severity' => Severity::MEDIUM],
        '__debugInfo' => ['description' => 'Debug output changed', 'severity' => Severity::INFO],
        '__serialize' => ['description' => 'Serialization format changed', 'severity' => Severity::VERY_HIGH],
        '__unserialize' => ['description' => 'Deserialization format changed', 'severity' => Severity::VERY_HIGH],
        '__sleep' => ['description' => 'Sleep serialization changed', 'severity' => Severity::MEDIUM],
        '__wakeup' => ['description' => 'Wakeup deserialization changed', 'severity' => Severity::MEDIUM],
    ];

    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard;
    }

    public function shortDescription(): string
    {
        return 'Detects PHP magic method changes that affect implicit behavior';
    }

    public function description(): string
    {
        return 'Detects magic method changes: __toString, __get, __set, __call, __invoke, __serialize, __destruct, and other PHP magic methods that affect implicit behavior.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        foreach ($comparison['methods'] as $key => $pair) {
            $methodName = explode('::', $key)[1] ?? '';

            if (! isset(self::MAGIC_METHODS[$methodName])) {
                continue;
            }

            $meta = self::MAGIC_METHODS[$methodName];
            $className = explode('::', $key)[0];

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $oldBody = $this->printer->prettyPrint($pair['old']->stmts ?? []);
                $newBody = $this->printer->prettyPrint($pair['new']->stmts ?? []);

                if ($oldBody !== $newBody) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::METHOD_SIGNATURE,
                        severity: $meta['severity'],
                        description: "{$className}::{$methodName}() — {$meta['description']}",
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            } elseif ($pair['old'] === null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::METHOD_SIGNATURE,
                    severity: $meta['severity'],
                    description: "Magic method added: {$className}::{$methodName}() — {$meta['description']}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            } elseif ($pair['old'] !== null && $pair['new'] === null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::METHOD_SIGNATURE,
                    severity: $meta['severity'],
                    description: "Magic method removed: {$className}::{$methodName}()",
                    location: $key,
                );
            }
        }

        return $changes;
    }
}
