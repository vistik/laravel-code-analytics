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
        ->and($changes[0]->severity)->toBe(Severity::VERY_HIGH)
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

it('returns no changes for identical resource', function () {
    $code = '<?php namespace App\Http\Resources; use Illuminate\Http\Resources\Json\JsonResource; class UserResource extends JsonResource { public function toArray($request) { return ["id" => $this->id]; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($code, $code);
    $file = new FileDiff('app/Http/Resources/UserResource.php', 'app/Http/Resources/UserResource.php', FileStatus::MODIFIED);

    $changes = (new LaravelApiResourceRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
