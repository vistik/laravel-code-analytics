<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts\FileGroupResolver;
use Vistik\LaravelCodeAnalytics\Enums\NodeGroup;

class PatternBasedGroupResolver implements FileGroupResolver
{
    public function resolve(string $path): NodeGroup
    {
        return match (true) {
            (bool) preg_match('#^tests/#', $path) => NodeGroup::TEST,
            (bool) preg_match('#^database/#', $path) => NodeGroup::DB,
            (bool) preg_match('#Nova/#', $path) => NodeGroup::NOVA,
            (bool) preg_match('#app/Models/#', $path) => NodeGroup::MODEL,
            (bool) preg_match('#app/Actions/#', $path) => NodeGroup::ACTION,
            (bool) preg_match('#app/Jobs/#', $path) => NodeGroup::JOB,
            (bool) preg_match('#app/Http/Controllers/#', $path) => NodeGroup::CONTROLLER,
            (bool) preg_match('#app/Http/Requests/#', $path) => NodeGroup::REQUEST,
            (bool) preg_match('#app/Http/Resources/#', $path) => NodeGroup::REQUEST,
            (bool) preg_match('#app/Http/#', $path) => NodeGroup::HTTP,
            (bool) preg_match('#app/Console/#', $path) => NodeGroup::CONSOLE,
            (bool) preg_match('#app/Providers/#', $path) => NodeGroup::PROVIDER,
            (bool) preg_match('#app/Exceptions/#', $path) => NodeGroup::CORE,
            (bool) preg_match('#app/Enums/#', $path) => NodeGroup::CORE,
            (bool) preg_match('#app/Events/#', $path) => NodeGroup::EVENT,
            (bool) preg_match('#app/Listeners/#', $path) => NodeGroup::EVENT,
            (bool) preg_match('#app/Services/#', $path) => NodeGroup::SERVICE,
            (bool) preg_match('#resources/views/#', $path) => NodeGroup::VIEW,
            (bool) preg_match('#resources/(js|css)/#', $path) => NodeGroup::FRONTEND,
            (bool) preg_match('#config/#', $path) => NodeGroup::CONFIG,
            (bool) preg_match('#routes/#', $path) => NodeGroup::ROUTE,
            default => NodeGroup::OTHER,
        };
    }
}
