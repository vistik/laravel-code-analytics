<?php

use Vistik\LaravelCodeAnalytics\Actions\AnalyzeCode;
use Vistik\LaravelCodeAnalytics\Enums\OutputFormat;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;

// ── Helpers ───────────────────────────────────────────────────────────────────

function endpointsTempRepo(): string
{
    $dir = sys_get_temp_dir().'/endpoints-test-'.uniqid();
    mkdir($dir, 0755, true);

    shell_exec("git -C {$dir} init 2>&1");
    shell_exec("git -C {$dir} config user.email 'test@example.com' 2>&1");
    shell_exec("git -C {$dir} config user.name 'Test' 2>&1");

    file_put_contents("{$dir}/README.md", '# Test');
    shell_exec("git -C {$dir} add . 2>&1");
    shell_exec("git -C {$dir} commit -m 'initial' 2>&1");
    shell_exec("git -C {$dir} branch -m main 2>&1");

    return $dir;
}

function stageEndpointFile(string $dir, string $path, string $content): void
{
    $fullPath = "{$dir}/{$path}";
    $parentDir = dirname($fullPath);

    if (! is_dir($parentDir)) {
        mkdir($parentDir, 0755, true);
    }

    file_put_contents($fullPath, $content);
    shell_exec("git -C {$dir} add ".escapeshellarg($path).' 2>&1');
}

/** Run AnalyzeCode and return the captured GraphPayload (or null if not populated). */
function runAndCapturePayload(string $dir): ?GraphPayload
{
    $captured = null;

    (new AnalyzeCode)->execute(
        repoPath: $dir,
        format: OutputFormat::JSON,
        raw: true,
        onPayloadReady: function (GraphPayload $payload) use (&$captured) {
            $captured = $payload;
        },
    );

    return $captured;
}

function removeEndpointsDir(string $dir): void
{
    shell_exec('rm -rf '.escapeshellarg($dir));
}

// ── Affected endpoints when controller is directly changed ────────────────────

describe('affected endpoints — controller changed directly', function () {
    it('detects an affected endpoint when the controller itself is in the diff', function () {
        $dir = endpointsTempRepo();

        stageEndpointFile($dir, 'routes/web.php', '<?php
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
Route::get(\'/users\', [UserController::class, \'index\']);
');

        stageEndpointFile($dir, 'app/Http/Controllers/UserController.php', '<?php
namespace App\Http\Controllers;
class UserController {
    public function index() {}
}
');

        $payload = runAndCapturePayload($dir);
        removeEndpointsDir($dir);

        expect($payload)->not->toBeNull();

        $uris = array_column($payload->affectedEndpoints, 'uri');
        expect($uris)->toContain('/users');

        $endpoint = collect($payload->affectedEndpoints)->firstWhere('uri', '/users');
        expect($endpoint['method'])->toBe('GET')
            ->and($endpoint['handlerMethod'])->toBe('index');
    });
});

// ── Affected endpoints when a FormRequest is changed ─────────────────────────

describe('affected endpoints — FormRequest changed', function () {
    it('detects the endpoint when only the FormRequest is in the diff', function () {
        $dir = endpointsTempRepo();

        // Commit the controller and route so they are NOT in the diff
        stageEndpointFile($dir, 'app/Http/Requests/StoreUserRequest.php', '<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreUserRequest extends FormRequest {
    public function rules(): array { return [\'name\' => \'required\']; }
}
');
        stageEndpointFile($dir, 'app/Http/Controllers/UserController.php', '<?php
namespace App\Http\Controllers;
use App\Http\Requests\StoreUserRequest;
class UserController {
    public function store(StoreUserRequest $request) {}
}
');
        stageEndpointFile($dir, 'routes/web.php', '<?php
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
Route::post(\'/users\', [UserController::class, \'store\']);
');
        shell_exec("git -C {$dir} commit -m 'baseline' 2>&1");

        // Only change the FormRequest
        stageEndpointFile($dir, 'app/Http/Requests/StoreUserRequest.php', '<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreUserRequest extends FormRequest {
    public function rules(): array { return [\'name\' => \'required\', \'email\' => \'required|email\']; }
}
');

        $payload = runAndCapturePayload($dir);
        removeEndpointsDir($dir);

        expect($payload)->not->toBeNull();

        $uris = array_column($payload->affectedEndpoints, 'uri');
        expect($uris)->toContain('/users');

        $endpoint = collect($payload->affectedEndpoints)->firstWhere('uri', '/users');
        expect($endpoint['method'])->toBe('POST')
            ->and($endpoint['handlerMethod'])->toBe('store')
            ->and($endpoint['triggeredByPath'])->toBe('app/Http/Requests/StoreUserRequest.php');
    });

    it('includes the dependency chain from request to controller', function () {
        $dir = endpointsTempRepo();

        stageEndpointFile($dir, 'app/Http/Requests/UpdatePostRequest.php', '<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdatePostRequest extends FormRequest {
    public function rules(): array { return [\'title\' => \'required\']; }
}
');
        stageEndpointFile($dir, 'app/Http/Controllers/PostController.php', '<?php
namespace App\Http\Controllers;
use App\Http\Requests\UpdatePostRequest;
class PostController {
    public function update(UpdatePostRequest $request, int $id) {}
}
');
        stageEndpointFile($dir, 'routes/web.php', '<?php
use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;
Route::put(\'/posts/{id}\', [PostController::class, \'update\']);
');
        shell_exec("git -C {$dir} commit -m 'baseline' 2>&1");

        stageEndpointFile($dir, 'app/Http/Requests/UpdatePostRequest.php', '<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdatePostRequest extends FormRequest {
    public function rules(): array { return [\'title\' => \'required|string|max:255\']; }
}
');

        $payload = runAndCapturePayload($dir);
        removeEndpointsDir($dir);

        expect($payload)->not->toBeNull();

        $endpoint = collect($payload->affectedEndpoints)->firstWhere('uri', '/posts/{id}');
        expect($endpoint)->not->toBeNull();

        $chain = $endpoint['dependencyChain'];
        expect($chain)->toContain('app/Http/Requests/UpdatePostRequest.php')
            ->and($chain)->toContain('app/Http/Controllers/PostController.php');
    });
});

// ── Affected endpoints when a Service is changed ──────────────────────────────

describe('affected endpoints — Service changed', function () {
    it('detects the endpoint when a service injected by the controller changes', function () {
        $dir = endpointsTempRepo();

        stageEndpointFile($dir, 'app/Services/UserService.php', '<?php
namespace App\Services;
class UserService {
    public function all(): array { return []; }
}
');
        stageEndpointFile($dir, 'app/Http/Controllers/UserController.php', '<?php
namespace App\Http\Controllers;
use App\Services\UserService;
class UserController {
    public function __construct(private UserService $service) {}
    public function index() {}
}
');
        stageEndpointFile($dir, 'routes/web.php', '<?php
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
Route::get(\'/users\', [UserController::class, \'index\']);
');
        shell_exec("git -C {$dir} commit -m 'baseline' 2>&1");

        // Only change the service
        stageEndpointFile($dir, 'app/Services/UserService.php', '<?php
namespace App\Services;
class UserService {
    public function all(): array { return [1, 2, 3]; }
}
');

        $payload = runAndCapturePayload($dir);
        removeEndpointsDir($dir);

        expect($payload)->not->toBeNull();

        $uris = array_column($payload->affectedEndpoints, 'uri');
        expect($uris)->toContain('/users');
    });
});

// ── Unrelated controller is NOT shown ────────────────────────────────────────

describe('affected endpoints — unrelated controller excluded', function () {
    it('does not show an endpoint whose controller does not use the changed file', function () {
        $dir = endpointsTempRepo();

        stageEndpointFile($dir, 'app/Services/SpecialService.php', '<?php
namespace App\Services;
class SpecialService {}
');
        // OrderController uses SpecialService; UserController does NOT
        stageEndpointFile($dir, 'app/Http/Controllers/OrderController.php', '<?php
namespace App\Http\Controllers;
use App\Services\SpecialService;
class OrderController {
    public function __construct(private SpecialService $svc) {}
    public function index() {}
}
');
        stageEndpointFile($dir, 'app/Http/Controllers/UserController.php', '<?php
namespace App\Http\Controllers;
class UserController {
    public function index() {}
}
');
        stageEndpointFile($dir, 'routes/web.php', '<?php
use App\Http\Controllers\OrderController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
Route::get(\'/orders\', [OrderController::class, \'index\']);
Route::get(\'/users\', [UserController::class, \'index\']);
');
        shell_exec("git -C {$dir} commit -m 'baseline' 2>&1");

        // Only change SpecialService (used by OrderController, not UserController)
        stageEndpointFile($dir, 'app/Services/SpecialService.php', '<?php
namespace App\Services;
class SpecialService { public function doSomething(): void {} }
');

        $payload = runAndCapturePayload($dir);
        removeEndpointsDir($dir);

        expect($payload)->not->toBeNull();

        $uris = array_column($payload->affectedEndpoints, 'uri');
        expect($uris)->toContain('/orders')
            ->and($uris)->not->toContain('/users');
    });
});

// ── Multiple endpoints on one controller ─────────────────────────────────────

describe('affected endpoints — multiple methods on one controller', function () {
    it('reports all endpoints for a controller when its request changes', function () {
        $dir = endpointsTempRepo();

        stageEndpointFile($dir, 'app/Http/Requests/ProductRequest.php', '<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class ProductRequest extends FormRequest {
    public function rules(): array { return [\'name\' => \'required\']; }
}
');
        stageEndpointFile($dir, 'app/Http/Controllers/ProductController.php', '<?php
namespace App\Http\Controllers;
use App\Http\Requests\ProductRequest;
class ProductController {
    public function store(ProductRequest $request) {}
    public function update(ProductRequest $request, int $id) {}
}
');
        stageEndpointFile($dir, 'routes/web.php', '<?php
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;
Route::post(\'/products\', [ProductController::class, \'store\']);
Route::put(\'/products/{id}\', [ProductController::class, \'update\']);
');
        shell_exec("git -C {$dir} commit -m 'baseline' 2>&1");

        stageEndpointFile($dir, 'app/Http/Requests/ProductRequest.php', '<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class ProductRequest extends FormRequest {
    public function rules(): array { return [\'name\' => \'required|string\']; }
}
');

        $payload = runAndCapturePayload($dir);
        removeEndpointsDir($dir);

        expect($payload)->not->toBeNull();

        $uris = array_column($payload->affectedEndpoints, 'uri');
        expect($uris)->toContain('/products')
            ->and($uris)->toContain('/products/{id}');
    });
});

// ── No endpoints when no routes file exists ───────────────────────────────────

describe('affected endpoints — no routes', function () {
    it('returns no affected endpoints when the repo has no routes directory', function () {
        $dir = endpointsTempRepo();

        stageEndpointFile($dir, 'app/Http/Controllers/UserController.php', '<?php
namespace App\Http\Controllers;
class UserController {
    public function index() {}
}
');

        $payload = runAndCapturePayload($dir);
        removeEndpointsDir($dir);

        expect($payload)->not->toBeNull()
            ->and($payload->affectedEndpoints)->toBeEmpty();
    });
});
