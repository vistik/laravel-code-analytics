<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelDataMigrationRule;

$migrationFile = fn (string $name = 'test_migration') => new FileDiff(
    "database/migrations/2024_01_01_000000_{$name}.php",
    "database/migrations/2024_01_01_000000_{$name}.php",
    FileStatus::MODIFIED,
);

$stub = fn (string $upBody) => '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class TestMigration extends Migration {
    public function up() { '.$upBody.' }
    public function down() { }
}';

// --- DB direct data methods ---

it('flags DB::insert() as critical', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('DB::insert("insert into users (name) values (?)", ["John"]);');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'DB::insert()'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::VERY_HIGH)
        ->and($hit->category)->toBe(ChangeCategory::LARAVEL);
});

it('flags DB::update() as critical', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('DB::update("update users set active = 1 where id = ?", [1]);');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'DB::update()'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::VERY_HIGH);
});

it('flags DB::delete() as critical', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('DB::delete("delete from users where id = ?", [1]);');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'DB::delete()'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::VERY_HIGH);
});

it('flags DB::statement() as critical', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('DB::statement("INSERT INTO settings (key, value) VALUES (\'foo\', \'bar\')");');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'DB::statement()'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::VERY_HIGH);
});

it('flags DB::unprepared() as critical', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('DB::unprepared("UPDATE users SET legacy = 1");');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'DB::unprepared()'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::VERY_HIGH);
});

// --- DB::table() query builder ---

it('flags DB::table() as warning with table name', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('DB::table("users")->insert(["name" => "Admin"]);');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'DB::table()'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::MEDIUM)
        ->and($hit->description)->toContain("'users'");
});

it('flags DB::table() without a string argument', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('DB::table($tableName)->update(["active" => 0]);');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'DB::table()'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::MEDIUM);
});

it('includes line number for DB::table() hit', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('DB::table("roles")->insert(["name" => "editor"]);');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'DB::table()'));

    expect($hit)->not->toBeNull()
        ->and($hit->line)->toBeInt();
});

// --- Eloquent model DML ---

it('flags Eloquent model create() as critical', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('User::create(["name" => "Admin", "email" => "admin@example.com"]);');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'User::create()'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::VERY_HIGH)
        ->and($hit->category)->toBe(ChangeCategory::LARAVEL);
});

it('flags Eloquent model insert() as critical', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Role::insert([["name" => "editor"], ["name" => "viewer"]]);');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'Role::insert()'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::VERY_HIGH);
});

it('flags Eloquent model destroy() as critical', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Permission::destroy([1, 2, 3]);');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'Permission::destroy()'));

    expect($hit)->not->toBeNull()
        ->and($hit->severity)->toBe(Severity::VERY_HIGH);
});

it('includes the class name in the Eloquent description', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Setting::firstOrCreate(["key" => "theme"], ["value" => "dark"]);');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    $hit = collect($changes)->first(fn ($c) => str_contains($c->description, 'Setting'));

    expect($hit)->not->toBeNull()
        ->and($hit->description)->toContain('firstOrCreate');
});

// --- Non-migration files are ignored ---

it('ignores non-migration files', function () use ($stub) {
    $old = $stub('');
    $new = $stub('DB::table("users")->insert(["name" => "Admin"]);');

    $comparison = (new AstComparer)->compare($old, $new);
    $file = new FileDiff('app/Services/UserService.php', 'app/Services/UserService.php', FileStatus::MODIFIED);
    $changes = (new LaravelDataMigrationRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});

// --- Schema-only migrations are not flagged ---

it('does not flag schema-only migrations', function () use ($migrationFile, $stub) {
    $old = $stub('');
    $new = $stub('Schema::table("users", function (Blueprint $table) { $table->string("bio")->nullable(); });');

    $comparison = (new AstComparer)->compare($old, $new);
    $changes = (new LaravelDataMigrationRule)->analyze($migrationFile(), $comparison);

    expect($changes)->toBeEmpty();
});
