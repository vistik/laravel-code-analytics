<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class ClassStructureRule implements Rule
{
    public function __construct() {}

    public function shortDescription(): string
    {
        return 'Detects class structure changes like inheritance, traits, properties, and modifiers';
    }

    public function description(): string
    {
        return 'Detects class structure changes: added/removed classes, interfaces, enums, extends/implements changes, trait usage, property and constant additions/removals, and class modifier changes (final, abstract, readonly).';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        $this->analyzeClassLikes('classes', $comparison, $changes);
        $this->analyzeClassLikes('interfaces', $comparison, $changes);
        $this->analyzeClassLikes('enums', $comparison, $changes);

        // Properties
        foreach ($comparison['properties'] as $key => $pair) {
            if ($pair['old'] !== null && $pair['new'] === null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CLASS_STRUCTURE,
                    severity: Severity::VERY_HIGH,
                    description: "Property removed: {$key}",
                    location: $key,
                );
            } elseif ($pair['old'] === null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CLASS_STRUCTURE,
                    severity: Severity::INFO,
                    description: "Property added: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            } elseif ($pair['old'] !== null && $pair['new'] !== null) {
                $this->comparePropertyModifiers($key, $pair['old'], $pair['new'], $changes);
            }
        }

        // Class constants
        foreach ($comparison['class_constants'] as $key => $pair) {
            if ($pair['old'] !== null && $pair['new'] === null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CLASS_STRUCTURE,
                    severity: Severity::VERY_HIGH,
                    description: "Class constant removed: {$key}",
                    location: $key,
                );
            } elseif ($pair['old'] === null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CLASS_STRUCTURE,
                    severity: Severity::INFO,
                    description: "Class constant added: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeClassLikes(string $type, array $comparison, array &$changes): void
    {
        $label = ucfirst(rtrim($type, 's'));

        foreach ($comparison[$type] ?? [] as $name => $pair) {
            // Renamed class detected by AstComparer
            if (isset($pair['renamed_from'])) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CLASS_STRUCTURE,
                    severity: Severity::MEDIUM,
                    description: "{$label} renamed: {$pair['renamed_from']} -> {$name}",
                    location: $name,
                    line: $pair['new']->getStartLine(),
                );

                // Still compare the matched pair for other structural changes
                $this->compareClassLike($name, $pair['old'], $pair['new'], $changes);

                continue;
            }

            if ($pair['old'] !== null && $pair['new'] === null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CLASS_STRUCTURE,
                    severity: Severity::VERY_HIGH,
                    description: "{$label} removed: {$name}",
                    location: $name,
                );

                continue;
            }

            if ($pair['old'] === null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CLASS_STRUCTURE,
                    severity: Severity::INFO,
                    description: "{$label} added: {$name}",
                    location: $name,
                    line: $pair['new']->getStartLine(),
                );

                continue;
            }

            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $this->compareClassLike($name, $pair['old'], $pair['new'], $changes);
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareClassLike(string $name, Stmt\Class_|Stmt\Interface_|Stmt\Enum_ $old, Stmt\Class_|Stmt\Interface_|Stmt\Enum_ $new, array &$changes): void
    {
        // Extends
        if ($old instanceof Stmt\Class_ && $new instanceof Stmt\Class_) {
            $oldExtends = $old->extends?->toString();
            $newExtends = $new->extends?->toString();

            if ($oldExtends !== $newExtends) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CLASS_STRUCTURE,
                    severity: Severity::VERY_HIGH,
                    description: "Parent class changed on {$name}: ".($oldExtends ?? 'none').' -> '.($newExtends ?? 'none'),
                    location: $name,
                    line: $new->getStartLine(),
                );
            }
        }

        // Implements
        if ($old instanceof Stmt\Class_ && $new instanceof Stmt\Class_) {
            $oldImpls = array_map(fn (Node\Name $n) => $n->toString(), $old->implements);
            $newImpls = array_map(fn (Node\Name $n) => $n->toString(), $new->implements);

            sort($oldImpls);
            sort($newImpls);

            $added = array_diff($newImpls, $oldImpls);
            $removed = array_diff($oldImpls, $newImpls);

            foreach ($added as $impl) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CLASS_STRUCTURE,
                    severity: Severity::MEDIUM,
                    description: "Interface added to {$name}: {$impl}",
                    location: $name,
                    line: $new->getStartLine(),
                );
            }

            foreach ($removed as $impl) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::CLASS_STRUCTURE,
                    severity: Severity::VERY_HIGH,
                    description: "Interface removed from {$name}: {$impl}",
                    location: $name,
                    line: $new->getStartLine(),
                );
            }
        }

        // Trait uses
        $oldTraits = $this->extractTraitNames($old);
        $newTraits = $this->extractTraitNames($new);

        $addedTraits = array_diff($newTraits, $oldTraits);
        $removedTraits = array_diff($oldTraits, $newTraits);

        foreach ($addedTraits as $trait) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CLASS_STRUCTURE,
                severity: Severity::MEDIUM,
                description: "Trait added to {$name}: {$trait}",
                location: $name,
                line: $new->getStartLine(),
            );
        }

        foreach ($removedTraits as $trait) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CLASS_STRUCTURE,
                severity: Severity::VERY_HIGH,
                description: "Trait removed from {$name}: {$trait}",
                location: $name,
                line: $new->getStartLine(),
            );
        }

        // Class modifiers (final, abstract, readonly)
        if ($old instanceof Stmt\Class_ && $new instanceof Stmt\Class_) {
            $this->compareClassModifiers($name, $old, $new, $changes);
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function compareClassModifiers(string $name, Stmt\Class_ $old, Stmt\Class_ $new, array &$changes): void
    {
        $wasFinal = (bool) ($old->flags & Modifiers::FINAL);
        $isFinal = (bool) ($new->flags & Modifiers::FINAL);

        if ($wasFinal !== $isFinal) {
            $action = $isFinal ? 'made final' : 'final removed';
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CLASS_STRUCTURE,
                severity: Severity::MEDIUM,
                description: "Class {$action}: {$name}",
                location: $name,
                line: $new->getStartLine(),
            );
        }

        $wasAbstract = (bool) ($old->flags & Modifiers::ABSTRACT);
        $isAbstract = (bool) ($new->flags & Modifiers::ABSTRACT);

        if ($wasAbstract !== $isAbstract) {
            $action = $isAbstract ? 'made abstract' : 'abstract removed';
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CLASS_STRUCTURE,
                severity: Severity::VERY_HIGH,
                description: "Class {$action}: {$name}",
                location: $name,
                line: $new->getStartLine(),
            );
        }

        $wasReadonly = (bool) ($old->flags & Modifiers::READONLY);
        $isReadonly = (bool) ($new->flags & Modifiers::READONLY);

        if ($wasReadonly !== $isReadonly) {
            $action = $isReadonly ? 'made readonly' : 'readonly removed';
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CLASS_STRUCTURE,
                severity: Severity::MEDIUM,
                description: "Class {$action}: {$name}",
                location: $name,
                line: $new->getStartLine(),
            );
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function comparePropertyModifiers(string $key, Stmt\Property $old, Stmt\Property $new, array &$changes): void
    {
        $wasReadonly = (bool) ($old->flags & Modifiers::READONLY);
        $isReadonly = (bool) ($new->flags & Modifiers::READONLY);

        if ($wasReadonly !== $isReadonly) {
            $action = $isReadonly ? 'made readonly' : 'readonly removed';
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CLASS_STRUCTURE,
                severity: Severity::MEDIUM,
                description: "Property {$action}: {$key}",
                location: $key,
                line: $new->getStartLine(),
            );
        }

        $oldVis = $this->getVisibility($old->flags);
        $newVis = $this->getVisibility($new->flags);

        if ($oldVis !== $newVis) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::CLASS_STRUCTURE,
                severity: Severity::MEDIUM,
                description: "Property visibility changed on {$key}: {$oldVis} -> {$newVis}",
                location: $key,
                line: $new->getStartLine(),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function extractTraitNames(Stmt\Class_|Stmt\Interface_|Stmt\Enum_ $classLike): array
    {
        $traits = [];

        foreach ($classLike->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $traits[] = $trait->toString();
                }
            }
        }

        sort($traits);

        return $traits;
    }

    private function getVisibility(int $flags): string
    {
        if ($flags & Modifiers::PUBLIC) {
            return 'public';
        }

        if ($flags & Modifiers::PROTECTED) {
            return 'protected';
        }

        if ($flags & Modifiers::PRIVATE) {
            return 'private';
        }

        return 'public';
    }
}
