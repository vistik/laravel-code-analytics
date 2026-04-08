<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelRedirectRule;

it('detects to_route() name changed', function () {
    $old = <<<'PHP'
    <?php
    class AuthController {
        public function authenticate() {
            return to_route('auth.workos.authenticate', ['foo' => 'bar']);
        }
    }
    PHP;

    $new = <<<'PHP'
    <?php
    class AuthController {
        public function authenticate() {
            return to_route('authenticate', ['foo' => 'bar']);
        }
    }
    PHP;

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Controllers/AuthController.php', 'app/Http/Controllers/AuthController.php', FileStatus::MODIFIED);

    $changes = (new LaravelRedirectRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::HIGH)
        ->and($changes[0]->description)->toContain('auth.workos.authenticate')
        ->and($changes[0]->description)->toContain('authenticate');
});

it('detects to_route() added', function () {
    $old = <<<'PHP'
    <?php
    class AuthController {
        public function logout() {
            return response()->noContent();
        }
    }
    PHP;

    $new = <<<'PHP'
    <?php
    class AuthController {
        public function logout() {
            return to_route('home');
        }
    }
    PHP;

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Controllers/AuthController.php', 'app/Http/Controllers/AuthController.php', FileStatus::MODIFIED);

    $changes = (new LaravelRedirectRule)->analyze($file, $comparison);

    $change = collect($changes)->first(fn ($c) => str_contains($c->description, 'Redirect to route added'));

    expect($change)->not->toBeNull()
        ->and($change->severity)->toBe(Severity::MEDIUM)
        ->and($change->description)->toContain('home');
});

it('detects view() name changed', function () {
    $old = <<<'PHP'
    <?php
    class PageController {
        public function show() {
            return view('auth.login');
        }
    }
    PHP;

    $new = <<<'PHP'
    <?php
    class PageController {
        public function show() {
            return view('auth.sso-login');
        }
    }
    PHP;

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Controllers/PageController.php', 'app/Http/Controllers/PageController.php', FileStatus::MODIFIED);

    $changes = (new LaravelRedirectRule)->analyze($file, $comparison);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->severity)->toBe(Severity::MEDIUM)
        ->and($changes[0]->description)->toContain('auth.login')
        ->and($changes[0]->description)->toContain('auth.sso-login');
});

it('does not flag unchanged to_route() calls', function () {
    $old = <<<'PHP'
    <?php
    class AuthController {
        public function authenticate() {
            return to_route('home');
        }
    }
    PHP;

    $new = <<<'PHP'
    <?php
    class AuthController {
        public function authenticate() {
            return to_route('home');
        }
    }
    PHP;

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Http/Controllers/AuthController.php', 'app/Http/Controllers/AuthController.php', FileStatus::MODIFIED);

    $changes = (new LaravelRedirectRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
