<?php

use Vistik\LaravelCodeAnalytics\Actions\AnalyzeCode;
use Vistik\LaravelCodeAnalytics\Enums\GraphLayout;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

it('fails when the path is not a git repo', function () {
    $this->artisan('code:analyze', ['repo-path' => '/tmp'])
        ->expectsOutputToContain('Not a git repository')
        ->assertFailed();
});

it('fails when the base branch cannot be resolved', function () {
    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->andThrow(new RuntimeException('Could not resolve base: nonexistent-branch'));
    });

    $this->artisan('code:analyze', ['--base' => 'nonexistent-branch'])
        ->expectsOutputToContain('Could not resolve base')
        ->assertFailed();
});

it('returns success with no output when no changes found', function () {
    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze')
        ->assertSuccessful();
});

// ── --config error handling ──────────────────────────────────────────────────

it('fails when the config file does not exist', function () {
    $this->artisan('code:analyze', ['--config' => '/tmp/nonexistent-vast-config.json'])
        ->expectsOutputToContain('Config file not found')
        ->assertFailed();
});

it('fails when the config file contains invalid json', function () {
    $path = tempnam(sys_get_temp_dir(), 'vast-cfg-');
    file_put_contents($path, 'not json at all');

    $this->artisan('code:analyze', ['--config' => $path])
        ->expectsOutputToContain('Invalid JSON')
        ->assertFailed();

    unlink($path);
});

// ── config-driven inputs (no file_groups = container mock still applies) ─────

it('uses base branch from config when not provided on cli', function () {
    $path = tempnam(sys_get_temp_dir(), 'vast-cfg-');
    file_put_contents($path, json_encode(['base' => 'develop']));

    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($repoPath, $outputPath, $baseBranch) => $baseBranch === 'develop')
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze', ['--config' => $path])->assertSuccessful();

    unlink($path);
});

it('cli --base takes precedence over config base', function () {
    $path = tempnam(sys_get_temp_dir(), 'vast-cfg-');
    file_put_contents($path, json_encode(['base' => 'develop']));

    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($repoPath, $outputPath, $baseBranch) => $baseBranch === 'staging')
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze', ['--config' => $path, '--base' => 'staging'])->assertSuccessful();

    unlink($path);
});

it('uses output path from config when not provided on cli', function () {
    $path = tempnam(sys_get_temp_dir(), 'vast-cfg-');
    file_put_contents($path, json_encode(['output' => '/tmp/my-report.html']));

    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($repoPath, $outputPath) => $outputPath === '/tmp/my-report.html')
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze', ['--config' => $path])->assertSuccessful();

    unlink($path);
});

it('uses repo_path from config when not provided on cli', function () {
    $path = tempnam(sys_get_temp_dir(), 'vast-cfg-');
    file_put_contents($path, json_encode(['repo_path' => '/tmp']));

    // /tmp is not a git repo, so this should reach the git check and fail
    $this->artisan('code:analyze', ['--config' => $path])
        ->expectsOutputToContain('Not a git repository')
        ->assertFailed();

    unlink($path);
});

// ── file_groups in config bypasses container mock ────────────────────────────

it('applies file_groups from config using a custom group resolver', function () {
    $path = tempnam(sys_get_temp_dir(), 'vast-cfg-');
    file_put_contents($path, json_encode([
        'file_groups' => ['test' => ['^tests/'], 'model' => ['app/Models/']],
    ]));

    // file_groups triggers direct AnalyzeCode instantiation (bypasses container mock),
    // so verify via a non-git repo path that execution reached the git check.
    $this->artisan('code:analyze', ['repo-path' => '/tmp', '--config' => $path])
        ->expectsOutputToContain('Not a git repository')
        ->assertFailed();

    unlink($path);
});

// ── --title option ───────────────────────────────────────────────────────────

it('passes custom title from --title option to execute', function () {
    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($repoPath, $outputPath, $baseBranch, $prUrl, $all, $title) => $title === 'My Custom Title')
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze', ['--title' => 'My Custom Title'])->assertSuccessful();
});

it('passes custom title from config to execute', function () {
    $path = tempnam(sys_get_temp_dir(), 'vast-cfg-');
    file_put_contents($path, json_encode(['title' => 'Config Title']));

    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($repoPath, $outputPath, $baseBranch, $prUrl, $all, $title) => $title === 'Config Title')
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze', ['--config' => $path])->assertSuccessful();

    unlink($path);
});

it('cli --title takes precedence over config title', function () {
    $path = tempnam(sys_get_temp_dir(), 'vast-cfg-');
    file_put_contents($path, json_encode(['title' => 'Config Title']));

    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($repoPath, $outputPath, $baseBranch, $prUrl, $all, $title) => $title === 'CLI Title')
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze', ['--config' => $path, '--title' => 'CLI Title'])->assertSuccessful();

    unlink($path);
});

// ── --view option ────────────────────────────────────────────────────────────

it('passes custom view from --view option to execute', function () {
    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($repoPath, $outputPath, $baseBranch, $prUrl, $all, $title, $view) => $view === GraphLayout::Tree)
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze', ['--view' => 'tree'])->assertSuccessful();
});

it('passes custom view from config to execute', function () {
    $path = tempnam(sys_get_temp_dir(), 'vast-cfg-');
    file_put_contents($path, json_encode(['view' => 'arch']));

    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($repoPath, $outputPath, $baseBranch, $prUrl, $all, $title, $view) => $view === GraphLayout::Arch)
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze', ['--config' => $path])->assertSuccessful();

    unlink($path);
});

it('cli --view takes precedence over config view', function () {
    $path = tempnam(sys_get_temp_dir(), 'vast-cfg-');
    file_put_contents($path, json_encode(['view' => 'cake']));

    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($repoPath, $outputPath, $baseBranch, $prUrl, $all, $title, $view) => $view === GraphLayout::Grouped)
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze', ['--config' => $path, '--view' => 'grouped'])->assertSuccessful();

    unlink($path);
});

it('uses default resolver when config has no file_groups key', function () {
    $path = tempnam(sys_get_temp_dir(), 'vast-cfg-');
    file_put_contents($path, json_encode(['base' => 'main']));

    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze', ['--config' => $path])->assertSuccessful();

    unlink($path);
});

// ── --full-files option ──────────────────────────────────────────────────────

it('passes includeFileContents=true when --full-files flag is set', function () {
    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn (...$args) => $args[13] === true)
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze', ['--full-files' => true])->assertSuccessful();
});

it('passes includeFileContents=false by default', function () {
    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn (...$args) => $args[13] === false)
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze')->assertSuccessful();
});

it('passes includeFileContents=true from config full_files key', function () {
    $path = tempnam(sys_get_temp_dir(), 'vast-cfg-');
    file_put_contents($path, json_encode(['full_files' => true]));

    $this->mock(AnalyzeCode::class, function ($mock) {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn (...$args) => $args[13] === true)
            ->andReturn(['files' => [], 'risk' => new RiskScore(0)]);
    });

    $this->artisan('code:analyze', ['--config' => $path])->assertSuccessful();

    unlink($path);
});
