<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class DependencyRule implements Rule
{
    /** @var array<string, Severity> Known security-sensitive packages */
    private const SENSITIVE_PACKAGES = [
        'laravel/sanctum' => Severity::VERY_HIGH,
        'laravel/passport' => Severity::VERY_HIGH,
        'laravel/socialite' => Severity::MEDIUM,
        'spatie/laravel-permission' => Severity::VERY_HIGH,
        'tymon/jwt-auth' => Severity::VERY_HIGH,
    ];

    public function shortDescription(): string
    {
        return 'Detects Composer and npm dependency additions, removals, and version changes';
    }

    public function description(): string
    {
        return 'Detects dependency changes: composer.json require/require-dev additions, removals, and version constraint changes; package.json dependencies/devDependencies changes.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $path = $file->effectivePath();

        if ($path === 'composer.json') {
            return $this->analyzeComposer($comparison);
        }

        if ($path === 'package.json') {
            return $this->analyzeNpm($comparison);
        }

        if ($path === 'composer.lock') {
            return [new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::INFO,
                description: 'Composer lock file updated — dependency versions resolved',
            )];
        }

        if ($path === 'package-lock.json' || $path === 'yarn.lock' || $path === 'pnpm-lock.yaml') {
            return [new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::INFO,
                description: 'Package lock file updated',
            )];
        }

        return [];
    }

    /**
     * @return list<ClassifiedChange>
     */
    private function analyzeComposer(array $comparison): array
    {
        $oldSource = $comparison['old_source'] ?? null;
        $newSource = $comparison['new_source'] ?? null;

        if ($oldSource === null || $newSource === null) {
            return [];
        }

        $old = json_decode($oldSource, true) ?? [];
        $new = json_decode($newSource, true) ?? [];

        $changes = [];

        $this->diffDependencySection($old, $new, 'require', 'production', $changes);
        $this->diffDependencySection($old, $new, 'require-dev', 'dev', $changes);

        // Detect PHP version constraint changes
        $oldPhp = $old['require']['php'] ?? null;
        $newPhp = $new['require']['php'] ?? null;

        if ($oldPhp !== $newPhp && ($oldPhp !== null || $newPhp !== null)) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::MEDIUM,
                description: 'PHP version constraint changed: '.($oldPhp ?? 'none').' -> '.($newPhp ?? 'none'),
            );
        }

        // Detect script changes
        $oldScripts = $old['scripts'] ?? [];
        $newScripts = $new['scripts'] ?? [];

        $addedScripts = array_diff_key($newScripts, $oldScripts);
        $removedScripts = array_diff_key($oldScripts, $newScripts);

        foreach (array_keys($addedScripts) as $script) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::INFO,
                description: "Composer script added: {$script}",
            );
        }

        foreach (array_keys($removedScripts) as $script) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::MEDIUM,
                description: "Composer script removed: {$script}",
            );
        }

        // Detect autoload changes
        if (($old['autoload'] ?? []) !== ($new['autoload'] ?? [])) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::MEDIUM,
                description: 'Composer autoload configuration changed',
            );
        }

        return $changes;
    }

    /**
     * @return list<ClassifiedChange>
     */
    private function analyzeNpm(array $comparison): array
    {
        $oldSource = $comparison['old_source'] ?? null;
        $newSource = $comparison['new_source'] ?? null;

        if ($oldSource === null || $newSource === null) {
            return [];
        }

        $old = json_decode($oldSource, true) ?? [];
        $new = json_decode($newSource, true) ?? [];

        $changes = [];

        $this->diffJsonDeps($old, $new, 'dependencies', 'production', $changes);
        $this->diffJsonDeps($old, $new, 'devDependencies', 'dev', $changes);

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function diffDependencySection(array $old, array $new, string $section, string $label, array &$changes): void
    {
        $oldDeps = $old[$section] ?? [];
        $newDeps = $new[$section] ?? [];

        // Remove 'php' and extensions from comparison (handled separately)
        $oldDeps = array_filter($oldDeps, fn ($key) => ! str_starts_with($key, 'ext-') && $key !== 'php', ARRAY_FILTER_USE_KEY);
        $newDeps = array_filter($newDeps, fn ($key) => ! str_starts_with($key, 'ext-') && $key !== 'php', ARRAY_FILTER_USE_KEY);

        $added = array_diff_key($newDeps, $oldDeps);
        $removed = array_diff_key($oldDeps, $newDeps);
        $common = array_intersect_key($oldDeps, $newDeps);

        foreach ($added as $package => $version) {
            $severity = self::SENSITIVE_PACKAGES[$package] ?? Severity::MEDIUM;
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: $severity,
                description: "Composer {$label} dependency added: {$package} ({$version})",
            );
        }

        foreach ($removed as $package => $version) {
            $severity = self::SENSITIVE_PACKAGES[$package] ?? Severity::MEDIUM;
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: $severity,
                description: "Composer {$label} dependency removed: {$package} ({$version})",
            );
        }

        foreach ($common as $package => $oldVersion) {
            $newVersion = $newDeps[$package];
            if ($oldVersion !== $newVersion) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::FILE_LEVEL,
                    severity: Severity::MEDIUM,
                    description: "Composer {$label} dependency version changed: {$package} ({$oldVersion} -> {$newVersion})",
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function diffJsonDeps(array $old, array $new, string $section, string $label, array &$changes): void
    {
        $oldDeps = $old[$section] ?? [];
        $newDeps = $new[$section] ?? [];

        $added = array_diff_key($newDeps, $oldDeps);
        $removed = array_diff_key($oldDeps, $newDeps);
        $common = array_intersect_key($oldDeps, $newDeps);

        foreach ($added as $package => $version) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::INFO,
                description: "NPM {$label} dependency added: {$package} ({$version})",
            );
        }

        foreach ($removed as $package => $version) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::MEDIUM,
                description: "NPM {$label} dependency removed: {$package}",
            );
        }

        foreach ($common as $package => $oldVersion) {
            $newVersion = $newDeps[$package];
            if ($oldVersion !== $newVersion) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::FILE_LEVEL,
                    severity: Severity::INFO,
                    description: "NPM {$label} dependency version changed: {$package} ({$oldVersion} -> {$newVersion})",
                );
            }
        }
    }
}
