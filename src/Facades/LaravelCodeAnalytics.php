<?php

namespace Vistik\LaravelCodeAnalytics\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Vistik\LaravelCodeAnalytics\LaravelCodeAnalytics
 */
class LaravelCodeAnalytics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vistik\LaravelCodeAnalytics\LaravelCodeAnalytics::class;
    }
}
