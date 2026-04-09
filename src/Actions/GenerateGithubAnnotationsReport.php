<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

class GenerateGithubAnnotationsReport implements ReportGenerator
{
    public function generate(
        array $nodes,
        array $edges,
        array $fileDiffs,
        array $analysisData,
        string $title,
        string $repo,
        string $headCommit,
        int $prAdditions,
        int $prDeletions,
        int $fileCount,
        string $prUrl = '',
        ?RiskScore $riskScore = null,
        array $metricsData = [],
        array $fileContents = [],
    ): string {
        $lines = [];

        foreach ($analysisData as $filePath => $findings) {
            foreach ($findings as $finding) {
                $category = $finding['category'];
                $description = $this->escapeMessage($finding['description']);
                $location = $finding['location'] ?? null;
                $line = $finding['line'] ?? null;

                $title = $this->buildTitle($category, $location);
                $params = "file={$filePath}";

                if ($line !== null) {
                    $params .= ",line={$line}";
                }

                $params .= ",title={$title}";

                $level = $this->annotationLevel($finding['severity']);
                $lines[] = "::{$level} {$params}::{$description}";
            }
        }

        if ($riskScore !== null) {
            $label = $this->riskLabel($riskScore->score);
            $lines[] = "::notice title=Risk Score::Score: {$riskScore->score}/100 — {$label}";
        }

        return implode("\n", $lines);
    }

    public function writeFile(string $outputPath, string $content): void
    {
        file_put_contents($outputPath, $content);
    }

    private function annotationLevel(string $severity): string
    {
        return match ($severity) {
            'very_high', 'high' => 'error',
            'medium' => 'warning',
            default => 'notice',
        };
    }

    private function buildTitle(string $category, ?string $location): string
    {
        $label = str_replace(['_', '-'], ' ', $category);
        $label = ucwords($label);

        if ($location !== null) {
            $label .= ": {$location}";
        }

        return $this->escapeParam($label);
    }

    private function escapeMessage(string $message): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $message);
    }

    private function escapeParam(string $value): string
    {
        return str_replace(['%', ':', ','], ['%25', '%3A', '%2C'], $value);
    }

    private function riskLabel(int $score): string
    {
        return match (true) {
            $score >= 75 => 'Very High',
            $score >= 50 => 'High',
            $score >= 25 => 'Medium',
            default => 'Low',
        };
    }
}
