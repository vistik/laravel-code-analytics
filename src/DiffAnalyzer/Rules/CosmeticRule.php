<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class CosmeticRule implements Rule
{
    public function shortDescription(): string
    {
        return 'Detects cosmetic changes that do not affect runtime behavior';
    }

    public function description(): string
    {
        return 'Detects cosmetic changes: whitespace, formatting, comment-only edits, and PHPDoc modifications that do not affect runtime behavior.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        // If ASTs are identical but the file was modified, it's purely cosmetic
        if ($comparison['ast_identical'] && $file->status === FileStatus::MODIFIED) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::COSMETIC,
                severity: Severity::INFO,
                description: 'Whitespace or formatting changes only',
            );

            return $changes;
        }

        // Check for comment-only changes in methods
        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            if ($this->onlyCommentsChanged($pair['old'], $pair['new'])) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::COSMETIC,
                    severity: Severity::INFO,
                    description: "Only comments changed in {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }

        // Check for PHPDoc-only changes on classes
        foreach ($comparison['classes'] as $name => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldDoc = $this->getDocComment($pair['old']);
            $newDoc = $this->getDocComment($pair['new']);

            if ($oldDoc !== $newDoc) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::COSMETIC,
                    severity: Severity::INFO,
                    description: "PHPDoc changed on class {$name}",
                    location: $name,
                    line: $pair['new']->getStartLine(),
                );
            }
        }

        return $changes;
    }

    private function onlyCommentsChanged(Node $old, Node $new): bool
    {
        $oldComments = $old->getComments();
        $newComments = $new->getComments();

        // Strip comments from both and compare
        $oldClone = clone $old;
        $newClone = clone $new;
        $oldClone->setAttribute('comments', []);
        $newClone->setAttribute('comments', []);

        // If the code is the same without comments, but comments differ, it's comment-only
        $printer = new Standard;

        $oldCode = $printer->prettyPrint([$oldClone]);
        $newCode = $printer->prettyPrint([$newClone]);

        if ($oldCode !== $newCode) {
            return false;
        }

        // Verify that comments actually differ
        $oldCommentText = implode("\n", array_map(fn (Comment $c) => $c->getText(), $oldComments));
        $newCommentText = implode("\n", array_map(fn (Comment $c) => $c->getText(), $newComments));

        return $oldCommentText !== $newCommentText;
    }

    private function getDocComment(Node $node): ?string
    {
        $doc = $node->getDocComment();

        return $doc?->getText();
    }
}
