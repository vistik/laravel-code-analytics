<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts\FileGroupResolver;
use Vistik\LaravelCodeAnalytics\Enums\FileGroup;

class PatternBasedGroupResolver implements FileGroupResolver
{
    public function resolve(string $path): FileGroup
    {
        return match (true) {
            (bool) preg_match('#^tests/#', $path) => FileGroup::TEST,
            (bool) preg_match('#^database/#', $path) => FileGroup::DB,
            (bool) preg_match('#Nova/#', $path) => FileGroup::NOVA,
            (bool) preg_match('#app/Models/#', $path) => FileGroup::MODEL,
            (bool) preg_match('#app/Actions/#', $path) => FileGroup::ACTION,
            (bool) preg_match('#app/Jobs/#', $path) => FileGroup::JOB,
            (bool) preg_match('#app/Http/Controllers/#', $path) => FileGroup::CONTROLLER,
            (bool) preg_match('#app/Http/Requests/#', $path) => FileGroup::REQUEST,
            (bool) preg_match('#app/Http/Resources/#', $path) => FileGroup::REQUEST,
            (bool) preg_match('#app/Http/#', $path) => FileGroup::HTTP,
            (bool) preg_match('#app/Console/#', $path) => FileGroup::CONSOLE,
            (bool) preg_match('#app/Providers/#', $path) => FileGroup::PROVIDER,
            (bool) preg_match('#app/Exceptions/#', $path) => FileGroup::CORE,
            (bool) preg_match('#app/Enums/#', $path) => FileGroup::CORE,
            (bool) preg_match('#app/Events/#', $path) => FileGroup::EVENT,
            (bool) preg_match('#app/Listeners/#', $path) => FileGroup::EVENT,
            (bool) preg_match('#app/Services/#', $path) => FileGroup::SERVICE,
            (bool) preg_match('#resources/views/#', $path) => FileGroup::VIEW,
            (bool) preg_match('#resources/(js|css)/#', $path) => FileGroup::FRONTEND,
            (bool) preg_match('#config/#', $path) => FileGroup::CONFIG,
            (bool) preg_match('#routes/#', $path) => FileGroup::ROUTE,
            default => FileGroup::OTHER,
        };
    }
}
