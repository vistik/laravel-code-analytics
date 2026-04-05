<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelMigrationRule;

it('detects schema create', function () {
    $old = '<?php use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema; class CreateUsersTable extends Migration { public function up() { } public function down() { } }';
    $new = '<?php use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema; class CreateUsersTable extends Migration { public function up() { Schema::create("users", function (Blueprint $table) { $table->id(); }); } public function down() { Schema::dropIfExists("users"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('database/migrations/2024_01_01_000000_create_users_table.php', 'database/migrations/2024_01_01_000000_create_users_table.php', FileStatus::MODIFIED);

    $changes = (new LaravelMigrationRule)->analyze($file, $comparison);

    $schemaCreate = collect($changes)->first(fn ($c) => str_contains($c->description, 'creates table'));

    expect($schemaCreate)->not->toBeNull()
        ->and($schemaCreate->category)->toBe(ChangeCategory::LARAVEL)
        ->and($schemaCreate->severity)->toBe(Severity::MEDIUM)
        ->and($schemaCreate->description)->toContain('users');
});

it('detects schema drop', function () {
    $old = '<?php use Illuminate\Database\Migrations\Migration; use Illuminate\Support\Facades\Schema; class DropUsersTable extends Migration { public function up() { } public function down() { } }';
    $new = '<?php use Illuminate\Database\Migrations\Migration; use Illuminate\Support\Facades\Schema; class DropUsersTable extends Migration { public function up() { Schema::dropIfExists("users"); } public function down() { } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('database/migrations/2024_01_01_000000_drop_users_table.php', 'database/migrations/2024_01_01_000000_drop_users_table.php', FileStatus::MODIFIED);

    $changes = (new LaravelMigrationRule)->analyze($file, $comparison);

    $schemaDrop = collect($changes)->first(fn ($c) => str_contains($c->description, 'drops table'));

    expect($schemaDrop)->not->toBeNull()
        ->and($schemaDrop->category)->toBe(ChangeCategory::LARAVEL)
        ->and($schemaDrop->severity)->toBe(Severity::VERY_HIGH)
        ->and($schemaDrop->description)->toContain('users');
});

it('detects column addition', function () {
    $old = '<?php use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema; class AddNameToUsersTable extends Migration { public function up() { Schema::table("users", function (Blueprint $table) { $table->id(); }); } public function down() { } }';
    $new = '<?php use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema; class AddNameToUsersTable extends Migration { public function up() { Schema::table("users", function (Blueprint $table) { $table->id(); $table->string("name"); }); } public function down() { } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('database/migrations/2024_01_01_000001_add_name_to_users_table.php', 'database/migrations/2024_01_01_000001_add_name_to_users_table.php', FileStatus::MODIFIED);

    $changes = (new LaravelMigrationRule)->analyze($file, $comparison);

    $columnAdd = collect($changes)->first(fn ($c) => str_contains($c->description, 'adds column') && str_contains($c->description, 'string'));

    expect($columnAdd)->not->toBeNull()
        ->and($columnAdd->category)->toBe(ChangeCategory::LARAVEL)
        ->and($columnAdd->severity)->toBe(Severity::INFO);
});

it('detects column drop', function () {
    $old = '<?php use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema; class DropNameFromUsersTable extends Migration { public function up() { Schema::table("users", function (Blueprint $table) { $table->id(); }); } public function down() { } }';
    $new = '<?php use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema; class DropNameFromUsersTable extends Migration { public function up() { Schema::table("users", function (Blueprint $table) { $table->id(); $table->dropColumn("name"); }); } public function down() { } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('database/migrations/2024_01_01_000002_drop_name_from_users_table.php', 'database/migrations/2024_01_01_000002_drop_name_from_users_table.php', FileStatus::MODIFIED);

    $changes = (new LaravelMigrationRule)->analyze($file, $comparison);

    $columnDrop = collect($changes)->first(fn ($c) => str_contains($c->description, 'drops column'));

    expect($columnDrop)->not->toBeNull()
        ->and($columnDrop->category)->toBe(ChangeCategory::LARAVEL)
        ->and($columnDrop->severity)->toBe(Severity::VERY_HIGH);
});

it('ignores non-migration files', function () {
    $old = '<?php use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema; class CreateUsersTable extends Migration { public function up() { } public function down() { } }';
    $new = '<?php use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema; class CreateUsersTable extends Migration { public function up() { Schema::create("users", function (Blueprint $table) { $table->id(); }); } public function down() { Schema::dropIfExists("users"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Models/User.php', 'app/Models/User.php', FileStatus::MODIFIED);

    $changes = (new LaravelMigrationRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
