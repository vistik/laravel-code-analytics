<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\AstComparer;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\FileStatus;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelNotificationRule;

it('detects via method changed', function () {
    $old = '<?php namespace App\Notifications; use Illuminate\Notifications\Notification; class InvoicePaid extends Notification { public function via($notifiable) { return ["mail"]; } public function toMail($notifiable) { return (new MailMessage)->line("Your invoice has been paid."); } }';
    $new = '<?php namespace App\Notifications; use Illuminate\Notifications\Notification; class InvoicePaid extends Notification { public function via($notifiable) { return ["mail", "database"]; } public function toMail($notifiable) { return (new MailMessage)->line("Your invoice has been paid."); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Notifications/InvoicePaid.php', 'app/Notifications/InvoicePaid.php', FileStatus::MODIFIED);

    $changes = (new LaravelNotificationRule)->analyze($file, $comparison);

    $viaChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'channels changed'));

    expect($viaChange)->not->toBeNull()
        ->and($viaChange->category)->toBe(ChangeCategory::LARAVEL)
        ->and($viaChange->severity)->toBe(Severity::VERY_HIGH)
        ->and($viaChange->description)->toContain('InvoicePaid::via');
});

it('detects toMail method changed', function () {
    $old = '<?php namespace App\Notifications; use Illuminate\Notifications\Notification; class InvoicePaid extends Notification { public function via($notifiable) { return ["mail"]; } public function toMail($notifiable) { return (new MailMessage)->line("Your invoice has been paid."); } }';
    $new = '<?php namespace App\Notifications; use Illuminate\Notifications\Notification; class InvoicePaid extends Notification { public function via($notifiable) { return ["mail"]; } public function toMail($notifiable) { return (new MailMessage)->line("Your invoice has been paid.")->action("View Invoice", "https://example.com"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Notifications/InvoicePaid.php', 'app/Notifications/InvoicePaid.php', FileStatus::MODIFIED);

    $changes = (new LaravelNotificationRule)->analyze($file, $comparison);

    $toMailChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'email template changed'));

    expect($toMailChange)->not->toBeNull()
        ->and($toMailChange->category)->toBe(ChangeCategory::LARAVEL)
        ->and($toMailChange->severity)->toBe(Severity::MEDIUM)
        ->and($toMailChange->description)->toContain('InvoicePaid::toMail');
});

it('detects mailable envelope changed', function () {
    $old = '<?php namespace App\Mail; use Illuminate\Mail\Mailable; use Illuminate\Mail\Mailables\Envelope; use Illuminate\Mail\Mailables\Content; class WelcomeMail extends Mailable { public function envelope() { return new Envelope(subject: "Welcome"); } public function content() { return new Content(view: "emails.welcome"); } }';
    $new = '<?php namespace App\Mail; use Illuminate\Mail\Mailable; use Illuminate\Mail\Mailables\Envelope; use Illuminate\Mail\Mailables\Content; class WelcomeMail extends Mailable { public function envelope() { return new Envelope(subject: "Welcome to Our App"); } public function content() { return new Content(view: "emails.welcome"); } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Mail/WelcomeMail.php', 'app/Mail/WelcomeMail.php', FileStatus::MODIFIED);

    $changes = (new LaravelNotificationRule)->analyze($file, $comparison);

    $envelopeChange = collect($changes)->first(fn ($c) => str_contains($c->description, 'envelope changed'));

    expect($envelopeChange)->not->toBeNull()
        ->and($envelopeChange->category)->toBe(ChangeCategory::LARAVEL)
        ->and($envelopeChange->severity)->toBe(Severity::MEDIUM)
        ->and($envelopeChange->description)->toContain('WelcomeMail::envelope');
});

it('ignores non-notification files', function () {
    $old = '<?php namespace App\Services; class InvoiceService { public function via($notifiable) { return ["mail"]; } }';
    $new = '<?php namespace App\Services; class InvoiceService { public function via($notifiable) { return ["mail", "database"]; } }';

    $comparer = new AstComparer;
    $comparison = $comparer->compare($old, $new);
    $file = new FileDiff('app/Services/InvoiceService.php', 'app/Services/InvoiceService.php', FileStatus::MODIFIED);

    $changes = (new LaravelNotificationRule)->analyze($file, $comparison);

    expect($changes)->toBeEmpty();
});
