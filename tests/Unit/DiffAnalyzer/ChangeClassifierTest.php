<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\ChangeClassifier;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;

function classify(string $old, string $new, string $path = 'src/Foo.php'): array
{
    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff($path, $path, FileStatus::MODIFIED);

    return (new ChangeClassifier($comparer))->classify($file, $comparison, $new)->changes;
}

it('assigns a line number to every finding', function () {
    $old = <<<'PHP'
        <?php

        namespace App\Services;

        use App\Models\User;

        class PaymentService
        {
            public function charge(int $amount): bool
            {
                return $amount >= 0;
            }
        }
        PHP;

    $new = <<<'PHP'
        <?php

        namespace App\Services;

        use App\Models\User;
        use App\Models\Invoice;

        class PaymentService
        {
            public function charge(int $amount): bool
            {
                return $amount > 0 || $amount === 0;
            }
        }
        PHP;

    $changes = classify($old, $new);

    expect($changes)->not->toBeEmpty();

    foreach ($changes as $change) {
        expect($change->line)->not->toBeNull()
            ->and($change->line)->toBeGreaterThanOrEqual(1);
    }
});

it('anchors file-level findings to line 1', function () {
    // A file with no class declaration; the import change is file-level.
    $old = '<?php use App\Models\User;';
    $new = '<?php use App\Models\User; use App\Models\Post;';

    $changes = classify($old, $new, 'routes/web.php');

    expect($changes)->not->toBeEmpty();

    foreach ($changes as $change) {
        expect($change->line)->toBe(1);
    }
});

it('anchors class member findings without a precise line to the class declaration', function () {
    // The class is declared on line 7; the assignment-target change carries no
    // intrinsic line, so it must fall back to the class declaration line.
    $old = <<<'PHP'
        <?php

        namespace App\Services;

        use App\Models\User;

        class Ledger
        {
            public function record(): void
            {
                $total = 0;
            }
        }
        PHP;

    $new = <<<'PHP'
        <?php

        namespace App\Services;

        use App\Models\User;

        class Ledger
        {
            public function record(): void
            {
                $total = 0;
                $running = 0;
            }
        }
        PHP;

    $changes = classify($old, $new);

    $assignment = array_values(array_filter(
        $changes,
        fn ($c) => str_contains($c->description, 'assignment target'),
    ));

    expect($assignment)->not->toBeEmpty()
        ->and($assignment[0]->line)->toBe(7);
});
