<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node\Stmt\Use_;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class ImportRule implements Rule
{
    public function shortDescription(): string
    {
        return 'Detects added or removed use/import statements';
    }

    public function description(): string
    {
        return 'Detects added or removed use/import statements.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        $oldUses = $this->extractUseNames($comparison['use_statements']['old'] ?? []);
        $newUses = $this->extractUseNames($comparison['use_statements']['new'] ?? []);

        $added = array_diff($newUses, $oldUses);
        $removed = array_diff($oldUses, $newUses);

        foreach ($added as $use) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::IMPORTS,
                severity: Severity::INFO,
                description: "Added import: {$use}",
            );
        }

        foreach ($removed as $use) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::IMPORTS,
                severity: Severity::INFO,
                description: "Removed import: {$use}",
            );
        }

        return $changes;
    }

    /**
     * @param  list<Use_>  $uses
     * @return list<string>
     */
    private function extractUseNames(array $uses): array
    {
        $names = [];

        foreach ($uses as $use) {
            foreach ($use->uses as $useItem) {
                $names[] = $useItem->name->toString();
            }
        }

        sort($names);

        return $names;
    }
}
