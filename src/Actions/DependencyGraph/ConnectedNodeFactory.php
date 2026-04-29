<?php

namespace Vistik\LaravelCodeAnalytics\Actions\DependencyGraph;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Contracts\FileGroupResolver;

final class ConnectedNodeFactory
{
    public function __construct(
        private readonly FileGroupResolver $groupResolver,
    ) {}

    public function make(string $path, string $label): array
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: basename($path);
        $folder = dirname($path);
        $folder = (string) preg_replace('#^app/#', '', $folder);
        $folder = (string) preg_replace('#^tests/(Unit|Feature)/#', 'tests/', $folder);
        if ($folder === '.' || $folder === '') {
            $folder = '';
        }
        $domain = explode('/', $folder)[0] ?: '(root)';

        return [
            'id' => $label,
            'path' => $path,
            'add' => 0,
            'del' => 0,
            'status' => 'modified',
            'group' => $this->groupResolver->resolve($path)->value,
            'hash' => hash('sha256', $path),
            'ext' => $ext,
            'folder' => $folder,
            'domain' => $domain,
            'domainColor' => '#484f58',
            'isConnected' => true,
        ];
    }
}
