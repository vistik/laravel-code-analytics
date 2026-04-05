<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileReport;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\LaravelMigrationModelCorrelator;

function makeReport(string $path, FileStatus $status = FileStatus::MODIFIED, array $changes = []): FileReport
{
    return new FileReport($path, $status, $changes);
}

it('links a migration to a model in the same PR', function () {
    $migrationPath = 'database/migrations/2024_01_01_000000_create_users_table.php';
    $modelPath = 'app/Models/User.php';

    $fileReports = [
        $migrationPath => makeReport($migrationPath),
        $modelPath => makeReport($modelPath),
    ];

    $headContents = [
        $migrationPath => '<?php use Illuminate\Support\Facades\Schema; use Illuminate\Database\Schema\Blueprint; class CreateUsersTable { public function up() { Schema::create("users", function (Blueprint $table) { $table->id(); }); } }',
        $modelPath => '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { }',
    ];

    $result = (new LaravelMigrationModelCorrelator)->correlate($fileReports, $headContents, null);

    $migrationChanges = $result[$migrationPath]->changes;
    $link = collect($migrationChanges)->first(fn ($c) => $c->category === ChangeCategory::MIGRATION_MODEL_LINK);

    expect($link)->not->toBeNull()
        ->and($link->severity)->toBe(Severity::INFO)
        ->and($link->description)->toContain('users')
        ->and($link->description)->toContain($modelPath)
        ->and($link->description)->toContain('also updated in this PR');
});

it('flags a migration when the model is not in the PR', function () {
    $migrationPath = 'database/migrations/2024_01_01_000000_add_bio_to_users_table.php';
    $modelPath = 'app/Models/User.php';

    $fileReports = [
        $migrationPath => makeReport($migrationPath),
        // User model is NOT in the PR
    ];

    $headContents = [
        $migrationPath => '<?php use Illuminate\Support\Facades\Schema; use Illuminate\Database\Schema\Blueprint; class AddBioToUsersTable { public function up() { Schema::table("users", function (Blueprint $table) { $table->text("bio"); }); } }',
        $modelPath => '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { }',
    ];

    $result = (new LaravelMigrationModelCorrelator)->correlate($fileReports, $headContents, null);

    $migrationChanges = $result[$migrationPath]->changes;
    $link = collect($migrationChanges)->first(fn ($c) => $c->category === ChangeCategory::MIGRATION_MODEL_LINK);

    expect($link)->not->toBeNull()
        ->and($link->severity)->toBe(Severity::MEDIUM)
        ->and($link->description)->toContain('users')
        ->and($link->description)->toContain($modelPath)
        ->and($link->description)->toContain('check related model');
});

it('detects model table via explicit $table property', function () {
    $migrationPath = 'database/migrations/2024_01_01_000000_create_account_users_table.php';
    $modelPath = 'app/Models/User.php';

    $fileReports = [
        $migrationPath => makeReport($migrationPath),
        $modelPath => makeReport($modelPath),
    ];

    $headContents = [
        $migrationPath => '<?php use Illuminate\Support\Facades\Schema; use Illuminate\Database\Schema\Blueprint; class CreateAccountUsersTable { public function up() { Schema::create("account_users", function (Blueprint $table) { $table->id(); }); } }',
        $modelPath => '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { protected $table = "account_users"; }',
    ];

    $result = (new LaravelMigrationModelCorrelator)->correlate($fileReports, $headContents, null);

    $link = collect($result[$migrationPath]->changes)->first(fn ($c) => $c->category === ChangeCategory::MIGRATION_MODEL_LINK);

    expect($link)->not->toBeNull()
        ->and($link->description)->toContain('account_users');
});

it('falls back to Laravel naming convention when no $table property', function () {
    $migrationPath = 'database/migrations/2024_01_01_000000_create_blog_posts_table.php';
    $modelPath = 'app/Models/BlogPost.php';

    $fileReports = [
        $migrationPath => makeReport($migrationPath),
        $modelPath => makeReport($modelPath),
    ];

    $headContents = [
        $migrationPath => '<?php use Illuminate\Support\Facades\Schema; use Illuminate\Database\Schema\Blueprint; class CreateBlogPostsTable { public function up() { Schema::create("blog_posts", function (Blueprint $table) { $table->id(); }); } }',
        $modelPath => '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class BlogPost extends Model { }',
    ];

    $result = (new LaravelMigrationModelCorrelator)->correlate($fileReports, $headContents, null);

    $link = collect($result[$migrationPath]->changes)->first(fn ($c) => $c->category === ChangeCategory::MIGRATION_MODEL_LINK);

    expect($link)->not->toBeNull()
        ->and($link->description)->toContain('blog_posts');
});

it('does not emit when no model corresponds to the migration table', function () {
    $migrationPath = 'database/migrations/2024_01_01_000000_create_role_user_table.php';

    $fileReports = [
        $migrationPath => makeReport($migrationPath),
    ];

    $headContents = [
        $migrationPath => '<?php use Illuminate\Support\Facades\Schema; use Illuminate\Database\Schema\Blueprint; class CreateRoleUserTable { public function up() { Schema::create("role_user", function (Blueprint $table) { $table->id(); }); } }',
        // No model file for this pivot table
    ];

    $result = (new LaravelMigrationModelCorrelator)->correlate($fileReports, $headContents, null);

    $link = collect($result[$migrationPath]->changes)->first(fn ($c) => $c->category === ChangeCategory::MIGRATION_MODEL_LINK);

    expect($link)->toBeNull();
});

it('ignores non-migration files', function () {
    $servicePath = 'app/Services/UserService.php';
    $modelPath = 'app/Models/User.php';

    $fileReports = [
        $servicePath => makeReport($servicePath),
        $modelPath => makeReport($modelPath),
    ];

    $headContents = [
        $servicePath => '<?php class UserService { }',
        $modelPath => '<?php class User extends Model { }',
    ];

    $result = (new LaravelMigrationModelCorrelator)->correlate($fileReports, $headContents, null);

    expect($result[$servicePath]->changes)->toBeEmpty();
    expect($result[$modelPath]->changes)->toBeEmpty();
});

it('preserves existing changes on the migration report', function () {
    $migrationPath = 'database/migrations/2024_01_01_000000_create_users_table.php';
    $modelPath = 'app/Models/User.php';

    $existingChange = new ClassifiedChange(
        category: ChangeCategory::LARAVEL,
        severity: Severity::MEDIUM,
        description: 'Migration creates table (users)',
    );

    $fileReports = [
        $migrationPath => makeReport($migrationPath, FileStatus::MODIFIED, [$existingChange]),
        $modelPath => makeReport($modelPath),
    ];

    $headContents = [
        $migrationPath => '<?php use Illuminate\Support\Facades\Schema; use Illuminate\Database\Schema\Blueprint; class CreateUsersTable { public function up() { Schema::create("users", function (Blueprint $table) { $table->id(); }); } }',
        $modelPath => '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { }',
    ];

    $result = (new LaravelMigrationModelCorrelator)->correlate($fileReports, $headContents, null);

    $changes = $result[$migrationPath]->changes;

    expect($changes)->toHaveCount(2);
    expect(collect($changes)->first(fn ($c) => $c->description === $existingChange->description))->not->toBeNull();
});
