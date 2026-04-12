<?php

namespace Vistik\LaravelCodeAnalytics;

use Illuminate\Support\ServiceProvider;
use Vistik\LaravelCodeAnalytics\Console\Commands\CodeAnalyzeCommand;
use Vistik\LaravelCodeAnalytics\Console\Commands\CodeFileCommand;
use Vistik\LaravelCodeAnalytics\Console\Commands\ListFindingsCommand;
use Vistik\LaravelCodeAnalytics\Console\Commands\TestCoverageCommand;

class LaravelCodeAnalyticsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CodeAnalyzeCommand::class,
                CodeFileCommand::class,
                ListFindingsCommand::class,
                TestCoverageCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/laravel-code-analytics.php' => config_path('laravel-code-analytics.php'),
            ], 'laravel-code-analytics-config');

            $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-code-analytics');
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laravel-code-analytics.php',
            'laravel-code-analytics'
        );
    }
}
