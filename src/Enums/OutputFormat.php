<?php

namespace Vistik\LaravelCodeAnalytics\Enums;

use Vistik\LaravelCodeAnalytics\Actions\GenerateHtmlReport;
use Vistik\LaravelCodeAnalytics\Actions\GenerateJsonReport;
use Vistik\LaravelCodeAnalytics\Actions\GenerateMdReport;
use Vistik\LaravelCodeAnalytics\Actions\GenerateMetricsDetailsReport;
use Vistik\LaravelCodeAnalytics\Actions\GenerateMetricsReport;
use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;

enum OutputFormat: string
{
    case HTML = 'html';
    case MARKDOWN = 'md';
    case JSON = 'json';
    case METRICS = 'metrics';
    case METRICS_DETAILS = 'metrics-details';

    public function generator(): ReportGenerator
    {
        return match ($this) {
            self::HTML => new GenerateHtmlReport,
            self::MARKDOWN => new GenerateMdReport,
            self::JSON => new GenerateJsonReport,
            self::METRICS => new GenerateMetricsReport,
            self::METRICS_DETAILS => new GenerateMetricsDetailsReport,
        };
    }

    public function fileExtension(): string
    {
        return match ($this) {
            self::HTML => 'html',
            self::MARKDOWN => 'md',
            self::JSON => 'json',
            self::METRICS => 'txt',
            self::METRICS_DETAILS => 'txt',
        };
    }
}
