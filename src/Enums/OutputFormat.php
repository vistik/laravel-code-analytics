<?php

namespace Vistik\LaravelCodeAnalytics\Enums;

use Vistik\LaravelCodeAnalytics\Actions\GenerateGithubAnnotationsReport;
use Vistik\LaravelCodeAnalytics\Actions\GenerateHtmlReport;
use Vistik\LaravelCodeAnalytics\Actions\GenerateJsonReport;
use Vistik\LaravelCodeAnalytics\Actions\GenerateLlmReport;
use Vistik\LaravelCodeAnalytics\Actions\GenerateMdReport;
use Vistik\LaravelCodeAnalytics\Actions\GenerateMetricsReport;
use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;

enum OutputFormat: string
{
    case HTML = 'html';
    case MARKDOWN = 'md';
    case JSON = 'json';
    case METRICS = 'metrics';
    case LLM = 'llm';
    case GITHUB = 'github';

    /** @param array<string, mixed> $options */
    public function generator(array $options = []): ReportGenerator
    {
        return match ($this) {
            self::HTML => new GenerateHtmlReport(aiReview: $options['aiReview'] ?? null),
            self::MARKDOWN => new GenerateMdReport,
            self::JSON => new GenerateJsonReport,
            self::METRICS => new GenerateMetricsReport,
            self::LLM => new GenerateLlmReport($options['focus'] ?? null),
            self::GITHUB => new GenerateGithubAnnotationsReport($options['metrics'] ?? false),
        };
    }

    public function fileExtension(): string
    {
        return match ($this) {
            self::HTML => 'html',
            self::MARKDOWN => 'md',
            self::JSON => 'json',
            self::METRICS => 'txt',
            self::LLM => 'txt',
            self::GITHUB => 'txt',
        };
    }
}
