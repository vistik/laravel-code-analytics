<?php

use Vistik\LaravelCodeAnalytics\Support\PhpDependencyExtractor;

it('classifies constructor injection', function () {
    $code = <<<'PHP'
    <?php
    class OrderService {
        public function __construct(
            private PaymentGateway $gateway,
            private LoggerInterface $logger,
        ) {}
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['PaymentGateway'])->toBe(PhpDependencyExtractor::CONSTRUCTOR_INJECTION)
        ->and($refs['LoggerInterface'])->toBe(PhpDependencyExtractor::CONSTRUCTOR_INJECTION);
});

it('classifies method injection', function () {
    $code = <<<'PHP'
    <?php
    class ReportController {
        public function export(Request $request, Exporter $exporter): void {}
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['Exporter'])->toBe(PhpDependencyExtractor::METHOD_INJECTION);
});

it('classifies new instance', function () {
    $code = <<<'PHP'
    <?php
    class Factory {
        public function make(): Widget {
            return new Widget();
        }
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['Widget'])->toBe(PhpDependencyExtractor::NEW_INSTANCE);
});

it('classifies container resolved via app()', function () {
    $code = <<<'PHP'
    <?php
    class Bootstrapper {
        public function boot(): void {
            $gateway = app(PaymentGateway::class);
        }
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['PaymentGateway'])->toBe(PhpDependencyExtractor::CONTAINER_RESOLVED);
});

it('classifies container resolved via resolve()', function () {
    $code = <<<'PHP'
    <?php
    class Bootstrapper {
        public function boot(): void {
            $mailer = resolve(MailService::class);
        }
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['MailService'])->toBe(PhpDependencyExtractor::CONTAINER_RESOLVED);
});

it('classifies container resolved via App::make()', function () {
    $code = <<<'PHP'
    <?php
    class Bootstrapper {
        public function boot(): void {
            $service = App::make(CacheManager::class);
        }
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['CacheManager'])->toBe(PhpDependencyExtractor::CONTAINER_RESOLVED);
});

it('classifies static call', function () {
    $code = <<<'PHP'
    <?php
    class EventHandler {
        public function handle(): void {
            UserRepository::findById(1);
        }
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['UserRepository'])->toBe(PhpDependencyExtractor::STATIC_CALL);
});

it('classifies extends reference for extends', function () {
    $code = <<<'PHP'
    <?php
    class AdminController extends BaseController {}
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['BaseController'])->toBe(PhpDependencyExtractor::EXTENDS_REFERENCE);
});

it('classifies type reference for use import', function () {
    $code = <<<'PHP'
    <?php
    use App\Services\Billing\InvoiceService;
    class Dummy {}
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['App\\Services\\Billing\\InvoiceService'])->toBe(PhpDependencyExtractor::USE);
});

it('constructor injection wins over type reference for same class', function () {
    $code = <<<'PHP'
    <?php
    use App\Services\Mailer;
    class Notifier {
        public function __construct(private Mailer $mailer) {}
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    // constructor_injection beats use (from use statement)
    expect($refs['Mailer'])->toBe(PhpDependencyExtractor::CONSTRUCTOR_INJECTION);
});

it('constructor injection wins over new instance for same class', function () {
    $code = <<<'PHP'
    <?php
    class Repository {
        public function __construct(private Connection $conn) {}
        public function reconnect(): void {
            $this->conn = new Connection();
        }
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['Connection'])->toBe(PhpDependencyExtractor::CONSTRUCTOR_INJECTION);
});

it('classifies new instance when chained with method call via (new Foo())->method()', function () {
    $code = <<<'PHP'
    <?php
    class Factory {
        public function build(): string {
            return (new Builder())->serialize()->toString();
        }
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['Builder'])->toBe(PhpDependencyExtractor::NEW_INSTANCE);
});

it('classifies new instance when property accessed via (new Foo)->prop', function () {
    $code = <<<'PHP'
    <?php
    class Accessor {
        public function run(): mixed {
            return (new Config)->value;
        }
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['Config'])->toBe(PhpDependencyExtractor::NEW_INSTANCE);
});

it('classifies implements reference', function () {
    $code = <<<'PHP'
    <?php
    class OrderExporter implements Exportable {}
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['Exportable'])->toBe(PhpDependencyExtractor::IMPLEMENTS_REFERENCE);
});

it('classifies property type', function () {
    $code = <<<'PHP'
    <?php
    class UserRepository {
        private Connection $connection;
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['Connection'])->toBe(PhpDependencyExtractor::PROPERTY_TYPE);
});

it('classifies return type', function () {
    $code = <<<'PHP'
    <?php
    class UserFactory {
        public function make(): User {}
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['User'])->toBe(PhpDependencyExtractor::RETURN_TYPE);
});

it('property type wins over return type for same class', function () {
    $code = <<<'PHP'
    <?php
    class UserCache {
        private User $cached;
        public function get(): User {}
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs['User'])->toBe(PhpDependencyExtractor::PROPERTY_TYPE);
});

it('returns empty array for empty content', function () {
    expect((new PhpDependencyExtractor)->extract(''))->toBe([]);
});

it('ignores self, static, parent', function () {
    $code = <<<'PHP'
    <?php
    class Builder {
        public static function create(): static { return new static(); }
        public function clone(): self { return new self(); }
    }
    PHP;

    $refs = (new PhpDependencyExtractor)->extract($code);

    expect($refs)->not->toHaveKey('self')
        ->and($refs)->not->toHaveKey('static')
        ->and($refs)->not->toHaveKey('parent');
});
