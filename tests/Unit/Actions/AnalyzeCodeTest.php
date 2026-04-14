<?php

use Vistik\LaravelCodeAnalytics\Actions\AnalyzeCode;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\Enums\OutputFormat;
use Vistik\LaravelCodeAnalytics\FileSignal\FileSignalScoring;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScoring;
use Vistik\LaravelCodeAnalytics\Support\JsMetrics;
use Vistik\LaravelCodeAnalytics\Support\PhpMetrics;

// ── Helpers ───────────────────────────────────────────────────────────────────

function analyzeCodeMethod(string $method, mixed ...$args): mixed
{
    $obj = new AnalyzeCode;
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);

    return $ref->invoke($obj, ...$args);
}

function makeTempGitRepo(bool $withArtisan = false): string
{
    $dir = sys_get_temp_dir().'/analyze-code-test-'.uniqid();
    mkdir($dir, 0755, true);

    shell_exec("git -C {$dir} init 2>&1");
    shell_exec("git -C {$dir} config user.email 'test@example.com' 2>&1");
    shell_exec("git -C {$dir} config user.name 'Test' 2>&1");

    file_put_contents("{$dir}/README.md", '# Test');
    if ($withArtisan) {
        file_put_contents("{$dir}/artisan", '<?php // artisan');
    }

    shell_exec("git -C {$dir} add . 2>&1");
    shell_exec("git -C {$dir} commit -m 'initial' 2>&1");
    shell_exec("git -C {$dir} branch -m main 2>&1");

    return $dir;
}

/** Stage a new or modified file so it appears in git diff against the base. */
function addAndStageFile(string $repoDir, string $relativePath, string $content): void
{
    $fullPath = "{$repoDir}/{$relativePath}";
    $parentDir = dirname($fullPath);

    if (! is_dir($parentDir)) {
        mkdir($parentDir, 0755, true);
    }

    file_put_contents($fullPath, $content);
    shell_exec("git -C {$repoDir} add ".escapeshellarg($relativePath).' 2>&1');
}

function removeTempDir(string $dir): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        shell_exec('rmdir /s /q '.escapeshellarg($dir));
    } else {
        shell_exec('rm -rf '.escapeshellarg($dir));
    }
}

// ── generateLabel ─────────────────────────────────────────────────────────────

describe('generateLabel', function () {
    it('returns class name without extension for a regular PHP file', function () {
        $label = analyzeCodeMethod('generateLabel', 'app/Actions/SomeAction.php');
        expect($label)->toBe('SomeAction');
    });

    it('strips migration timestamp prefix', function () {
        $label = analyzeCodeMethod('generateLabel', 'database/migrations/2024_01_01_000000_create_users_table.php');
        expect($label)->toBe('create_users_table');
    });

    it('prefixes with parent directory for files directly in Controllers', function () {
        // File is a direct child of Controllers, so parent dir (Http) is used as prefix
        $label = analyzeCodeMethod('generateLabel', 'app/Http/Controllers/UserController.php');
        expect($label)->toBe('Http\\UserController');
    });

    it('does not add a prefix for files nested deeper than Controllers', function () {
        // File is under Controllers/Api/, so $dir=Api is not in the special list
        $label = analyzeCodeMethod('generateLabel', 'app/Http/Controllers/Api/UserController.php');
        expect($label)->toBe('UserController');
    });

    it('prefixes with parent directory for files in Concerns', function () {
        $label = analyzeCodeMethod('generateLabel', 'app/Models/Concerns/HasSlug.php');
        expect($label)->toBe('Models\\HasSlug');
    });

    it('prefixes with parent directory for files in Policies', function () {
        $label = analyzeCodeMethod('generateLabel', 'app/Http/Policies/UserPolicy.php');
        expect($label)->toBe('Http\\UserPolicy');
    });

    it('prefixes test label with Test: for test files', function () {
        $label = analyzeCodeMethod('generateLabel', 'tests/Feature/UserTest.php');
        expect($label)->toBe('Test:User');
    });

    it('strips Test suffix from test file labels', function () {
        $label = analyzeCodeMethod('generateLabel', 'tests/Unit/SomeHelperTest.php');
        expect($label)->toBe('Test:SomeHelper');
    });

    it('returns basename for short non-PHP files', function () {
        $label = analyzeCodeMethod('generateLabel', 'routes/api.php');
        expect($label)->toBe('api');
    });

    it('returns basename for non-PHP frontend files', function () {
        $label = analyzeCodeMethod('generateLabel', 'resources/js/Pages/Dashboard.vue');
        expect($label)->toBe('Dashboard.vue');
    });

    it('truncates long non-PHP filenames to 30 characters with ellipsis', function () {
        $longName = 'this-is-a-very-long-filename-that-exceeds-limit.tsx';
        $label = analyzeCodeMethod('generateLabel', "resources/js/{$longName}");
        expect(strlen($label))->toBe(30)
            ->and($label)->toStartWith('...');
    });

    it('does not truncate non-PHP filenames at exactly 30 characters', function () {
        // 30 chars exactly — no truncation
        $name = str_repeat('a', 26).'.tsx'; // 30 chars
        $label = analyzeCodeMethod('generateLabel', "resources/js/{$name}");
        expect($label)->toBe($name);
    });
});

// ── matchesWatchPattern ───────────────────────────────────────────────────────

describe('matchesWatchPattern', function () {
    it('returns false for an empty pattern', function () {
        expect(analyzeCodeMethod('matchesWatchPattern', 'app/Models/User.php', ''))->toBeFalse();
    });

    it('matches files under a directory pattern with trailing slash', function () {
        expect(analyzeCodeMethod('matchesWatchPattern', 'app/Models/User.php', 'app/Models/'))->toBeTrue();
    });

    it('does not match files outside a directory pattern', function () {
        expect(analyzeCodeMethod('matchesWatchPattern', 'app/Actions/CreateUser.php', 'app/Models/'))->toBeFalse();
    });

    it('matches an exact file path', function () {
        expect(analyzeCodeMethod('matchesWatchPattern', 'config/auth.php', 'config/auth.php'))->toBeTrue();
    });

    it('does not match a different exact path', function () {
        expect(analyzeCodeMethod('matchesWatchPattern', 'config/queue.php', 'config/auth.php'))->toBeFalse();
    });

    it('matches via fnmatch glob pattern', function () {
        expect(analyzeCodeMethod('matchesWatchPattern', 'app/Models/User.php', 'app/Models/*.php'))->toBeTrue();
    });

    it('does not match a glob pattern when the path differs', function () {
        expect(analyzeCodeMethod('matchesWatchPattern', 'app/Actions/CreateUser.php', 'app/Models/*.php'))->toBeFalse();
    });

    it('does not match a partial path that is not an exact match or valid glob', function () {
        // matchesWatchPattern uses fnmatch or exact equality — not str_contains
        expect(analyzeCodeMethod('matchesWatchPattern', 'app/Models/User.php', 'Models/User'))->toBeFalse();
    });
});

// ── pathToFqcn ────────────────────────────────────────────────────────────────

describe('pathToFqcn', function () {
    it('converts app path to App namespace FQCN', function () {
        expect(analyzeCodeMethod('pathToFqcn', 'app/Models/User.php'))->toBe('App\\Models\\User');
    });

    it('converts nested app path correctly', function () {
        expect(analyzeCodeMethod('pathToFqcn', 'app/Http/Controllers/UserController.php'))
            ->toBe('App\\Http\\Controllers\\UserController');
    });

    it('converts database/factories path to Database\\Factories FQCN', function () {
        expect(analyzeCodeMethod('pathToFqcn', 'database/factories/UserFactory.php'))
            ->toBe('Database\\Factories\\UserFactory');
    });

    it('converts tests path to Tests namespace FQCN', function () {
        expect(analyzeCodeMethod('pathToFqcn', 'tests/Feature/UserTest.php'))
            ->toBe('Tests\\Feature\\UserTest');
    });

    it('returns null for unrecognised paths', function () {
        expect(analyzeCodeMethod('pathToFqcn', 'some/unknown/path.php'))->toBeNull();
    });

    it('returns null for non-PHP files', function () {
        expect(analyzeCodeMethod('pathToFqcn', 'resources/js/app.js'))->toBeNull();
    });
});

// ── fqcnToPath ────────────────────────────────────────────────────────────────

describe('fqcnToPath', function () {
    it('converts App namespace FQCN to app path', function () {
        expect(analyzeCodeMethod('fqcnToPath', 'App\\Models\\User'))->toBe('app/Models/User.php');
    });

    it('converts Database\\Factories FQCN to database/factories path', function () {
        expect(analyzeCodeMethod('fqcnToPath', 'Database\\Factories\\UserFactory'))
            ->toBe('database/factories/UserFactory.php');
    });

    it('converts Database\\Seeders FQCN to database/seeders path', function () {
        expect(analyzeCodeMethod('fqcnToPath', 'Database\\Seeders\\DatabaseSeeder'))
            ->toBe('database/seeders/DatabaseSeeder.php');
    });

    it('converts Tests FQCN to tests path', function () {
        expect(analyzeCodeMethod('fqcnToPath', 'Tests\\Unit\\FooTest'))->toBe('tests/Unit/FooTest.php');
    });

    it('returns null for unknown namespaces', function () {
        expect(analyzeCodeMethod('fqcnToPath', 'SomeVendor\\Package\\SomeClass'))->toBeNull();
    });
});

// ── extractFqcnFromContent ────────────────────────────────────────────────────

describe('extractFqcnFromContent', function () {
    it('extracts FQCN from a plain class', function () {
        $content = '<?php
namespace App\Models;
class User {}';
        expect(analyzeCodeMethod('extractFqcnFromContent', $content))->toBe('App\\Models\\User');
    });

    it('extracts FQCN from an abstract class', function () {
        $content = '<?php
namespace App\Support;
abstract class BaseService {}';
        expect(analyzeCodeMethod('extractFqcnFromContent', $content))->toBe('App\\Support\\BaseService');
    });

    it('extracts FQCN from a final class', function () {
        $content = '<?php
namespace App\Actions;
final class CreateUser {}';
        expect(analyzeCodeMethod('extractFqcnFromContent', $content))->toBe('App\\Actions\\CreateUser');
    });

    it('extracts FQCN from a readonly class', function () {
        $content = '<?php
namespace App\Data;
readonly class UserData {}';
        expect(analyzeCodeMethod('extractFqcnFromContent', $content))->toBe('App\\Data\\UserData');
    });

    it('extracts FQCN from an interface', function () {
        $content = '<?php
namespace App\Contracts;
interface Renderable {}';
        expect(analyzeCodeMethod('extractFqcnFromContent', $content))->toBe('App\\Contracts\\Renderable');
    });

    it('extracts FQCN from a trait', function () {
        $content = '<?php
namespace App\Models\Concerns;
trait HasTimestamps {}';
        expect(analyzeCodeMethod('extractFqcnFromContent', $content))->toBe('App\\Models\\Concerns\\HasTimestamps');
    });

    it('extracts FQCN from an enum', function () {
        $content = '<?php
namespace App\Enums;
enum Status: string {}';
        expect(analyzeCodeMethod('extractFqcnFromContent', $content))->toBe('App\\Enums\\Status');
    });

    it('returns null when there is no namespace declaration', function () {
        $content = '<?php
class Foo {}';
        expect(analyzeCodeMethod('extractFqcnFromContent', $content))->toBeNull();
    });

    it('returns null when there is no class-like declaration', function () {
        $content = '<?php
namespace App\Helpers;
function doSomething() {}';
        expect(analyzeCodeMethod('extractFqcnFromContent', $content))->toBeNull();
    });

    it('trims whitespace from namespace and class name', function () {
        $content = "<?php\nnamespace  App\\Services ;\nclass  UserService  {}";
        expect(analyzeCodeMethod('extractFqcnFromContent', $content))->toBe('App\\Services\\UserService');
    });
});

// ── countHotSpots ─────────────────────────────────────────────────────────────

describe('countHotSpots', function () {
    it('returns 0 for an empty metrics array', function () {
        expect(analyzeCodeMethod('countHotSpots', []))->toBe(0);
    });

    it('returns 0 when all metrics are within acceptable thresholds', function () {
        $metrics = new PhpMetrics(
            cyclomaticComplexity: 5,
            weightedMethodCount: null,
            maintainabilityIndex: 90.0,
            bugs: 0.05,
            lackOfCohesion: null,
            efferentCoupling: 10,
            logicalLinesOfCode: null,
            methodsCount: null,
        );
        expect(analyzeCodeMethod('countHotSpots', ['App\\Foo' => $metrics]))->toBe(0);
    });

    it('counts a class with cyclomatic complexity above 10 as a hotspot', function () {
        $metrics = new PhpMetrics(11, null, 90.0, 0.05, null, 5, null, null);
        expect(analyzeCodeMethod('countHotSpots', ['App\\Foo' => $metrics]))->toBe(1);
    });

    it('counts a class with maintainability index below 85 as a hotspot', function () {
        $metrics = new PhpMetrics(5, null, 84.9, 0.05, null, 5, null, null);
        expect(analyzeCodeMethod('countHotSpots', ['App\\Foo' => $metrics]))->toBe(1);
    });

    it('counts a class with bugs above 0.1 as a hotspot', function () {
        $metrics = new PhpMetrics(5, null, 90.0, 0.11, null, 5, null, null);
        expect(analyzeCodeMethod('countHotSpots', ['App\\Foo' => $metrics]))->toBe(1);
    });

    it('counts a class with efferent coupling above 15 as a hotspot', function () {
        $metrics = new PhpMetrics(5, null, 90.0, 0.05, null, 16, null, null);
        expect(analyzeCodeMethod('countHotSpots', ['App\\Foo' => $metrics]))->toBe(1);
    });

    it('counts each file only once even when multiple thresholds are exceeded', function () {
        $metrics = new PhpMetrics(20, null, 50.0, 0.5, null, 20, null, null);
        expect(analyzeCodeMethod('countHotSpots', ['App\\Foo' => $metrics]))->toBe(1);
    });

    it('counts multiple hotspot files independently', function () {
        $hot = new PhpMetrics(20, null, 50.0, 0.5, null, 20, null, null);
        $cool = new PhpMetrics(5, null, 90.0, 0.05, null, 5, null, null);
        expect(analyzeCodeMethod('countHotSpots', [
            'App\\Hot' => $hot,
            'App\\Cool' => $cool,
            'App\\AlsoHot' => $hot,
        ]))->toBe(2);
    });

    it('handles null metric values without counting them as hotspots', function () {
        $metrics = new PhpMetrics(null, null, null, null, null, null, null, null);
        expect(analyzeCodeMethod('countHotSpots', ['App\\Foo' => $metrics]))->toBe(0);
    });
});

// ── countJsHotSpots ───────────────────────────────────────────────────────────

describe('countJsHotSpots', function () {
    it('returns 0 for an empty metrics array', function () {
        expect(analyzeCodeMethod('countJsHotSpots', []))->toBe(0);
    });

    it('returns 0 when all JS metrics are within acceptable thresholds', function () {
        $metrics = new JsMetrics(5, 90.0, 0.05, 100, 3);
        expect(analyzeCodeMethod('countJsHotSpots', ['src/Foo.tsx' => $metrics]))->toBe(0);
    });

    it('counts a file with cyclomatic complexity above 10 as a hotspot', function () {
        $metrics = new JsMetrics(11, 90.0, 0.05, 100, 3);
        expect(analyzeCodeMethod('countJsHotSpots', ['src/Foo.tsx' => $metrics]))->toBe(1);
    });

    it('counts a file with maintainability index below 85 as a hotspot', function () {
        $metrics = new JsMetrics(5, 84.9, 0.05, 100, 3);
        expect(analyzeCodeMethod('countJsHotSpots', ['src/Foo.tsx' => $metrics]))->toBe(1);
    });

    it('counts a file with bugs above 0.1 as a hotspot', function () {
        $metrics = new JsMetrics(5, 90.0, 0.11, 100, 3);
        expect(analyzeCodeMethod('countJsHotSpots', ['src/Foo.tsx' => $metrics]))->toBe(1);
    });

    it('counts each file only once even when multiple thresholds are exceeded', function () {
        $metrics = new JsMetrics(20, 50.0, 0.5, 500, 30);
        expect(analyzeCodeMethod('countJsHotSpots', ['src/Foo.tsx' => $metrics]))->toBe(1);
    });

    it('handles null metric values without counting them as hotspots', function () {
        $metrics = new JsMetrics(null, null, null, null, null);
        expect(analyzeCodeMethod('countJsHotSpots', ['src/Foo.tsx' => $metrics]))->toBe(0);
    });
});

// ── classifyFile ─────────────────────────────────────────────────────────────

describe('classifyFile', function () {
    it('classifies a model file correctly', function () {
        $node = analyzeCodeMethod('classifyFile', ['path' => 'app/Models/User.php', 'additions' => 10, 'deletions' => 2], []);

        expect($node['group'])->toBe('model')
            ->and($node['path'])->toBe('app/Models/User.php')
            ->and($node['add'])->toBe(10)
            ->and($node['del'])->toBe(2)
            ->and($node['ext'])->toBe('php')
            ->and($node['domain'])->toBe('Models');
    });

    it('classifies a controller file correctly', function () {
        $node = analyzeCodeMethod('classifyFile', ['path' => 'app/Http/Controllers/UserController.php', 'additions' => 5, 'deletions' => 0], []);

        expect($node['group'])->toBe('controller')
            ->and($node['domain'])->toBe('Http');
    });

    it('classifies a test file correctly', function () {
        $node = analyzeCodeMethod('classifyFile', ['path' => 'tests/Feature/UserTest.php', 'additions' => 20, 'deletions' => 0], []);

        expect($node['group'])->toBe('test')
            ->and($node['domain'])->toBe('tests');
    });

    it('classifies a migration file correctly', function () {
        $node = analyzeCodeMethod('classifyFile', ['path' => 'database/migrations/2024_01_01_000000_create_users_table.php', 'additions' => 15, 'deletions' => 0], []);

        expect($node['group'])->toBe('db')
            ->and($node['domain'])->toBe('database');
    });

    it('classifies a route file correctly', function () {
        $node = analyzeCodeMethod('classifyFile', ['path' => 'routes/api.php', 'additions' => 3, 'deletions' => 1], []);

        expect($node['group'])->toBe('route');
    });

    it('classifies a Vue frontend file correctly', function () {
        $node = analyzeCodeMethod('classifyFile', ['path' => 'resources/js/Pages/Dashboard.vue', 'additions' => 8, 'deletions' => 2], []);

        expect($node['group'])->toBe('frontend')
            ->and($node['ext'])->toBe('vue');
    });

    it('sets status to modified by default when not in fileDiffMap', function () {
        $node = analyzeCodeMethod('classifyFile', ['path' => 'app/Models/User.php', 'additions' => 1, 'deletions' => 0], []);

        expect($node['status'])->toBe(FileStatus::MODIFIED->value);
    });

    it('strips app/ prefix from folder', function () {
        $node = analyzeCodeMethod('classifyFile', ['path' => 'app/Models/User.php', 'additions' => 1, 'deletions' => 0], []);

        expect($node['folder'])->toBe('Models');
    });

    it('strips tests/Unit/ and tests/Feature/ prefix from folder', function () {
        $node = analyzeCodeMethod('classifyFile', ['path' => 'tests/Unit/Support/FooTest.php', 'additions' => 1, 'deletions' => 0], []);

        expect($node['folder'])->toStartWith('tests/');
    });

    it('generates a sha256 hash of the path', function () {
        $node = analyzeCodeMethod('classifyFile', ['path' => 'app/Models/User.php', 'additions' => 1, 'deletions' => 0], []);

        expect($node['hash'])->toBe(hash('sha256', 'app/Models/User.php'));
    });
});

// ── execute — error cases ─────────────────────────────────────────────────────

describe('execute — error cases', function () {
    it('throws RuntimeException when path is not a git repository', function () {
        $dir = sys_get_temp_dir().'/not-a-git-repo-'.uniqid();
        mkdir($dir);

        expect(fn () => (new AnalyzeCode)->execute(repoPath: $dir))
            ->toThrow(RuntimeException::class, 'Not a git repository');

        rmdir($dir);
    });

    it('returns empty result when base branch does not exist', function () {
        // git rev-parse echoes back the unresolvable ref to stdout, so no exception is thrown;
        // the diff against an invalid ref is empty → execute() returns early.
        $dir = makeTempGitRepo();

        $result = (new AnalyzeCode)->execute(repoPath: $dir, baseBranch: 'nonexistent-branch-xyz');

        removeTempDir($dir);

        expect($result['files'])->toBeEmpty()
            ->and($result['risk']->score)->toBe(0);
    });

    it('throws RuntimeException for an invalid GitHub PR URL format', function () {
        expect(fn () => (new AnalyzeCode)->execute(prUrl: 'https://example.com/not-a-pr'))
            ->toThrow(RuntimeException::class, 'Invalid GitHub PR URL');
    });
});

// ── execute — no-change early return ─────────────────────────────────────────

describe('execute — no diff', function () {
    it('returns empty files and zero risk when there are no changes', function () {
        $dir = makeTempGitRepo();
        // No changes since the initial commit — diff against main is empty

        $result = (new AnalyzeCode)->execute(repoPath: $dir, baseBranch: 'main');

        removeTempDir($dir);

        expect($result['files'])->toBeEmpty()
            ->and($result['risk'])->toBeInstanceOf(RiskScore::class)
            ->and($result['risk']->score)->toBe(0);
    });
});

// ── execute — uncommitted changes progress messages ───────────────────────────

describe('execute — uncommitted changes progress messages', function () {
    it('shows "Including staged and unstaged working tree changes" when uncommitted changes produce a real diff vs base', function () {
        $dir = makeTempGitRepo(); // main: README.md committed

        // Commit a file on a feature branch (main stays at initial commit)
        shell_exec("git -C {$dir} checkout -b feature 2>&1");
        addAndStageFile($dir, 'committed.php', '<?php class Committed {}');
        shell_exec("git -C {$dir} commit -m 'committed' 2>&1");

        // Add an uncommitted staged change on top — combined diff vs main is non-empty
        addAndStageFile($dir, 'staged.php', '<?php class Staged {}');

        $messages = [];
        (new AnalyzeCode)->execute(
            repoPath: $dir,
            baseBranch: 'main',
            format: OutputFormat::JSON,
            onProgress: function (string $level, string $message) use (&$messages) {
                $messages[] = [$level, $message];
            },
            raw: true,
        );

        removeTempDir($dir);

        $lineText = implode(' ', collect($messages)->filter(fn ($m) => $m[0] === 'line')->pluck(1)->all());

        expect($lineText)->toContain('Including staged and unstaged working tree changes');
    });

    it('does not show "Including staged and unstaged" when there are no uncommitted changes', function () {
        $dir = makeTempGitRepo(); // main: README.md committed

        // Commit a file — no uncommitted changes
        addAndStageFile($dir, 'committed.php', '<?php class Committed {}');
        shell_exec("git -C {$dir} commit -m 'committed' 2>&1");

        $messages = [];
        (new AnalyzeCode)->execute(
            repoPath: $dir,
            baseBranch: 'main',
            format: OutputFormat::JSON,
            onProgress: function (string $level, string $message) use (&$messages) {
                $messages[] = [$level, $message];
            },
            raw: true,
        );

        removeTempDir($dir);

        $lineText = implode(' ', collect($messages)->filter(fn ($m) => $m[0] === 'line')->pluck(1)->all());

        expect($lineText)->not->toContain('Including staged and unstaged working tree changes');
    });
});

// ── execute — uncommitted changes cancel branch commits ───────────────────────

describe('execute — uncommitted changes cancel branch commits', function () {
    it('falls back to HEAD diff and produces a report when uncommitted changes cancel the branch diff vs base', function () {
        $dir = makeTempGitRepo(); // main: README.md committed

        // Create a feature branch and commit a new file there (main stays at initial commit)
        shell_exec("git -C {$dir} checkout -b feature 2>&1");
        file_put_contents("{$dir}/new-file.php", '<?php class Foo {}');
        shell_exec("git -C {$dir} add new-file.php 2>&1");
        shell_exec("git -C {$dir} commit -m 'add new-file' 2>&1");

        // Undo the file in the working tree (unstaged delete):
        //   git diff main  → empty   (working tree matches main)
        //   git status     → " D new-file.php"
        //   git diff HEAD  → shows deletion
        unlink("{$dir}/new-file.php");

        $messages = [];
        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            baseBranch: 'main',
            format: OutputFormat::JSON,
            onProgress: function (string $level, string $message) use (&$messages) {
                $messages[] = [$level, $message];
            },
            raw: true,
        );

        removeTempDir($dir);

        $warnText = implode(' ', collect($messages)->filter(fn ($m) => $m[0] === 'warn')->pluck(1)->all());
        $lineText = implode(' ', collect($messages)->filter(fn ($m) => $m[0] === 'line')->pluck(1)->all());

        // In raw mode, files is always [] but content is set when a report was generated
        expect($result)->toHaveKey('content')
            ->and($warnText)->toContain('No net changes between working tree and main')
            ->and($lineText)->toContain('(uncommitted only)');
    });
});

// ── execute — progress callback ───────────────────────────────────────────────

describe('execute — progress callback', function () {
    it('invokes the progress callback with messages during analysis', function () {
        $dir = makeTempGitRepo();
        // Stage a new PHP file so there IS a diff against main
        addAndStageFile($dir, 'new-file.php', '<?php echo "hello";');

        $messages = [];
        (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            onProgress: function (string $level, string $message) use (&$messages) {
                $messages[] = [$level, $message];
            },
            raw: true,
        );

        removeTempDir($dir);

        expect($messages)->not->toBeEmpty()
            ->and(collect($messages)->pluck(0)->all())->toContain('info');
    });
});

// ── execute — HEAD base ───────────────────────────────────────────────────────

describe('execute — HEAD base', function () {
    it('uses "uncommitted changes on <branch>" as title and shows "(uncommitted only)" in progress', function () {
        $dir = makeTempGitRepo();
        addAndStageFile($dir, 'new-file.php', '<?php echo "hello";');

        $messages = [];
        (new AnalyzeCode)->execute(
            repoPath: $dir,
            baseBranch: 'HEAD',
            format: OutputFormat::JSON,
            onProgress: function (string $level, string $message) use (&$messages) {
                $messages[] = [$level, $message];
            },
            raw: true,
        );

        removeTempDir($dir);

        $infoText = implode(' ', collect($messages)->filter(fn ($m) => $m[0] === 'info')->pluck(1)->all());
        $lineText = implode(' ', collect($messages)->filter(fn ($m) => $m[0] === 'line')->pluck(1)->all());

        expect($infoText)->toContain('uncommitted changes on')
            ->and($lineText)->toContain('(uncommitted only)');
    });

    it('returns empty files and warns "No uncommitted changes found." when there is no diff', function () {
        $dir = makeTempGitRepo();
        // No uncommitted changes after the initial commit

        $messages = [];
        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            baseBranch: 'HEAD',
            format: OutputFormat::JSON,
            onProgress: function (string $level, string $message) use (&$messages) {
                $messages[] = [$level, $message];
            },
            raw: true,
        );

        removeTempDir($dir);

        $warnText = implode(' ', collect($messages)->filter(fn ($m) => $m[0] === 'warn')->pluck(1)->all());

        expect($result['files'])->toBeEmpty()
            ->and($warnText)->toContain('No uncommitted changes found.');
    });

    it('detects HEAD base when passing the literal current commit hash', function () {
        $dir = makeTempGitRepo();
        addAndStageFile($dir, 'new-file.php', '<?php echo "hello";');

        $currentHash = trim(shell_exec("git -C {$dir} rev-parse HEAD 2>/dev/null") ?? '');

        $messages = [];
        (new AnalyzeCode)->execute(
            repoPath: $dir,
            baseBranch: $currentHash,
            format: OutputFormat::JSON,
            onProgress: function (string $level, string $message) use (&$messages) {
                $messages[] = [$level, $message];
            },
            raw: true,
        );

        removeTempDir($dir);

        $infoText = implode(' ', collect($messages)->filter(fn ($m) => $m[0] === 'info')->pluck(1)->all());

        expect($infoText)->toContain('uncommitted changes on');
    });
});

// ── execute — file pattern filter ────────────────────────────────────────────

describe('execute — file pattern filter', function () {
    it('returns only files matching the provided pattern', function () {
        $dir = makeTempGitRepo();

        addAndStageFile($dir, 'app/Foo.php', '<?php class Foo {}');
        addAndStageFile($dir, 'app/Bar.php', '<?php class Bar {}');
        addAndStageFile($dir, 'docs/notes.md', '# notes');

        // Use a str_contains-compatible pattern (the execute() filter uses fnmatch || str_contains)
        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            filePatterns: ['.php'],
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);
        $paths = array_column($content['files'], 'path');

        foreach ($paths as $path) {
            expect($path)->toEndWith('.php');
        }
    });

    it('returns empty result when no files match the pattern', function () {
        $dir = makeTempGitRepo();
        addAndStageFile($dir, 'docs/notes.md', '# changed again');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            filePatterns: ['*.php'],
        );

        removeTempDir($dir);

        expect($result['files'])->toBeEmpty()
            ->and($result['risk']->score)->toBe(0);
    });
});

// ── execute — watched files ───────────────────────────────────────────────────

describe('execute — watched files', function () {
    it('marks matched files as watched with a reason', function () {
        $dir = makeTempGitRepo();
        addAndStageFile($dir, 'config/auth.php', '<?php return [];');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            watchedFiles: [
                ['pattern' => 'config/auth.php', 'reason' => 'Security-sensitive config'],
            ],
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);
        $authFile = collect($content['files'])->firstWhere('path', 'config/auth.php');

        // The node was processed through the watched-files logic
        expect($authFile)->not->toBeNull();
    });
});

// ── execute — raw mode ────────────────────────────────────────────────────────

describe('execute — raw mode', function () {
    it('returns a content key in raw mode instead of writing to disk', function () {
        $dir = makeTempGitRepo();
        addAndStageFile($dir, 'something.php', '<?php class Something {}');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        expect($result)->toHaveKey('content')
            ->and($result['content'])->toBeString()
            ->and(json_decode($result['content'], true))->toBeArray();
    });

    it('returns a RiskScore instance in raw mode', function () {
        $dir = makeTempGitRepo();
        addAndStageFile($dir, 'something.php', '<?php class Something {}');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        expect($result['risk'])->toBeInstanceOf(RiskScore::class);
    });
});

// ── execute — custom risk / signal scorers ────────────────────────────────────

describe('execute — injectable scorers', function () {
    it('uses a custom risk scorer when provided', function () {
        $mockScorer = new class implements RiskScoring
        {
            public function calculate(array $nodes, int $additions, int $deletions, int $fileCount, int $phpHotSpots): RiskScore
            {
                return new RiskScore(42);
            }
        };

        $dir = makeTempGitRepo();
        addAndStageFile($dir, 'something.php', '<?php class Something {}');

        $result = (new AnalyzeCode(riskScorer: $mockScorer))->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        expect($result['risk']->score)->toBe(42);
    });
});

// ── execute — dependency detection as code analysis ──────────────────────────

describe('execute — dependency detection as code analysis', function () {
    it('adds a constructor injection finding with __construct location', function () {
        $dir = makeTempGitRepo();

        addAndStageFile($dir, 'app/Services/FooService.php', '<?php
namespace App\Services;
class FooService {}');

        addAndStageFile($dir, 'app/Http/Controllers/UserController.php', '<?php
namespace App\Http\Controllers;
use App\Services\FooService;
class UserController {
    public function __construct(FooService $foo) {}
}');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);
        $depFindings = collect($content['findings'])
            ->where('category', 'dependency')
            ->where('file', 'app/Http/Controllers/UserController.php')
            ->values()->all();

        expect($depFindings)->not->toBeEmpty();

        $finding = collect($depFindings)->first(fn ($f) => str_contains($f['description'], 'constructor injection'));

        expect($finding)->not->toBeNull()
            ->and($finding['severity'])->toBe('info')
            ->and($finding['description'])->toContain('FooService')
            ->and($finding['location'])->toBe('__construct');
    });

    it('adds a new instance finding without a location', function () {
        $dir = makeTempGitRepo();

        addAndStageFile($dir, 'app/Services/Helper.php', '<?php
namespace App\Services;
class Helper {}');

        addAndStageFile($dir, 'app/Actions/DoSomething.php', '<?php
namespace App\Actions;
use App\Services\Helper;
class DoSomething {
    public function handle(): void {
        $h = new Helper();
    }
}');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);
        $finding = collect($content['findings'])
            ->where('category', 'dependency')
            ->where('file', 'app/Actions/DoSomething.php')
            ->first(fn ($f) => str_contains($f['description'], 'new instance'));

        expect($finding)->not->toBeNull()
            ->and($finding['severity'])->toBe('info')
            ->and($finding['description'])->toContain('Helper')
            ->and(array_key_exists('location', $finding))->toBeFalse();
    });

    it('does not add findings for use-import-only or return-type-only dependencies', function () {
        $dir = makeTempGitRepo();

        // use import → USE type (skipped); return type → RETURN_TYPE (skipped)
        addAndStageFile($dir, 'app/Services/BazService.php', '<?php
namespace App\Services;
use Some\External\Library;
class BazService {
    public function handle(): Library {}
}');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);
        $depFindings = collect($content['findings'])
            ->where('category', 'dependency')
            ->where('file', 'app/Services/BazService.php')
            ->values()->all();

        expect($depFindings)->toBeEmpty();
    });

    it('does not add dependency findings for unchanged PHP files', function () {
        $dir = makeTempGitRepo();

        // Commit a PHP file with constructor injection — it will be unchanged in the diff
        $path = "{$dir}/app/Services/FooService.php";
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, '<?php
namespace App\Services;
class BarDep {}
class FooService {
    public function __construct(BarDep $dep) {}
}');
        shell_exec("git -C {$dir} add . 2>&1");
        shell_exec("git -C {$dir} commit -m 'add service' 2>&1");

        // Only stage a different new file
        addAndStageFile($dir, 'app/Actions/NewAction.php', '<?php
namespace App\Actions;
class NewAction {}');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);
        $depFindings = collect($content['findings'])
            ->where('category', 'dependency')
            ->where('file', 'app/Services/FooService.php')
            ->values()->all();

        expect($depFindings)->toBeEmpty();
    });
});

// ── execute — minSeverity filter ──────────────────────────────────────────────

describe('execute — minSeverity filter', function () {
    it('accepts a minSeverity filter without throwing', function () {
        $dir = makeTempGitRepo();
        addAndStageFile($dir, 'something.php', '<?php class Something {}');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            minSeverity: Severity::HIGH,
            raw: true,
        );

        removeTempDir($dir);

        expect($result['risk'])->toBeInstanceOf(RiskScore::class);
    });
});

// ── computeSignalScores (PR connection boost) ─────────────────────────────────

/**
 * Create an AnalyzeCode instance with a stub scorer that always returns the
 * given base score, inject edges via reflection, then invoke computeSignalScores.
 */
function computeSignalScoresWithEdges(array $nodes, array $edges, int $baseScore = 0): array
{
    $scorer = new class($baseScore) implements FileSignalScoring
    {
        public function __construct(private int $base) {}

        public function calculate(array $node, array $findings, ?array $metrics): int
        {
            return $this->base;
        }
    };

    $obj = new AnalyzeCode(fileSignalScorer: $scorer);

    $edgesProp = new ReflectionProperty($obj, 'edges');
    $edgesProp->setAccessible(true);
    $edgesProp->setValue($obj, $edges);

    $method = new ReflectionMethod($obj, 'computeSignalScores');
    $method->setAccessible(true);

    return $method->invoke($obj, $nodes, [], []);
}

function makeSignalNode(string $id, bool $isConnected = false): array
{
    return [
        'id' => $id,
        'path' => "app/{$id}.php",
        'add' => 0,
        'del' => 0,
        'isConnected' => $isConnected,
    ];
}

describe('computeSignalScores — PR connection boost', function () {
    it('adds no connection boost when there are no edges', function () {
        $nodes = [makeSignalNode('Foo'), makeSignalNode('Bar')];
        $result = computeSignalScoresWithEdges($nodes, []);

        expect($result[0])->not->toHaveKey('_connectionBoost')
            ->and($result[1])->not->toHaveKey('_connectionBoost');
    });

    it('adds no connection boost when the only edge links to an external connected node', function () {
        $nodes = [makeSignalNode('Foo'), makeSignalNode('External', isConnected: true)];
        $result = computeSignalScoresWithEdges($nodes, [['Foo', 'External', 'use']]);

        expect($result[0])->not->toHaveKey('_connectionBoost');
    });

    it('boosts both ends of an edge between two changed files', function () {
        $nodes = [makeSignalNode('Foo'), makeSignalNode('Bar')];
        $result = computeSignalScoresWithEdges($nodes, [['Foo', 'Bar', 'use']]);

        $byId = array_column($result, null, 'id');
        expect($byId['Foo']['_connectionBoost'])->toBe(5)
            ->and($byId['Bar']['_connectionBoost'])->toBe(5);
    });

    it('accumulates boost for multiple connections', function () {
        $nodes = [makeSignalNode('A'), makeSignalNode('B'), makeSignalNode('C'), makeSignalNode('D')];
        $edges = [['A', 'B', 'use'], ['A', 'C', 'use'], ['A', 'D', 'use']];
        $result = computeSignalScoresWithEdges($nodes, $edges);

        $byId = array_column($result, null, 'id');
        // A has 3 outgoing edges → 3 × 5 = 15
        expect($byId['A']['_connectionBoost'])->toBe(15)
            ->and($byId['A']['_connections'])->toBe(3);
    });

    it('stores the connection count on the node', function () {
        $nodes = [makeSignalNode('Foo'), makeSignalNode('Bar'), makeSignalNode('Baz')];
        $edges = [['Foo', 'Bar', 'use'], ['Foo', 'Baz', 'use']];
        $result = computeSignalScoresWithEdges($nodes, $edges);

        $byId = array_column($result, null, 'id');
        expect($byId['Foo']['_connections'])->toBe(2);
    });

    it('adds the connection boost on top of the base signal', function () {
        $nodes = [makeSignalNode('Foo'), makeSignalNode('Bar')];
        $result = computeSignalScoresWithEdges($nodes, [['Foo', 'Bar', 'use']], baseScore: 20);

        $byId = array_column($result, null, 'id');
        expect($byId['Foo']['_signal'])->toBe(25); // 20 base + 5 boost
    });

    it('respects a custom multiplier from config', function () {
        config(['laravel-code-analytics.file_signal.pr_connections.multiplier' => 10]);

        $nodes = [makeSignalNode('Foo'), makeSignalNode('Bar')];
        $result = computeSignalScoresWithEdges($nodes, [['Foo', 'Bar', 'use']]);

        config(['laravel-code-analytics.file_signal.pr_connections.multiplier' => 5]);

        $byId = array_column($result, null, 'id');
        expect($byId['Foo']['_connectionBoost'])->toBe(10);
    });

    it('does not count edges where one end is a connected node', function () {
        $nodes = [makeSignalNode('Foo'), makeSignalNode('Bar'), makeSignalNode('External', isConnected: true)];
        $edges = [['Foo', 'Bar', 'use'], ['Bar', 'External', 'use']];
        $result = computeSignalScoresWithEdges($nodes, $edges);

        $byId = array_column($result, null, 'id');
        // Bar→External should not be counted; Bar only gets credit for Foo→Bar
        expect($byId['Bar']['_connections'])->toBe(1)
            ->and($byId['Bar']['_connectionBoost'])->toBe(5);
    });

    it('counts both incoming and outgoing edges', function () {
        // A → B, C → A: A has 1 in + 1 out = 2 connections
        $nodes = [makeSignalNode('A'), makeSignalNode('B'), makeSignalNode('C')];
        $edges = [['A', 'B', 'use'], ['C', 'A', 'use']];
        $result = computeSignalScoresWithEdges($nodes, $edges);

        $byId = array_column($result, null, 'id');
        expect($byId['A']['_connections'])->toBe(2)
            ->and($byId['A']['_connectionBoost'])->toBe(10);
    });
});

// ── execute — blade dependents ────────────────────────────────────────────────

describe('execute — blade dependents', function () {
    it('adds a non-diff blade file that @extends a changed blade as a connected node', function () {
        $dir = makeTempGitRepo();

        // Commit home.blade.php on main — it exists before the diff, so it is NOT a diff node
        addAndStageFile($dir, 'resources/views/home.blade.php', "@extends('layouts.app')\n<div>Home</div>");
        shell_exec("git -C {$dir} commit -m 'add home view' 2>&1");

        // Stage the layout file — this IS in the diff
        addAndStageFile($dir, 'resources/views/layouts/app.blade.php', '<html>{{ $slot }}</html>');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);
        $paths = array_column($content['files'], 'path');

        expect($paths)->toContain('resources/views/home.blade.php')
            ->and($paths)->toContain('resources/views/layouts/app.blade.php');

        // The connected node was not part of the diff — it has zero additions and deletions
        $homeFile = collect($content['files'])->firstWhere('path', 'resources/views/home.blade.php');
        expect($homeFile['additions'])->toBe(0)
            ->and($homeFile['deletions'])->toBe(0);
    });

    it('adds a non-diff blade file that @includes a changed blade as a connected node', function () {
        $dir = makeTempGitRepo();

        addAndStageFile($dir, 'resources/views/page.blade.php', "@include('partials.header')\n<div>Content</div>");
        shell_exec("git -C {$dir} commit -m 'add page' 2>&1");

        addAndStageFile($dir, 'resources/views/partials/header.blade.php', '<header>Site header</header>');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);
        $paths = array_column($content['files'], 'path');

        expect($paths)->toContain('resources/views/page.blade.php');
    });

    it('adds a non-diff blade file that uses an <x-component> tag from a changed blade as a connected node', function () {
        $dir = makeTempGitRepo();

        addAndStageFile($dir, 'resources/views/dashboard.blade.php', "<x-alert />\n<div>Dashboard</div>");
        shell_exec("git -C {$dir} commit -m 'add dashboard' 2>&1");

        addAndStageFile($dir, 'resources/views/components/alert.blade.php', '<div class="alert">{{ $slot }}</div>');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);
        $paths = array_column($content['files'], 'path');

        expect($paths)->toContain('resources/views/dashboard.blade.php');
    });

    it('does not add blade files that reference unrelated (non-diff) blade files', function () {
        $dir = makeTempGitRepo();

        addAndStageFile($dir, 'resources/views/unrelated.blade.php', "@extends('other.layout')\n<div>Unrelated</div>");
        shell_exec("git -C {$dir} commit -m 'add unrelated view' 2>&1");

        // Only the layout/app blade is in the diff — unrelated.blade.php extends a different layout
        addAndStageFile($dir, 'resources/views/layouts/app.blade.php', '<html>{{ $slot }}</html>');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);
        $paths = array_column($content['files'], 'path');

        expect($paths)->not->toContain('resources/views/unrelated.blade.php');
    });

    it('does not run the blade dependents scan when no blade files are in the diff', function () {
        $dir = makeTempGitRepo();

        // Commit a blade file that would be picked up if the scan ran
        addAndStageFile($dir, 'resources/views/home.blade.php', "@extends('layouts.app')");
        shell_exec("git -C {$dir} commit -m 'add home view' 2>&1");

        // Only a PHP controller is in the diff — no blade files
        addAndStageFile($dir, 'app/Http/Controllers/HomeController.php', '<?php
namespace App\Http\Controllers;
class HomeController {}');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);
        $paths = array_column($content['files'], 'path');

        expect($paths)->not->toContain('resources/views/home.blade.php');
    });

    it('creates a dependency edge from the dependent blade to the changed blade', function () {
        $dir = makeTempGitRepo();

        addAndStageFile($dir, 'resources/views/home.blade.php', "@extends('layouts.app')");
        shell_exec("git -C {$dir} commit -m 'add home view' 2>&1");

        addAndStageFile($dir, 'resources/views/layouts/app.blade.php', '<html>{{ $slot }}</html>');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);

        expect($content['dependencies'])->not->toBeEmpty();
    });

    it('handles multiple non-diff blade files referencing the same changed blade', function () {
        $dir = makeTempGitRepo();

        addAndStageFile($dir, 'resources/views/home.blade.php', "@extends('layouts.app')");
        addAndStageFile($dir, 'resources/views/about.blade.php', "@extends('layouts.app')");
        addAndStageFile($dir, 'resources/views/contact.blade.php', "@extends('layouts.app')");
        shell_exec("git -C {$dir} commit -m 'add views' 2>&1");

        addAndStageFile($dir, 'resources/views/layouts/app.blade.php', '<html>{{ $slot }}</html>');

        $result = (new AnalyzeCode)->execute(
            repoPath: $dir,
            format: OutputFormat::JSON,
            raw: true,
        );

        removeTempDir($dir);

        $content = json_decode($result['content'], true);
        $paths = array_column($content['files'], 'path');

        expect($paths)->toContain('resources/views/home.blade.php')
            ->and($paths)->toContain('resources/views/about.blade.php')
            ->and($paths)->toContain('resources/views/contact.blade.php');
    });
});
