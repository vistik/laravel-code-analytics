<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelServiceContainerRule;

it('detects new binding in register method', function () {
    $old = '<?php namespace App\Providers; use Illuminate\Support\ServiceProvider; class AppServiceProvider extends ServiceProvider { public function register() { $this->app->singleton("foo", function () { return new Foo; }); } public function boot() { } }';
    $new = '<?php namespace App\Providers; use Illuminate\Support\ServiceProvider; class AppServiceProvider extends ServiceProvider { public function register() { $this->app->singleton("foo", function () { return new Foo; }); $this->app->bind("bar", function () { return new Bar; }); } public function boot() { } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Providers/AppServiceProvider.php', 'app/Providers/AppServiceProvider.php', FileStatus::MODIFIED);

    $changes = (new LaravelServiceContainerRule)->analyze($file, $comparison);

    $bindingAdded = collect($changes)->first(fn ($c) => str_contains($c->description, 'binding added') && str_contains($c->description, 'bar'));

    expect($bindingAdded)->not->toBeNull()
        ->and($bindingAdded->category)->toBe(ChangeCategory::LARAVEL)
        ->and($bindingAdded->severity)->toBe(Severity::MEDIUM)
        ->and($bindingAdded->description)->toContain('bind');
});

it('detects boot method changed', function () {
    $old = '<?php namespace App\Providers; use Illuminate\Support\ServiceProvider; class AppServiceProvider extends ServiceProvider { public function register() { } public function boot() { view()->share("key", "value"); } }';
    $new = '<?php namespace App\Providers; use Illuminate\Support\ServiceProvider; class AppServiceProvider extends ServiceProvider { public function register() { } public function boot() { view()->share("key", "new-value"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Providers/AppServiceProvider.php', 'app/Providers/AppServiceProvider.php', FileStatus::MODIFIED);

    $changes = (new LaravelServiceContainerRule)->analyze($file, $comparison);

    $bootChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'boot()'));

    expect($bootChange)->not->toBeNull()
        ->and($bootChange->category)->toBe(ChangeCategory::LARAVEL)
        ->and($bootChange->severity)->toBe(Severity::MEDIUM)
        ->and($bootChange->description)->toContain('AppServiceProvider::boot');
});

it('detects bootstrap providers change', function () {
    $old = '<?php return [App\Providers\AppServiceProvider::class];';
    $new = '<?php return [App\Providers\AppServiceProvider::class, App\Providers\EventServiceProvider::class];';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('bootstrap/providers.php', 'bootstrap/providers.php', FileStatus::MODIFIED);

    $changes = (new LaravelServiceContainerRule)->analyze($file, $comparison);

    $providerChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'provider registration changed'));

    expect($providerChange)->not->toBeNull()
        ->and($providerChange->category)->toBe(ChangeCategory::LARAVEL)
        ->and($providerChange->severity)->toBe(Severity::MEDIUM);
});

it('ignores non-provider files', function () {
    $old = '<?php namespace App\Services; use Illuminate\Support\ServiceProvider; class AppServiceProvider extends ServiceProvider { public function register() { $this->app->singleton("foo", function () { return new Foo; }); } public function boot() { } }';
    $new = '<?php namespace App\Services; use Illuminate\Support\ServiceProvider; class AppServiceProvider extends ServiceProvider { public function register() { $this->app->singleton("foo", function () { return new Foo; }); $this->app->bind("bar", function () { return new Bar; }); } public function boot() { view()->share("key", "value"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Services/AppServiceProvider.php', 'app/Services/AppServiceProvider.php', FileStatus::MODIFIED);

    $changes = (new LaravelServiceContainerRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
