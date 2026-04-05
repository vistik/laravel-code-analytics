<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class AttributeRule implements Rule
{
    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard;
    }

    public function shortDescription(): string
    {
        return 'Detects PHP 8 attribute additions, removals, and modifications';
    }

    public function description(): string
    {
        return 'Detects PHP 8 attribute changes: attributes added/removed/modified on classes, methods, properties, and parameters.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        // Class-level attributes
        foreach (['classes', 'interfaces', 'enums'] as $type) {
            foreach ($comparison[$type] ?? [] as $name => $pair) {
                if ($pair['old'] === null || $pair['new'] === null) {
                    continue;
                }

                $this->compareAttributes($name, $pair['old'], $pair['new'], $changes);
            }
        }

        // Method-level attributes
        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareAttributes($key, $pair['old'], $pair['new'], $changes);
        }

        // Property-level attributes
        foreach ($comparison['properties'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareAttributes($key, $pair['old'], $pair['new'], $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareAttributes(string $key, Node $old, Node $new, array &$changes): void
    {
        $oldAttrs = $this->extractAttributeNames($old);
        $newAttrs = $this->extractAttributeNames($new);

        $added = array_diff($newAttrs, $oldAttrs);
        $removed = array_diff($oldAttrs, $newAttrs);

        foreach ($added as $attr) {
            $severity = $this->attributeSeverity($attr);
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CLASS_STRUCTURE,
                severity: $severity,
                description: "Attribute added on {$key}: #[{$attr}]",
                location: $key,
                line: $new->getStartLine(),
            );
        }

        foreach ($removed as $attr) {
            $severity = $this->attributeSeverity($attr);
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CLASS_STRUCTURE,
                severity: $severity,
                description: "Attribute removed from {$key}: #[{$attr}]",
                location: $key,
                line: $new->getStartLine(),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function extractAttributeNames(Node $node): array
    {
        $names = [];

        if (! property_exists($node, 'attrGroups')) {
            return [];
        }

        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $names[] = $attr->name->getLast();
            }
        }

        sort($names);

        return $names;
    }

    private function attributeSeverity(string $attr): Severity
    {
        // Some attributes are more impactful than others
        return match ($attr) {
            'Deprecated' => Severity::MEDIUM,
            'Override' => Severity::INFO,
            'SensitiveParameter' => Severity::MEDIUM,
            default => Severity::INFO,
        };
    }
}
