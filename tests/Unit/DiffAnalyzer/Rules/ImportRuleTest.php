<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ImportRule;

it('detects added import', function () {
    $old = '<?php use App\Models\User;';
    $new = '<?php use App\Models\User; use App\Models\Post;';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Services/PostService.php', 'app/Services/PostService.php', FileStatus::MODIFIED);

    $changes = (new ImportRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Added import')));

    expect($added)->toHaveCount(1)
        ->and($added[0]->category)->toBe(ChangeCategory::IMPORTS)
        ->and($added[0]->severity)->toBe(Severity::INFO)
        ->and($added[0]->description)->toContain('App\Models\Post');
});

it('detects removed import', function () {
    $old = '<?php use App\Models\User; use App\Models\Post;';
    $new = '<?php use App\Models\User;';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Services/PostService.php', 'app/Services/PostService.php', FileStatus::MODIFIED);

    $changes = (new ImportRule)->analyze($file, $comparison);

    $removed = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Removed import')));

    expect($removed)->toHaveCount(1)
        ->and($removed[0]->category)->toBe(ChangeCategory::IMPORTS)
        ->and($removed[0]->severity)->toBe(Severity::INFO)
        ->and($removed[0]->description)->toContain('App\Models\Post');
});

it('returns no changes when imports unchanged', function () {
    $code = '<?php use App\Models\User; use App\Models\Post;';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Services/PostService.php', 'app/Services/PostService.php', FileStatus::MODIFIED);

    $changes = (new ImportRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('detects multiple import changes', function () {
    $old = '<?php use App\Models\User; use App\Models\Post;';
    $new = '<?php use App\Models\User; use App\Models\Comment; use App\Models\Tag;';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Services/PostService.php', 'app/Services/PostService.php', FileStatus::MODIFIED);

    $changes = (new ImportRule)->analyze($file, $comparison);

    $added = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Added import')));
    $removed = array_values(array_filter($changes, fn ($c) => str_contains($c->description, 'Removed import')));

    expect($added)->toHaveCount(2)
        ->and($removed)->toHaveCount(1)
        ->and($removed[0]->description)->toContain('App\Models\Post');
});
