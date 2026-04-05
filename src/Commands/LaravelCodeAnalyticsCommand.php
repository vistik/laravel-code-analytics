<?php

namespace Vistik\LaravelCodeAnalytics\Commands;

use Illuminate\Console\Command;

class LaravelCodeAnalyticsCommand extends Command
{
    public $signature = 'laravel-code-analytics';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
