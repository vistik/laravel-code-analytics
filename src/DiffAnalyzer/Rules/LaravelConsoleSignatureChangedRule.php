<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelConsoleSignatureChangedRule implements Rule
{
    use AnalyzesLaravelCode;

    public function __construct(private readonly ?string $repoPath = null)
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects Artisan command signature and name changes';
    }

    public function description(): string
    {
        return 'Flags when an Artisan command\'s $signature or $name property changes. Severity is elevated to VERY_HIGH if the command is hardcoded in a schedule definition, since callers relying on the old name will silently stop running.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $path = $file->effectivePath();

        if (! $this->pathContains($path, 'Console/Commands/') && ! $this->pathContains($path, 'Commands/')) {
            return [];
        }

        $changes = [];

        foreach ($comparison['properties'] as $key => $pair) {
            if (! str_ends_with($key, '::$signature') && ! str_ends_with($key, '::$name')) {
                continue;
            }

            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldVal = $this->printer->prettyPrint([$pair['old']]);
            $newVal = $this->printer->prettyPrint([$pair['new']]);

            if ($oldVal === $newVal) {
                continue;
            }

            $propName = str_ends_with($key, '::$signature') ? 'signature' : 'name';
            $className = $this->getClassName($key);

            $oldCommandName = $this->extractCommandName($this->extractPropertyStringValue($pair['old']) ?? '');
            $isScheduled = $oldCommandName !== '' && $this->isScheduledCommand($oldCommandName);

            $severity = $isScheduled ? Severity::VERY_HIGH : Severity::MEDIUM;
            $scheduledNote = $isScheduled ? ' — command is hardcoded in schedule' : ' — CLI interface changed';

            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: $severity,
                description: "Artisan command \${$propName} changed on {$className}{$scheduledNote}",
                location: $key,
                line: $pair['new']->getStartLine(),
            );

            if ($oldCommandName !== '') {
                $callers = $this->findArtisanCallers($oldCommandName);

                if ($callers['code'] !== []) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::VERY_HIGH,
                        description: "Artisan command '{$oldCommandName}' is called in production code: ".implode(', ', $callers['code']),
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }

                if ($callers['tests'] !== []) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: "Artisan command '{$oldCommandName}' is called in tests: ".implode(', ', $callers['tests']),
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            }
        }

        return $changes;
    }

    private function extractCommandName(string $signature): string
    {
        return trim(explode('{', $signature)[0]);
    }

    private function isScheduledCommand(string $commandName): bool
    {
        if ($this->repoPath === null) {
            return false;
        }

        $consolePath = "{$this->repoPath}/routes/console.php";

        if (! file_exists($consolePath)) {
            return false;
        }

        $content = (string) file_get_contents($consolePath);

        return str_contains($content, "'{$commandName}'") || str_contains($content, "\"{$commandName}\"");
    }

    /**
     * Find files that call the given artisan command by name via:
     * - $this->artisan('cmd') — test helper
     * - Artisan::call('cmd') or Artisan::queue('cmd') — production/queue code
     *
     * Returns two lists: ['tests' => [...paths], 'code' => [...paths]]
     *
     * @return array{tests: list<string>, code: list<string>}
     */
    private function findArtisanCallers(string $commandName): array
    {
        if ($this->repoPath === null) {
            return ['tests' => [], 'code' => []];
        }

        $quoted = preg_quote($commandName, '/');
        $artisanFacadePattern = '/Artisan::\w+\s*\(\s*[\'"]'.$quoted.'[\'"]/';
        $artisanMethodPattern = '/->artisan\s*\(\s*[\'"]'.$quoted.'[\'"]/';

        $tests = [];
        $code = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repoPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getPathname();

            // Skip vendor directory
            if (str_contains($filePath, '/vendor/')) {
                continue;
            }

            $content = (string) file_get_contents($filePath);
            $relativePath = ltrim(str_replace($this->repoPath, '', $filePath), '/');
            $isTestFile = str_starts_with($relativePath, 'tests/') || str_contains($relativePath, 'Test.php');

            // Artisan::call / Artisan::queue — unambiguous, always counts
            if (preg_match($artisanFacadePattern, $content)) {
                if ($isTestFile) {
                    $tests[] = $relativePath;
                } else {
                    $code[] = $relativePath;
                }

                continue;
            }

            // ->artisan() — only counts in Laravel test files (InteractsWithConsole trait or TestCase / Pest)
            if (preg_match($artisanMethodPattern, $content) && $isTestFile && $this->isLaravelTestFile($content)) {
                $tests[] = $relativePath;
            }
        }

        return ['tests' => $tests, 'code' => $code];
    }

    /**
     * Check whether a file is a Laravel test that uses the InteractsWithConsole trait
     * (which provides $this->artisan()), either directly, via TestCase, or via Pest.
     */
    private function isLaravelTestFile(string $content): bool
    {
        // Uses the trait directly
        if (str_contains($content, 'InteractsWithConsole')) {
            return true;
        }

        // Extends TestCase (PHPUnit-style Laravel test)
        if (preg_match('/extends\s+\\\\?[\w\\\\]*TestCase\b/', $content)) {
            return true;
        }

        // Pest-style test file — uses() binds the TestCase which includes the trait
        if (preg_match('/\b(?:uses|it|test)\s*\(/', $content)) {
            return true;
        }

        return false;
    }
}
