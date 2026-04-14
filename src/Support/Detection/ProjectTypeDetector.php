<?php

namespace Vistik\LaravelCodeAnalytics\Support\Detection;

class ProjectTypeDetector
{
    /** @var list<array{detector: Detector, type: ProjectType}> */
    private array $detectors;

    /**
     * @param  array<class-string<Detector>, ProjectType>|null  $map
     */
    public function __construct(?array $map = null)
    {
        $map ??= config('laravel-code-analytics.detectors', [
            LaravelAppDetector::class => ProjectType::LaravelApp,
            LaravelPackageDetector::class => ProjectType::LaravelPackage,
            PhpPackageDetector::class => ProjectType::PhpPackage,
        ]);

        $this->detectors = [];
        foreach ($map as $class => $type) {
            $this->detectors[] = ['detector' => new $class, 'type' => $type];
        }
    }

    public function fromFilesystem(string $repoPath): ProjectType
    {
        return $this->resolve(RepoContext::filesystem($repoPath));
    }

    public function fromGit(string $gitDir, string $commit): ProjectType
    {
        return $this->resolve(RepoContext::git($gitDir, $commit));
    }

    private function resolve(RepoContext $context): ProjectType
    {
        foreach ($this->detectors as $entry) {
            if ($entry['detector']->detect($context)) {
                return $entry['type'];
            }
        }

        return ProjectType::Unknown;
    }
}
