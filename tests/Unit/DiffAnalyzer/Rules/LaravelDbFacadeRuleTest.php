<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelDbFacadeRule;

it('detects a DB::table()->select() chain added', function () {
    $old = '<?php class Repo { public function get() { return []; } }';
    $new = '<?php class Repo { public function get() { return DB::table("users")->select(["id", "name"])->get(); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Repo.php', 'app/Repo.php', FileStatus::MODIFIED);

    $changes = (new LaravelDbFacadeRule)->analyze($file, $comparison);

    $added = collect($changes)->first(fn ($c) => $c->category === ChangeCategory::DB_QUERY_ADDED);

    expect($added)->not->toBeNull()
        ->and($added->description)->toContain('DB::')
        ->and($added->severity)->toBe(Severity::LOW);
});

it('detects a DB::insert() added', function () {
    $old = '<?php class Repo { public function store() {} }';
    $new = '<?php class Repo { public function store() { DB::insert("insert into users (name) values (?)", ["John"]); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Repo.php', 'app/Repo.php', FileStatus::MODIFIED);

    $changes = (new LaravelDbFacadeRule)->analyze($file, $comparison);

    $added = collect($changes)->first(fn ($c) => $c->category === ChangeCategory::DB_QUERY_ADDED);

    expect($added)->not->toBeNull()
        ->and($added->description)->toContain('insert')
        ->and($added->severity)->toBe(Severity::MEDIUM);
});

it('detects a DB::delete() added with HIGH severity', function () {
    $old = '<?php class Repo { public function remove() {} }';
    $new = '<?php class Repo { public function remove() { DB::delete("delete from users where id = ?", [$id]); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Repo.php', 'app/Repo.php', FileStatus::MODIFIED);

    $changes = (new LaravelDbFacadeRule)->analyze($file, $comparison);

    $added = collect($changes)->first(fn ($c) => $c->category === ChangeCategory::DB_QUERY_ADDED);

    expect($added)->not->toBeNull()
        ->and($added->description)->toContain('delete')
        ->and($added->severity)->toBe(Severity::HIGH);
});

it('detects a DB query removed', function () {
    $old = '<?php class Repo { public function all() { return DB::select("select * from users"); } }';
    $new = '<?php class Repo { public function all() { return []; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Repo.php', 'app/Repo.php', FileStatus::MODIFIED);

    $changes = (new LaravelDbFacadeRule)->analyze($file, $comparison);

    $removed = collect($changes)->first(fn ($c) => $c->category === ChangeCategory::DB_QUERY_REMOVED);

    expect($removed)->not->toBeNull()
        ->and($removed->description)->toContain('select')
        ->and($removed->severity)->toBe(Severity::LOW);
});

it('detects a DB query modified when args change', function () {
    $old = '<?php class Repo { public function all() { return DB::select("select * from users"); } }';
    $new = '<?php class Repo { public function all() { return DB::select("select * from admins"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Repo.php', 'app/Repo.php', FileStatus::MODIFIED);

    $changes = (new LaravelDbFacadeRule)->analyze($file, $comparison);

    $modified = collect($changes)->first(fn ($c) => $c->category === ChangeCategory::DB_QUERY_MODIFIED);

    expect($modified)->not->toBeNull()
        ->and($modified->description)->toContain('select');
});

it('detects DB::connection()->table()->select() chain added', function () {
    $old = '<?php class CacheRepo { public function all() { return []; } }';
    $new = '<?php class CacheRepo { public function all() { return DB::connection($this->connection)->table("caches")->select(["key", "value"])->get(); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/CacheRepo.php', 'app/CacheRepo.php', FileStatus::MODIFIED);

    $changes = (new LaravelDbFacadeRule)->analyze($file, $comparison);

    expect($changes)->not->toBeEmpty();

    $addedCategories = collect($changes)->where('category', ChangeCategory::DB_QUERY_ADDED);
    expect($addedCategories)->not->toBeEmpty();
});

it('detects DB::transaction() added with MEDIUM severity', function () {
    $old = '<?php class Repo { public function transfer() {} }';
    $new = '<?php class Repo { public function transfer() { DB::transaction(function () { DB::table("accounts")->update(["balance" => 0]); }); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Repo.php', 'app/Repo.php', FileStatus::MODIFIED);

    $changes = (new LaravelDbFacadeRule)->analyze($file, $comparison);

    $tx = collect($changes)->first(fn ($c) => str_contains($c->description, 'transaction'));

    expect($tx)->not->toBeNull()
        ->and($tx->category)->toBe(ChangeCategory::DB_QUERY_ADDED)
        ->and($tx->severity)->toBe(Severity::MEDIUM);
});

it('ignores non-DB static calls', function () {
    $old = '<?php class Repo { public function run() {} }';
    $new = '<?php class Repo { public function run() { Cache::put("key", "val", 60); Redis::set("k", "v"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Repo.php', 'app/Repo.php', FileStatus::MODIFIED);

    $changes = (new LaravelDbFacadeRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
