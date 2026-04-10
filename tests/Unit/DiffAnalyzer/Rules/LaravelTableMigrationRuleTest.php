<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelTableMigrationRule;

$migrationFile = fn (string $name = 'test_migration') => new FileDiff(
    "database/migrations/2024_01_01_000000_{$name}.php",
    "database/migrations/2024_01_01_000000_{$name}.php",
    FileStatus::MODIFIED,
);

$stub = fn (string $upBody) => '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class TestMigration extends Migration {
    public function up() { '.$upBody.' }
    public function down() { }
}';

// --- Index additions on existing tables ---

it('flags index addition on existing table as high', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Schema::table("products", function (Blueprint $table) { $table->index("status"); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelTableMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'existing table'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::HIGH)
        ->and($hit->category)->toBe(ChangeCategory::LARAVEL)
        ->and($hit->description)->toContain('index')
        ->and($hit->description)->toContain('products');
});

it('flags unique index addition on existing table as high', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Schema::table("products", function (Blueprint $table) { $table->unique("slug"); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelTableMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'unique index'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::HIGH);
});

it('includes line number for index addition', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Schema::table("orders", function (Blueprint $table) { $table->index("created_at"); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelTableMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'existing table'));

    expect($hit)->not->toBeNull()
        ->and($hit->line)->toBeInt();
});

// --- Index additions on new tables ---

it('flags index addition on new table as info only', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Schema::create("new_table", function (Blueprint $table) { $table->id(); $table->index("name"); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelTableMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'index'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::INFO)
        ->and($hit->description)->toContain('new table');
});

// --- Index removals ---

it('flags index removal as high', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Schema::table("products", function (Blueprint $table) { $table->dropIndex("products_status_index"); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelTableMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'Removes index'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::HIGH)
        ->and($hit->description)->toContain('dropIndex')
        ->and($hit->description)->toContain('products');
});

it('flags unique index removal as high', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Schema::table("users", function (Blueprint $table) { $table->dropUnique("users_email_unique"); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelTableMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'Removes index'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::HIGH)
        ->and($hit->description)->toContain('dropUnique');
});

it('includes line number for index removal', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Schema::table("orders", function (Blueprint $table) { $table->dropIndex("orders_status_index"); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelTableMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'Removes index'));

    expect($hit)->not->toBeNull()
        ->and($hit->line)->toBeInt();
});

// --- Critical tables ---

it('elevates index addition on critical table to very high', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Schema::table("users", function (Blueprint $table) { $table->index("status"); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelTableMigrationRule(['users']))->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'existing table'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::VERY_HIGH)
        ->and($hit->description)->toContain('critical table');
});

it('elevates index removal on critical table to very high', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Schema::table("users", function (Blueprint $table) { $table->dropIndex("users_status_index"); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelTableMigrationRule(['users']))->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'Removes index'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::VERY_HIGH)
        ->and($hit->description)->toContain('critical table');
});

it('does not elevate index addition on non-critical table beyond high', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Schema::table("products", function (Blueprint $table) { $table->index("status"); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelTableMigrationRule(['users']))->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'existing table'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::HIGH)
        ->and($hit->description)->not->toContain('critical table');
});

it('does not elevate index addition on critical table via schema create', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Schema::create("users", function (Blueprint $table) { $table->id(); $table->index("name"); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelTableMigrationRule(['users']))->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'index'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::INFO);
});

// --- Non-migration files ---

it('ignores non-migration files', function () use ($stub) {
    $old = $stub('');
    $new = $stub('Schema::table("users", function (Blueprint $table) { $table->index("email"); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $file = new FileDiff('app/Models/User.php', 'app/Models/User.php', FileStatus::MODIFIED);
    $changes = (new LaravelTableMigrationRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
