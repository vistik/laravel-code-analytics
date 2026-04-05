<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;

class DiffParser
{
    /**
     * Parse unified diff text into FileDiff objects.
     *
     * @return list<FileDiff>
     */
    public function parse(string $diffText): array
    {
        $files = [];
        $lines = explode("\n", $diffText);
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            if (! str_starts_with($lines[$i], 'diff --git')) {
                $i++;

                continue;
            }

            $oldPath = '';
            $newPath = '';
            $status = FileStatus::MODIFIED;

            $i++;

            while ($i < $count && ! str_starts_with($lines[$i], 'diff --git')) {
                $line = $lines[$i];

                if (str_starts_with($line, 'new file mode')) {
                    $status = FileStatus::ADDED;
                } elseif (str_starts_with($line, 'deleted file mode')) {
                    $status = FileStatus::DELETED;
                } elseif (str_starts_with($line, 'similarity index')) {
                    $status = FileStatus::RENAMED;
                } elseif (str_starts_with($line, '--- ')) {
                    $oldPath = $this->extractPath($line, '--- ');
                } elseif (str_starts_with($line, '+++ ')) {
                    $newPath = $this->extractPath($line, '+++ ');
                } elseif (str_starts_with($line, '@@')) {
                    break;
                }

                $i++;
            }

            if ($oldPath !== '' || $newPath !== '') {
                $files[] = new FileDiff(
                    oldPath: $oldPath ?: $newPath,
                    newPath: $newPath ?: $oldPath,
                    status: $status,
                );
            }

            // Skip to next diff header
            while ($i < $count && ! str_starts_with($lines[$i], 'diff --git')) {
                $i++;
            }
        }

        return $files;
    }

    private function extractPath(string $line, string $prefix): string
    {
        $path = substr($line, strlen($prefix));

        if ($path === '/dev/null') {
            return '/dev/null';
        }

        // Remove the a/ or b/ prefix
        if (str_starts_with($path, 'a/') || str_starts_with($path, 'b/')) {
            $path = substr($path, 2);
        }

        return $path;
    }
}
