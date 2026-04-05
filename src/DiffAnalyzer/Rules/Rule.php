<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;

interface Rule
{
    /**
     * A short label for displaying this rule in the UI.
     */
    public function shortDescription(): string;

    /**
     * A human-readable description of what this rule detects.
     */
    public function description(): string;

    /**
     * Analyze the AST comparison result and return classified changes.
     *
     * @param  array<string, mixed>  $comparison  Output from AstComparer::compare()
     * @return list<ClassifiedChange>
     */
    public function analyze(FileDiff $file, array $comparison): array;
}
