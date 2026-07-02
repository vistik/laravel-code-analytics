<?php

namespace Vistik\LaravelCodeAnalytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class ClearGitCacheCommand extends Command
{
    protected $signature = 'code:clear-cache
        {repo? : Only clear the cache for this repo (owner/repo, matches the --pr= / --repo= remote)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Clear the cached git objects and PR diffs fetched for --pr=/--repo= analysis';

    public function handle(Filesystem $files): int
    {
        $repo = $this->argument('repo');
        $gitObjectsDir = storage_path('app/git-objects');
        $prCacheDir = storage_path('app/pr-cache');

        $targets = $repo !== null
            ? $this->findBareReposForRemote($files, $gitObjectsDir, $repo)
            : array_filter([$gitObjectsDir, $prCacheDir], fn (string $dir) => $files->isDirectory($dir));

        if (empty($targets)) {
            $this->info($repo !== null ? "No cached git objects found for {$repo}." : 'Nothing to clear — cache is already empty.');

            return self::SUCCESS;
        }

        $size = array_sum(array_map(fn (string $dir) => $this->directorySize($files, $dir), $targets));

        if (! $this->option('force')) {
            $this->line(sprintf(
                'This will delete %s of cached git data%s.',
                $this->formatBytes($size),
                $repo !== null ? " for {$repo}" : '',
            ));

            if (! $this->confirm('Continue?')) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        foreach ($targets as $dir) {
            $files->deleteDirectory($dir);
        }

        $this->info(sprintf('Cleared %s of cached git data.', $this->formatBytes($size)));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function findBareReposForRemote(Filesystem $files, string $gitObjectsDir, string $repo): array
    {
        if (! $files->isDirectory($gitObjectsDir)) {
            return [];
        }

        $remoteUrl = "github.com/{$repo}";
        $matches = [];

        foreach ($files->directories($gitObjectsDir) as $shard) {
            foreach ($files->directories($shard) as $bareRepoDir) {
                $origin = trim(shell_exec('git -C '.escapeshellarg($bareRepoDir).' remote get-url origin 2>/dev/null') ?? '');

                if (str_contains($origin, $remoteUrl)) {
                    $matches[] = $bareRepoDir;
                }
            }
        }

        return $matches;
    }

    /**
     * Uses `du` rather than Filesystem::allFiles() — the cache can hold thousands of
     * loose git objects across hundreds of repos, which exhausts memory when the
     * whole tree is loaded into a Finder collection just to sum file sizes.
     */
    private function directorySize(Filesystem $files, string $dir): int
    {
        if (! $files->isDirectory($dir)) {
            return 0;
        }

        $output = trim(shell_exec('du -sk '.escapeshellarg($dir).' 2>/dev/null') ?? '');
        [$kilobytes] = array_pad(explode("\t", $output), 1, '0');

        return ((int) $kilobytes) * 1024;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;

        foreach ($units as $unit) {
            if ($value < 1024 || $unit === end($units)) {
                return round($value, 1).' '.$unit;
            }
            $value /= 1024;
        }

        return "{$bytes} B";
    }
}
