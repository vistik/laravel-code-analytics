<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelApiResourceRule;

it('detects toArray changes in api resource', function () {
    $old = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id]; } }';
    $new = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id, "name" => $this->name]; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Resources/UserResource.php', 'app/Http/Resources/UserResource.php', FileStatus::MODIFIED);

    $changes = (new LaravelApiResourceRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('toArray()')
        ->and($changes[0]->location)->toContain('toArray');
});

it('detects with method changes', function () {
    $old = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id]; } public function with($request) { return ["meta" => ["version" => "1.0"]]; } }';
    $new = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id]; } public function with($request) { return ["meta" => ["version" => "2.0"]]; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Resources/UserResource.php', 'app/Http/Resources/UserResource.php', FileStatus::MODIFIED);

    $changes = (new LaravelApiResourceRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('with()');
});

it('ignores files outside Http/Resources path', function () {
    $old = '<?php namespace App\Services; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id]; } }';
    $new = '<?php namespace App\Services; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id, "name" => $this->name]; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Services/UserResource.php', 'app/Services/UserResource.php', FileStatus::MODIFIED);

    $changes = (new LaravelApiResourceRule)->analyze($file, $comparison);

    // Still detected because the class extends JsonResource (class-based detection)
    expect($changes)->toHaveCount(1);
});

it('ignores files outside resource path that do not extend resource classes', function () {
    $old = '<?php namespace App\Services; class UserService { public function toArray($request) { return ["id" => 1]; } }';
    $new = '<?php namespace App\Services; class UserService { public function toArray($request) { return ["id" => 1, "name" => "foo"]; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Services/UserService.php', 'app/Services/UserService.php', FileStatus::MODIFIED);

    $changes = (new LaravelApiResourceRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

it('reports removed keys as VERY_HIGH severity', function () {
    $old = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id, "email" => $this->email, "phone" => $this->phone]; } }';
    $new = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id]; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Resources/UserResource.php', 'app/Http/Resources/UserResource.php', FileStatus::MODIFIED);

    $changes = (new LaravelApiResourceRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($changes[0]->description)->toContain("removed: 'email', 'phone'");
});

it('reports added keys as MEDIUM severity', function () {
    $old = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id]; } }';
    $new = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id, "avatar" => $this->avatar]; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Resources/UserResource.php', 'app/Http/Resources/UserResource.php', FileStatus::MODIFIED);

    $changes = (new LaravelApiResourceRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain("added: 'avatar'");
});

it('emits separate findings for removed and added keys', function () {
    $old = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id, "email" => $this->email]; } }';
    $new = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id, "avatar" => $this->avatar]; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Resources/UserResource.php', 'app/Http/Resources/UserResource.php', FileStatus::MODIFIED);

    $changes = (new LaravelApiResourceRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(2);

    $byDescription = collect($changes)->keyBy(fn ($c) => $c->severity->value)->all();
    expect($byDescription[Severity::VERY_HIGH->value]->description)->toContain("removed: 'email'")
        ->and($byDescription[Severity::MEDIUM->value]->description)->toContain("added: 'avatar'");
});

it('falls back to generic description when return is not a static array literal', function () {
    $old = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return array_merge(parent::toArray($request), ["id" => $this->id]); } }';
    $new = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return array_merge(parent::toArray($request), ["id" => $this->id, "name" => $this->name]); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Resources/UserResource.php', 'app/Http/Resources/UserResource.php', FileStatus::MODIFIED);

    $changes = (new LaravelApiResourceRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->description)->toContain('API response shape may have changed');
});

it('returns no changes for identical resource', function () {
    $code = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id]; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Http/Resources/UserResource.php', 'app/Http/Resources/UserResource.php', FileStatus::MODIFIED);

    $changes = (new LaravelApiResourceRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
