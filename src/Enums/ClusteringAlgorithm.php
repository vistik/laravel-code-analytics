<?php

namespace Vistik\LaravelCodeAnalytics\Enums;

use Vistik\LaravelCodeAnalytics\Coupling\Clusterer;
use Vistik\LaravelCodeAnalytics\Coupling\DependencyClusterer;
use Vistik\LaravelCodeAnalytics\Coupling\NamedEntityClusterer;
use Vistik\LaravelCodeAnalytics\Coupling\WeightedCouplingClusterer;

enum ClusteringAlgorithm: string
{
    case Dependency = 'dependency';
    case Weighted = 'weighted';
    case Named = 'named';

    public function clusterer(): Clusterer
    {
        return match ($this) {
            self::Dependency => new DependencyClusterer,
            self::Weighted => new WeightedCouplingClusterer,
            self::Named => new NamedEntityClusterer,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Dependency => 'Dependency Graph',
            self::Weighted => 'Weighted Coupling',
            self::Named => 'Named Entity',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Dependency => 'Label propagation on the dependency graph (default)',
            self::Weighted => 'Pairwise coupling scores: mutual deps, shared deps',
            self::Named => 'Groups files by entity name: User, UserController, CreateUserRequest → User cluster',
        };
    }
}
