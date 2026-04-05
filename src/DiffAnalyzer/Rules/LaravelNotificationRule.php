<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelNotificationRule implements Rule
{
    use AnalyzesLaravelCode;

    /** @var list<string> */
    private const NOTIFICATION_METHODS = ['via', 'toMail', 'toSlack', 'toArray', 'toDatabase', 'toBroadcast', 'toVonage', 'toNexmo'];

    /** @var list<string> */
    private const MAILABLE_METHODS = ['content', 'envelope', 'attachments', 'headers', 'build'];

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects notification channel and mailable template changes';
    }

    public function description(): string
    {
        return 'Detects notification and mailable changes: notification channel (via) modifications, message template changes (toMail, toSlack, etc.), and mailable envelope/content/attachment changes.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];
        $path = $file->effectivePath();

        if ($this->pathContains($path, 'Notifications/')) {
            $this->analyzeNotification($comparison, $changes);
        }

        if ($this->pathContains($path, 'Mail/')) {
            $this->analyzeMailable($comparison, $changes);
        }

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeNotification(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            $methodName = $this->getMethodName($key);

            if (! in_array($methodName, self::NOTIFICATION_METHODS, true)) {
                continue;
            }

            if ($pair['old'] === null && $pair['new'] !== null) {
                $description = match ($methodName) {
                    'via' => "Notification channels defined: {$key}",
                    default => "Notification {$methodName}() added: {$key}",
                };

                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::INFO,
                    description: $description,
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );

                continue;
            }

            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldBody = $this->printer->prettyPrint($pair['old']->stmts ?? []);
            $newBody = $this->printer->prettyPrint($pair['new']->stmts ?? []);

            if ($oldBody === $newBody) {
                continue;
            }

            $severity = $methodName === 'via' ? Severity::VERY_HIGH : Severity::MEDIUM;
            $description = match ($methodName) {
                'via' => "Notification channels changed: {$key} — may affect delivery",
                'toMail' => "Notification email template changed: {$key}",
                'toSlack' => "Notification Slack message changed: {$key}",
                'toArray', 'toDatabase' => "Notification database representation changed: {$key}",
                default => "Notification {$methodName}() changed: {$key}",
            };

            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: $severity,
                description: $description,
                location: $key,
                line: $pair['new']->getStartLine(),
            );
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeMailable(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            $methodName = $this->getMethodName($key);

            if (! in_array($methodName, self::MAILABLE_METHODS, true)) {
                continue;
            }

            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldBody = $this->printer->prettyPrint($pair['old']->stmts ?? []);
            $newBody = $this->printer->prettyPrint($pair['new']->stmts ?? []);

            if ($oldBody === $newBody) {
                continue;
            }

            $description = match ($methodName) {
                'envelope' => "Mailable envelope changed (subject, from, to): {$key}",
                'content' => "Mailable content/view changed: {$key}",
                'attachments' => "Mailable attachments changed: {$key}",
                'headers' => "Mailable headers changed: {$key}",
                'build' => "Mailable build() changed: {$key}",
                default => "Mailable {$methodName}() changed: {$key}",
            };

            $changes[] = new ClassifiedChange(
                category: ChangeCategory::LARAVEL,
                severity: Severity::MEDIUM,
                description: $description,
                location: $key,
                line: $pair['new']->getStartLine(),
            );
        }
    }
}
