<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelLivewireRule;

it('detects public property added', function () {
    $old = '<?php namespace App\Livewire; use Livewire\Component; class Counter extends Component { public int $count = 0; public function increment() { $this->count++; } public function render() { return view("livewire.counter"); } }';
    $new = '<?php namespace App\Livewire; use Livewire\Component; class Counter extends Component { public int $count = 0; public string $name = ""; public function increment() { $this->count++; } public function render() { return view("livewire.counter"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Livewire/Counter.php', 'app/Livewire/Counter.php', FileStatus::MODIFIED);

    $changes = (new LaravelLivewireRule)->analyze($file, $comparison);

    $propAdded = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'public property added'),
    ));

    expect($propAdded)->toHaveCount(1)
        ->and($propAdded[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($propAdded[0]->severity)->toBe(Severity::MEDIUM)
        ->and($propAdded[0]->description)->toContain('$name');
});

it('detects public property removed', function () {
    $old = '<?php namespace App\Livewire; use Livewire\Component; class Counter extends Component { public int $count = 0; public string $name = ""; public function increment() { $this->count++; } public function render() { return view("livewire.counter"); } }';
    $new = '<?php namespace App\Livewire; use Livewire\Component; class Counter extends Component { public int $count = 0; public function increment() { $this->count++; } public function render() { return view("livewire.counter"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Livewire/Counter.php', 'app/Livewire/Counter.php', FileStatus::MODIFIED);

    $changes = (new LaravelLivewireRule)->analyze($file, $comparison);

    $propRemoved = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'public property removed'),
    ));

    expect($propRemoved)->toHaveCount(1)
        ->and($propRemoved[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($propRemoved[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($propRemoved[0]->description)->toContain('$name');
});

it('detects action method removed', function () {
    $old = '<?php namespace App\Livewire; use Livewire\Component; class Counter extends Component { public int $count = 0; public function increment() { $this->count++; } public function decrement() { $this->count--; } public function render() { return view("livewire.counter"); } }';
    $new = '<?php namespace App\Livewire; use Livewire\Component; class Counter extends Component { public int $count = 0; public function increment() { $this->count++; } public function render() { return view("livewire.counter"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Livewire/Counter.php', 'app/Livewire/Counter.php', FileStatus::MODIFIED);

    $changes = (new LaravelLivewireRule)->analyze($file, $comparison);

    $actionRemoved = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'action removed') && str_contains($c->description, 'decrement'),
    ));

    expect($actionRemoved)->toHaveCount(1)
        ->and($actionRemoved[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($actionRemoved[0]->severity)->toBe(Severity::VERY_HIGH)
        ->and($actionRemoved[0]->description)->toContain('wire:click');
});

it('detects mount parameter change', function () {
    $old = '<?php namespace App\Livewire; use Livewire\Component; class Counter extends Component { public int $count = 0; public function mount(int $initial) { $this->count = $initial; } public function render() { return view("livewire.counter"); } }';
    $new = '<?php namespace App\Livewire; use Livewire\Component; class Counter extends Component { public int $count = 0; public function mount(int $initial, string $label = "") { $this->count = $initial; } public function render() { return view("livewire.counter"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Livewire/Counter.php', 'app/Livewire/Counter.php', FileStatus::MODIFIED);

    $changes = (new LaravelLivewireRule)->analyze($file, $comparison);

    $mountChanges = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'mount() parameters changed'),
    ));

    expect($mountChanges)->toHaveCount(1)
        ->and($mountChanges[0]->category)->toBe(ChangeCategory::LARAVEL)
        ->and($mountChanges[0]->severity)->toBe(Severity::MEDIUM);
});

it('ignores non-livewire files', function () {
    $old = '<?php namespace App\Services; class CounterService { public int $count = 0; public function increment() { $this->count++; } }';
    $new = '<?php namespace App\Services; class CounterService { public int $count = 0; public string $name = ""; public function increment() { $this->count++; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Services/CounterService.php', 'app/Services/CounterService.php', FileStatus::MODIFIED);

    $changes = (new LaravelLivewireRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
