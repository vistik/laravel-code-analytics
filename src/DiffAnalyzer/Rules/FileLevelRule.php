<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

class FileLevelRule implements Rule
{
    /** @var array<string, array{description: string, severity: Severity}> */
    private const PATH_PATTERNS = [
        'database/migrations/' => ['description' => 'Database migration', 'severity' => Severity::MEDIUM],
        'database/seeders/' => ['description' => 'Database seeder', 'severity' => Severity::INFO],
        'database/factories/' => ['description' => 'Model factory', 'severity' => Severity::INFO],
        'tests/' => ['description' => 'Test file', 'severity' => Severity::INFO],
        'config/' => ['description' => 'Configuration file', 'severity' => Severity::MEDIUM],
        'routes/' => ['description' => 'Route file', 'severity' => Severity::MEDIUM],
        'resources/views/' => ['description' => 'Blade view', 'severity' => Severity::INFO],
        'resources/js/' => ['description' => 'JavaScript asset', 'severity' => Severity::INFO],
        'resources/css/' => ['description' => 'CSS asset', 'severity' => Severity::INFO],
        'app/Http/Middleware/' => ['description' => 'Middleware', 'severity' => Severity::MEDIUM],
        'app/Http/Controllers/' => ['description' => 'Controller', 'severity' => Severity::MEDIUM],
        'app/Models/' => ['description' => 'Eloquent model', 'severity' => Severity::MEDIUM],
        'app/Policies/' => ['description' => 'Authorization policy', 'severity' => Severity::VERY_HIGH],
        'app/Providers/' => ['description' => 'Service provider', 'severity' => Severity::MEDIUM],
        'app/Jobs/' => ['description' => 'Queued job', 'severity' => Severity::MEDIUM],
        'app/Events/' => ['description' => 'Event class', 'severity' => Severity::INFO],
        'app/Listeners/' => ['description' => 'Event listener', 'severity' => Severity::MEDIUM],
        'app/Mail/' => ['description' => 'Mailable', 'severity' => Severity::MEDIUM],
        'app/Notifications/' => ['description' => 'Notification', 'severity' => Severity::INFO],
        'app/Console/Commands/' => ['description' => 'Artisan command', 'severity' => Severity::INFO],
        'bootstrap/' => ['description' => 'Bootstrap config', 'severity' => Severity::MEDIUM],
    ];

    /** @var array<string, array{description: string, severity: Severity}> */
    private const EXACT_FILES = [
        'composer.json' => ['description' => 'Composer dependencies', 'severity' => Severity::MEDIUM],
        'composer.lock' => ['description' => 'Composer lock file', 'severity' => Severity::INFO],
        'package.json' => ['description' => 'NPM dependencies', 'severity' => Severity::MEDIUM],
        '.env.example' => ['description' => 'Environment template', 'severity' => Severity::INFO],
        'phpunit.xml' => ['description' => 'PHPUnit configuration', 'severity' => Severity::INFO],
        'bootstrap/app.php' => ['description' => 'Application bootstrap', 'severity' => Severity::VERY_HIGH],
        'bootstrap/providers.php' => ['description' => 'Service providers', 'severity' => Severity::MEDIUM],
    ];

    public function shortDescription(): string
    {
        return 'Classifies files by path and detects additions, deletions, and renames';
    }

    public function description(): string
    {
        return 'Classifies files by path (migrations, tests, config, routes, etc.) and detects file-level status changes like additions, deletions, and renames.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];
        $path = $file->effectivePath();

        // File status
        if ($file->status === FileStatus::ADDED) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::INFO,
                description: "New file: {$path}",
            );
        } elseif ($file->status === FileStatus::DELETED) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::MEDIUM,
                description: "File deleted: {$path}",
            );
        } elseif ($file->status === FileStatus::RENAMED) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::MEDIUM,
                description: "File renamed: {$file->oldPath} -> {$file->newPath}",
            );
        }

        // Exact file matches first
        foreach (self::EXACT_FILES as $exactPath => $meta) {
            if ($path === $exactPath) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::FILE_LEVEL,
                    severity: $meta['severity'],
                    description: "{$meta['description']} modified",
                );

                return $changes;
            }
        }

        // Path pattern matches
        foreach (self::PATH_PATTERNS as $pattern => $meta) {
            if (str_contains($path, $pattern)) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::FILE_LEVEL,
                    severity: $meta['severity'],
                    description: "{$meta['description']} {$file->status->value}: ".basename($path),
                );

                break;
            }
        }

        // Parse errors
        if (! empty($comparison['old_parse_errors'])) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::MEDIUM,
                description: 'Parse errors in old version: '.implode(', ', $comparison['old_parse_errors']),
            );
        }

        if (! empty($comparison['new_parse_errors'])) {
            $changes[] = new ClassifiedChange(
                category: ChangeCategory::FILE_LEVEL,
                severity: Severity::MEDIUM,
                description: 'Parse errors in new version: '.implode(', ', $comparison['new_parse_errors']),
            );
        }

        return $changes;
    }
}
