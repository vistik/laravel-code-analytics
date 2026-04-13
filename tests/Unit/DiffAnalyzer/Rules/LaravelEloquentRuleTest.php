<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelEloquentRule;

it('detects fillable property change', function () {
    $old = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { protected $fillable = ["name", "email"]; }';
    $new = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { protected $fillable = ["name", "email", "phone"]; }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Models/User.php', 'app/Models/User.php', FileStatus::MODIFIED);

    $changes = (new LaravelEloquentRule)->analyze($file, $comparison);

    $fillableChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Mass-assignable fields changed'),
    ));

    expect($fillableChanges)->toHaveCount(1)
        ->and($fillableChanges[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($fillableChanges[0]->severity)->toBe(Severity::VERY_HIGH);
});

it('detects relationship method change', function () {
    $old = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { public function posts() { return $this->hasMany(Post::class); } }';
    $new = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { public function posts() { return $this->hasMany(Post::class, "author_id"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Models/User.php', 'app/Models/User.php', FileStatus::MODIFIED);

    $changes = (new LaravelEloquentRule)->analyze($file, $comparison);

    $relationChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Eloquent relationship changed'),
    ));

    expect($relationChanges)->toHaveCount(1)
        ->and($relationChanges[0]->category)->toBe(ChangeCategory::RELATIONSHIP_CHANGED)
        ->and($relationChanges[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($relationChanges[0]->location)->toContain('posts');
});

it('detects relationship method added', function () {
    $old = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { }';
    $new = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { public function posts() { return $this->hasMany(Post::class); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Models/User.php', 'app/Models/User.php', FileStatus::MODIFIED);

    $changes = (new LaravelEloquentRule)->analyze($file, $comparison);

    $addedChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Eloquent relationship added'),
    ));

    expect($addedChanges)->toHaveCount(1)
        ->and($addedChanges[0]->category)->toBe(ChangeCategory::RELATIONSHIP_ADDED)
        ->and($addedChanges[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($addedChanges[0]->description)->toContain('hasMany')
        ->and($addedChanges[0]->location)->toContain('posts');
});

it('detects relationship method removed', function () {
    $old = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { public function posts() { return $this->hasMany(Post::class); } }';
    $new = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Models/User.php', 'app/Models/User.php', FileStatus::MODIFIED);

    $changes = (new LaravelEloquentRule)->analyze($file, $comparison);

    $removedChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Eloquent relationship removed'),
    ));

    expect($removedChanges)->toHaveCount(1)
        ->and($removedChanges[0]->category)->toBe(ChangeCategory::RELATIONSHIP_REMOVED)
        ->and($removedChanges[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($removedChanges[0]->description)->toContain('hasMany')
        ->and($removedChanges[0]->location)->toContain('posts');
});

it('detects relationship type changed', function () {
    $old = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { public function posts() { return $this->hasMany(Post::class); } }';
    $new = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { public function posts() { return $this->belongsToMany(Post::class); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Models/User.php', 'app/Models/User.php', FileStatus::MODIFIED);

    $changes = (new LaravelEloquentRule)->analyze($file, $comparison);

    $typeChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Eloquent relationship type changed'),
    ));

    expect($typeChanges)->toHaveCount(1)
        ->and($typeChanges[0]->category)->toBe(ChangeCategory::RELATIONSHIP_TYPE_CHANGED)
        ->and($typeChanges[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($typeChanges[0]->description)->toContain('hasMany')
        ->and($typeChanges[0]->description)->toContain('belongsToMany')
        ->and($typeChanges[0]->location)->toContain('posts');
});

it('detects relationship constraint added', function () {
    $old = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { public function posts() { return $this->hasMany(Post::class); } }';
    $new = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { public function posts() { return $this->hasMany(Post::class)->where("status", true); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Models/User.php', 'app/Models/User.php', FileStatus::MODIFIED);

    $changes = (new LaravelEloquentRule)->analyze($file, $comparison);

    $constraintChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Eloquent relationship constraint changed'),
    ));

    expect($constraintChanges)->toHaveCount(1)
        ->and($constraintChanges[0]->category)->toBe(ChangeCategory::RELATIONSHIP_CONSTRAINT_CHANGED)
        ->and($constraintChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($constraintChanges[0]->location)->toContain('posts');
});

it('detects relationship constraint removed', function () {
    $old = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { public function posts() { return $this->hasMany(Post::class)->where("status", true); } }';
    $new = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { public function posts() { return $this->hasMany(Post::class); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Models/User.php', 'app/Models/User.php', FileStatus::MODIFIED);

    $changes = (new LaravelEloquentRule)->analyze($file, $comparison);

    $constraintChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Eloquent relationship constraint changed'),
    ));

    expect($constraintChanges)->toHaveCount(1)
        ->and($constraintChanges[0]->category)->toBe(ChangeCategory::RELATIONSHIP_CONSTRAINT_CHANGED)
        ->and($constraintChanges[0]->severity)->toBe(Severity::HIGH)
        ->and($constraintChanges[0]->location)->toContain('posts');
});

it('detects scope modification', function () {
    $old = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { public function scopeActive($query) { return $query->where("active", true); } }';
    $new = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { public function scopeActive($query) { return $query->where("active", true)->where("verified", true); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Models/User.php', 'app/Models/User.php', FileStatus::MODIFIED);

    $changes = (new LaravelEloquentRule)->analyze($file, $comparison);

    $scopeChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Eloquent scope modified'),
    ));

    expect($scopeChanges)->toHaveCount(1)
        ->and($scopeChanges[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($scopeChanges[0]->severity)->toBe(Severity::MEDIUM)
        ->and($scopeChanges[0]->location)->toContain('scopeActive');
});

it('returns no changes for identical model', function () {
    $code = '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { protected $fillable = ["name", "email"]; public function posts() { return $this->hasMany(Post::class); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Models/User.php', 'app/Models/User.php', FileStatus::MODIFIED);

    $changes = (new LaravelEloquentRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
