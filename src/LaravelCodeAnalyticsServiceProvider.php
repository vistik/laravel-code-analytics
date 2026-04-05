<?php

namespace Vistik\LaravelCodeAnalytics;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vistik\LaravelCodeAnalytics\Commands\LaravelCodeAnalyticsCommand;

class LaravelCodeAnalyticsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-code-analytics')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_code_analytics_table')
            ->hasCommand(LaravelCodeAnalyticsCommand::class);
    }
}
