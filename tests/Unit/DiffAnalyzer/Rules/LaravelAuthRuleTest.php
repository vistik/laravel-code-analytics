<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelAuthRule;

it('detects authorize method changes in form request', function () {
    $old = '<?php namespace App\Http\Requests; use Illuminate\Foundation\Http\FormRequest; class StoreUserRequest extends FormRequest { public function authorize() { return true; } public function rules() { return ["name" => "required"]; } }';
    $new = '<?php namespace App\Http\Requests; use Illuminate\Foundation\Http\FormRequest; class StoreUserRequest extends FormRequest { public function authorize() { return $this->user()->isAdmin(); } public function rules() { return ["name" => "required"]; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Requests/StoreUserRequest.php', 'app/Http/Requests/StoreUserRequest.php', FileStatus::MODIFIED);

    $changes = (new LaravelAuthRule)->analyze($file, $comparison);

    $authorizeChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'authorize()'),
    ));

    expect($authorizeChanges)->toHaveCount(1)
        ->and($authorizeChanges[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($authorizeChanges[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($authorizeChanges[0]->description)->toContain('authorize()')
        ->and($authorizeChanges[0]->description)->toContain('security-critical');
});

it('detects rules method changes in form request', function () {
    $old = '<?php namespace App\Http\Requests; use Illuminate\Foundation\Http\FormRequest; class StoreUserRequest extends FormRequest { public function authorize() { return true; } public function rules() { return ["name" => "required"]; } }';
    $new = '<?php namespace App\Http\Requests; use Illuminate\Foundation\Http\FormRequest; class StoreUserRequest extends FormRequest { public function authorize() { return true; } public function rules() { return ["name" => "required", "email" => "required|email"]; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Requests/StoreUserRequest.php', 'app/Http/Requests/StoreUserRequest.php', FileStatus::MODIFIED);

    $changes = (new LaravelAuthRule)->analyze($file, $comparison);

    $rulesChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Validation rules'),
    ));

    expect($rulesChanges)->toHaveCount(1)
        ->and($rulesChanges[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($rulesChanges[0]->severity)->toBe(Severity::MEDIUM);
});

it('detects policy method changes', function () {
    $old = '<?php namespace App\Policies; class UserPolicy { public function update($user, $model) { return $user->id === $model->user_id; } }';
    $new = '<?php namespace App\Policies; class UserPolicy { public function update($user, $model) { return $user->isAdmin() || $user->id === $model->user_id; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Policies/UserPolicy.php', 'app/Policies/UserPolicy.php', FileStatus::MODIFIED);

    $changes = (new LaravelAuthRule)->analyze($file, $comparison);

    $policyChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'policy'),
    ));

    expect($policyChanges)->toHaveCount(1)
        ->and($policyChanges[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($policyChanges[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($policyChanges[0]->description)->toContain('Authorization policy changed');
});

it('detects auth config changes', function () {
    $old = '<?php return ["defaults" => ["guard" => "web"]];';
    $new = '<?php return ["defaults" => ["guard" => "api"]];';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('config/auth.php', 'config/auth.php', FileStatus::MODIFIED);

    $changes = (new LaravelAuthRule)->analyze($file, $comparison);

    $authConfigChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'Authentication configuration'),
    ));

    expect($authConfigChanges)->toHaveCount(1)
        ->and($authConfigChanges[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($authConfigChanges[0]->severity)->toBe(Severity::VERY_HIGH);
});

it('ignores files outside request/policy paths', function () {
    $old = '<?php namespace App\Services; class AuthService { public function authorize() { return true; } }';
    $new = '<?php namespace App\Services; class AuthService { public function authorize() { return false; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Services/AuthService.php', 'app/Services/AuthService.php', FileStatus::MODIFIED);

    $changes = (new LaravelAuthRule)->analyze($file, $comparison);

    // No authorize or policy changes detected since the path doesn't match
    $authChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'authorize()') || str_contains($c->description, 'policy'),
    ));

    expect($authChanges)->toBeEmpty();
});
