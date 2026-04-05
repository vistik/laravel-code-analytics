<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;

readonly class FileDiff
{
    public function __construct(
        public string $oldPath,
        public string $newPath,
        public FileStatus $status,
    ) {}

    public function isPhp(): bool
    {
        return str_ends_with($this->newPath, '.php')
            || str_ends_with($this->oldPath, '.php');
    }

    public function isJson(): bool
    {
        return str_ends_with($this->newPath, '.json')
            || str_ends_with($this->oldPath, '.json');
    }

    public function effectivePath(): string
    {
        return $this->newPath !== '/dev/null' ? $this->newPath : $this->oldPath;
    }
}
