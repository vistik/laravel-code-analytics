<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer;

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;

/**
 * Catalog of all finding types the analyzer can produce,
 * grouped by severity with example before/after code.
 *
 * Used by the `code:findings` command for severity review and calibration.
 */
class FindingsCatalog
{
    /**
     * @return list<array{rule: string, severity: Severity, title: string, description: string, before: string, after: string}>
     */
    public static function all(): array
    {
        return array_merge(
            self::veryHigh(),
            self::high(),
            self::medium(),
            self::low(),
            self::info(),
        );
    }

    /**
     * @return list<array{rule: string, severity: Severity, title: string, description: string, before: string, after: string}>
     */
    public static function bySeverity(Severity $severity): array
    {
        return array_filter(self::all(), fn ($f) => $f['severity'] === $severity);
    }

    /**
     * @return list<array{rule: string, severity: Severity, title: string, description: string, before: string, after: string}>
     */
    private static function veryHigh(): array
    {
        return [
            // ──────────────────────────────────────────────────────────────
            // DB / Schema migrations
            // ──────────────────────────────────────────────────────────────
            [
                'rule' => 'LaravelMigrationRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Migration drops table',
                'description' => 'Schema::drop() or Schema::dropIfExists() is called — destroys the table and all its data irreversibly.',
                'before' => <<<'PHP'
                    // Migration did not exist before
                    PHP,
                'after' => <<<'PHP'
                    Schema::dropIfExists('invoices');
                    PHP,
            ],
            [
                'rule' => 'LaravelMigrationRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Migration renames table',
                'description' => 'Schema::rename() is called — any code referencing the old table name will break.',
                'before' => <<<'PHP'
                    // table was 'orders'
                    PHP,
                'after' => <<<'PHP'
                    Schema::rename('orders', 'purchase_orders');
                    PHP,
            ],
            [
                'rule' => 'LaravelMigrationRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Migration drops column',
                'description' => '$table->dropColumn() removes a column and its data permanently.',
                'before' => <<<'PHP'
                    Schema::table('users', function (Blueprint $table) {
                        // no drop
                    });
                    PHP,
                'after' => <<<'PHP'
                    Schema::table('users', function (Blueprint $table) {
                        $table->dropColumn('legacy_token');
                    });
                    PHP,
            ],
            [
                'rule' => 'LaravelMigrationRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Migration renames column',
                'description' => '$table->renameColumn() — any raw queries or code using the old column name will break.',
                'before' => <<<'PHP'
                    Schema::table('users', function (Blueprint $table) {
                        // no rename
                    });
                    PHP,
                'after' => <<<'PHP'
                    Schema::table('users', function (Blueprint $table) {
                        $table->renameColumn('name', 'full_name');
                    });
                    PHP,
            ],

            // ──────────────────────────────────────────────────────────────
            // Eloquent model structure
            // ──────────────────────────────────────────────────────────────
            [
                'rule' => 'LaravelEloquentRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Eloquent $fillable or $guarded changed',
                'description' => 'Mass-assignable fields changed — may open or close mass-assignment vectors.',
                'before' => <<<'PHP'
                    protected $fillable = ['name', 'email'];
                    PHP,
                'after' => <<<'PHP'
                    protected $fillable = ['name', 'email', 'role'];
                    PHP,
            ],
            [
                'rule' => 'LaravelEloquentRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Eloquent $table changed',
                'description' => 'The model now points to a different database table — all queries will hit the new table.',
                'before' => <<<'PHP'
                    protected $table = 'users';
                    PHP,
                'after' => <<<'PHP'
                    protected $table = 'accounts';
                    PHP,
            ],
            [
                'rule' => 'LaravelEloquentRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Eloquent $primaryKey changed',
                'description' => 'The primary key column has changed — find/update/delete by ID will query a different column.',
                'before' => <<<'PHP'
                    protected $primaryKey = 'id';
                    PHP,
                'after' => <<<'PHP'
                    protected $primaryKey = 'uuid';
                    PHP,
            ],
            [
                'rule' => 'LaravelEloquentRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'SoftDeletes removed from model',
                'description' => 'The SoftDeletes trait was removed — deletes are now hard deletes. Existing soft-deleted rows may become accessible.',
                'before' => <<<'PHP'
                    use SoftDeletes;
                    PHP,
                'after' => <<<'PHP'
                    // SoftDeletes trait removed
                    PHP,
            ],
            [
                'rule' => 'LaravelEloquentRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Eloquent relationship removed',
                'description' => 'A relationship method was removed — any code calling it will throw a BadMethodCallException.',
                'before' => <<<'PHP'
                    public function orders(): HasMany
                    {
                        return $this->hasMany(Order::class);
                    }
                    PHP,
                'after' => <<<'PHP'
                    // orders() relationship deleted
                    PHP,
            ],
            [
                'rule' => 'LaravelEloquentRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Eloquent relationship type changed',
                'description' => 'hasMany changed to hasOne (or similar) — callers expecting a collection will receive a model instance.',
                'before' => <<<'PHP'
                    public function orders(): HasMany
                    {
                        return $this->hasMany(Order::class);
                    }
                    PHP,
                'after' => <<<'PHP'
                    public function orders(): HasOne
                    {
                        return $this->hasOne(Order::class);
                    }
                    PHP,
            ],
            [
                'rule' => 'LaravelEloquentRule',
                'severity' => Severity::HIGH,
                'title' => 'Eloquent relationship added',
                'description' => 'A new relationship method was added — may cause eager loading or N+1 query impacts.',
                'before' => <<<'PHP'
                    // no relationship
                    PHP,
                'after' => <<<'PHP'
                    public function tags(): BelongsToMany
                    {
                        return $this->belongsToMany(Tag::class);
                    }
                    PHP,
            ],

            // ──────────────────────────────────────────────────────────────
            // Auth / security
            // ──────────────────────────────────────────────────────────────
            [
                'rule' => 'LaravelUnauthorizedRouteRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Auth middleware removed from route',
                'description' => 'A route that was previously protected by auth middleware is now publicly accessible.',
                'before' => <<<'PHP'
                    Route::get('/admin/users', [UserController::class, 'index'])
                        ->middleware(['auth', 'admin']);
                    PHP,
                'after' => <<<'PHP'
                    Route::get('/admin/users', [UserController::class, 'index']);
                    PHP,
            ],
            [
                'rule' => 'LaravelAuthRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'FormRequest authorize() removed',
                'description' => 'The authorize() method was deleted from a FormRequest — the authorization check no longer runs.',
                'before' => <<<'PHP'
                    public function authorize(): bool
                    {
                        return $this->user()->can('update', $this->post);
                    }
                    PHP,
                'after' => <<<'PHP'
                    // authorize() removed
                    PHP,
            ],
            [
                'rule' => 'LaravelAuthRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'FormRequest authorize() logic changed',
                'description' => 'The body of authorize() was changed — the authorization decision may have changed.',
                'before' => <<<'PHP'
                    public function authorize(): bool
                    {
                        return $this->user()->isAdmin();
                    }
                    PHP,
                'after' => <<<'PHP'
                    public function authorize(): bool
                    {
                        return true;
                    }
                    PHP,
            ],
            [
                'rule' => 'LaravelAuthRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Authentication config changed',
                'description' => 'config/auth.php was modified — guards, providers, or password reset settings may have changed.',
                'before' => <<<'PHP'
                    'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
                    PHP,
                'after' => <<<'PHP'
                    'guards' => ['web' => ['driver' => 'token', 'provider' => 'users']],
                    PHP,
            ],

            // ──────────────────────────────────────────────────────────────
            // Logic / values
            // ──────────────────────────────────────────────────────────────
            [
                'rule' => 'OperatorRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Comparison operator changed',
                'description' => 'A comparison operator was changed (e.g. >= to >) — boundary conditions behave differently.',
                'before' => <<<'PHP'
                    if ($attempts >= $this->maxAttempts) {
                    PHP,
                'after' => <<<'PHP'
                    if ($attempts > $this->maxAttempts) {
                    PHP,
            ],
            [
                'rule' => 'OperatorRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Logical operator changed',
                'description' => '&& changed to || (or vice versa) — the compound condition now evaluates completely differently.',
                'before' => <<<'PHP'
                    if ($user->isActive() && $user->hasPermission('admin')) {
                    PHP,
                'after' => <<<'PHP'
                    if ($user->isActive() || $user->hasPermission('admin')) {
                    PHP,
            ],
            [
                'rule' => 'OperatorRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Operands swapped',
                'description' => 'Left and right operands of an operator were swapped — for non-commutative ops this changes the result.',
                'before' => <<<'PHP'
                    if ($a > $b) {
                    PHP,
                'after' => <<<'PHP'
                    if ($b > $a) {
                    PHP,
            ],
            [
                'rule' => 'AssignmentRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Compound assignment operator changed',
                'description' => 'A compound assignment operator was changed (e.g. += to -=) — accumulation becomes subtraction.',
                'before' => <<<'PHP'
                    $total += $lineItem->amount;
                    PHP,
                'after' => <<<'PHP'
                    $total -= $lineItem->amount;
                    PHP,
            ],
            // ──────────────────────────────────────────────────────────────
            // Enums
            // ──────────────────────────────────────────────────────────────
            [
                'rule' => 'EnumRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Enum case value changed',
                'description' => 'The backing value of an enum case changed — database rows or serialised values storing the old value are now orphaned.',
                'before' => <<<'PHP'
                    case Active = 'active';
                    PHP,
                'after' => <<<'PHP'
                    case Active = 'enabled';
                    PHP,
            ],
            [
                'rule' => 'EnumRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Enum backed type changed',
                'description' => 'The enum\'s backing type changed (e.g. string → int) — all stored/serialised values are now invalid.',
                'before' => <<<'PHP'
                    enum Status: string { case Active = 'active'; }
                    PHP,
                'after' => <<<'PHP'
                    enum Status: int { case Active = 1; }
                    PHP,
            ],

            // ──────────────────────────────────────────────────────────────
            // Serialisation
            // ──────────────────────────────────────────────────────────────
            [
                'rule' => 'MagicMethodRule',
                'severity' => Severity::VERY_HIGH,
                'title' => '__serialize() changed',
                'description' => 'The serialisation format of this class changed — objects serialised with the old format cannot be deserialised.',
                'before' => <<<'PHP'
                    public function __serialize(): array
                    {
                        return ['id' => $this->id, 'data' => $this->data];
                    }
                    PHP,
                'after' => <<<'PHP'
                    public function __serialize(): array
                    {
                        return ['id' => $this->id, 'payload' => $this->data];
                    }
                    PHP,
            ],

            // ──────────────────────────────────────────────────────────────
            // File-level critical paths
            // ──────────────────────────────────────────────────────────────
            [
                'rule' => 'FileLevelRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'bootstrap/app.php modified',
                'description' => 'The application bootstrap file changed — middleware, exception handling, or service provider registration may be affected globally.',
                'before' => <<<'PHP'
                    $app->singleton(ExceptionHandler::class, Handler::class);
                    PHP,
                'after' => <<<'PHP'
                    $app->singleton(ExceptionHandler::class, CustomHandler::class);
                    PHP,
            ],
            [
                'rule' => 'FileLevelRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Authorization policy file modified',
                'description' => 'A file in app/Policies/ changed — authorization rules for one or more models may have changed.',
                'before' => <<<'PHP'
                    public function update(User $user, Post $post): bool
                    {
                        return $user->id === $post->user_id;
                    }
                    PHP,
                'after' => <<<'PHP'
                    public function update(User $user, Post $post): bool
                    {
                        return true;
                    }
                    PHP,
            ],

            // ──────────────────────────────────────────────────────────────
            // Timezone
            // ──────────────────────────────────────────────────────────────
            [
                'rule' => 'DateTimeRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Timezone override added',
                'description' => 'date_default_timezone_set() was added — all datetime operations in the process now use the new timezone.',
                'before' => <<<'PHP'
                    // no timezone override
                    PHP,
                'after' => <<<'PHP'
                    date_default_timezone_set('America/New_York');
                    PHP,
            ],

            // ──────────────────────────────────────────────────────────────
            // Security-sensitive dependencies
            // ──────────────────────────────────────────────────────────────
            [
                'rule' => 'DependencyRule',
                'severity' => Severity::VERY_HIGH,
                'title' => 'Security-sensitive package added/removed',
                'description' => 'A security-critical package (laravel/sanctum, laravel/passport, spatie/laravel-permission, tymon/jwt-auth) was added or removed.',
                'before' => <<<'PHP'
                    // composer.json — no auth package
                    PHP,
                'after' => <<<'PHP'
                    "require": {
                        "laravel/sanctum": "^3.0"
                    }
                    PHP,
            ],
        ];
    }

    /**
     * @return list<array{rule: string, severity: Severity, title: string, description: string, before: string, after: string}>
     */
    private static function high(): array
    {
        return [
            // ──────────────────────────────────────────────────────────────
            // Method / class structure
            // ──────────────────────────────────────────────────────────────
            [
                'rule' => 'MethodRemovedRule',
                'severity' => Severity::HIGH,
                'title' => 'Method removed',
                'description' => 'A public or protected method was deleted — any callers will receive a fatal error.',
                'before' => <<<'PHP'
                    public function calculateTotal(): float
                    {
                        return $this->subtotal + $this->tax;
                    }
                    PHP,
                'after' => <<<'PHP'
                    // calculateTotal() deleted
                    PHP,
            ],
            [
                'rule' => 'OperatorRule',
                'severity' => Severity::HIGH,
                'title' => 'Negation added',
                'description' => 'A ! (not) was added to an expression — the condition is now inverted.',
                'before' => <<<'PHP'
                    if ($user->can('delete')) {
                    PHP,
                'after' => <<<'PHP'
                    if (!$user->can('delete')) {
                    PHP,
            ],
            [
                'rule' => 'ValueRule',
                'severity' => Severity::HIGH,
                'title' => 'Boolean literal flipped',
                'description' => 'A true literal was changed to false (or vice versa) — the guarding or enabling condition is now inverted.',
                'before' => <<<'PHP'
                    protected bool $timestamps = true;
                    PHP,
                'after' => <<<'PHP'
                    protected bool $timestamps = false;
                    PHP,
            ],
            [
                'rule' => 'ControlFlowRule',
                'severity' => Severity::HIGH,
                'title' => 'Return statement added or removed',
                'description' => 'A return was inserted (early exit) or removed (falls through) — the control flow of the method changed.',
                'before' => <<<'PHP'
                    public function process(): void
                    {
                        $this->validate();
                        $this->save();
                    }
                    PHP,
                'after' => <<<'PHP'
                    public function process(): void
                    {
                        if (! $this->validate()) {
                            return;
                        }
                        $this->save();
                    }
                    PHP,
            ],
            [
                'rule' => 'ControlFlowRule',
                'severity' => Severity::HIGH,
                'title' => 'Try-catch block added or removed',
                'description' => 'Exception handling was added or removed — exceptions may now propagate or be silently caught.',
                'before' => <<<'PHP'
                    $result = $this->api->call($payload);
                    PHP,
                'after' => <<<'PHP'
                    try {
                        $result = $this->api->call($payload);
                    } catch (ApiException $e) {
                        $result = null;
                    }
                    PHP,
            ],
            [
                'rule' => 'MethodRenamedRule',
                'severity' => Severity::HIGH,
                'title' => 'Method renamed',
                'description' => 'A method was renamed — all call sites using the old name will break.',
                'before' => <<<'PHP'
                    public function getUserData(int $id): array
                    PHP,
                'after' => <<<'PHP'
                    public function fetchUserData(int $id): array
                    PHP,
            ],
            [
                'rule' => 'LaravelRouteRule',
                'severity' => Severity::HIGH,
                'title' => 'Route removed',
                'description' => 'A route was deleted — any clients calling that URL will receive a 404.',
                'before' => <<<'PHP'
                    Route::get('/api/v1/users', [UserController::class, 'index']);
                    PHP,
                'after' => <<<'PHP'
                    // route deleted
                    PHP,
            ],
            [
                'rule' => 'LaravelRouteRule',
                'severity' => Severity::HIGH,
                'title' => 'Route HTTP method changed',
                'description' => 'GET changed to POST (or similar) — clients using the old method will fail.',
                'before' => <<<'PHP'
                    Route::get('/orders/{id}/cancel', [OrderController::class, 'cancel']);
                    PHP,
                'after' => <<<'PHP'
                    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
                    PHP,
            ],
            [
                'rule' => 'LaravelCacheRule',
                'severity' => Severity::HIGH,
                'title' => 'Cache invalidation added or changed',
                'description' => 'Cache::forget() / flush() was added, removed, or its key changed — stale data may now persist or be over-cleared.',
                'before' => <<<'PHP'
                    Cache::put('user.'.$id, $user, 3600);
                    PHP,
                'after' => <<<'PHP'
                    Cache::forget('user.'.$id);
                    Cache::put('user.'.$id, $user, 3600);
                    PHP,
            ],
            [
                'rule' => 'LaravelDbFacadeRule',
                'severity' => Severity::HIGH,
                'title' => 'Dangerous DB operation added',
                'description' => 'DB::delete(), DB::rollBack(), or DB::unprepared() was added — data may be mutated or lost.',
                'before' => <<<'PHP'
                    DB::table('sessions')->where('user_id', $id)->get();
                    PHP,
                'after' => <<<'PHP'
                    DB::table('sessions')->where('user_id', $id)->delete();
                    PHP,
            ],
            [
                'rule' => 'LaravelUnauthorizedRouteRule',
                'severity' => Severity::HIGH,
                'title' => 'New route without auth middleware',
                'description' => 'A new route was added with no authentication middleware — it is publicly accessible.',
                'before' => <<<'PHP'
                    // route did not exist
                    PHP,
                'after' => <<<'PHP'
                    Route::get('/admin/reports', [ReportController::class, 'index']);
                    PHP,
            ],
            [
                'rule' => 'MethodSignatureRule',
                'severity' => Severity::HIGH,
                'title' => 'Parameter removed from method',
                'description' => 'A method parameter was removed — all call sites that pass that argument will receive a deprecation or error.',
                'before' => <<<'PHP'
                    public function send(string $to, string $subject, string $body): void
                    PHP,
                'after' => <<<'PHP'
                    public function send(string $to, string $subject): void
                    PHP,
            ],
            [
                'rule' => 'MethodSignatureRule',
                'severity' => Severity::HIGH,
                'title' => 'Return type changed',
                'description' => 'The declared return type was changed — callers that type-hint the old return type will fail.',
                'before' => <<<'PHP'
                    public function find(int $id): User
                    PHP,
                'after' => <<<'PHP'
                    public function find(int $id): ?User
                    PHP,
            ],
            [
                'rule' => 'MethodSignatureRule',
                'severity' => Severity::HIGH,
                'title' => 'Method visibility narrowed',
                'description' => 'A method changed from public to protected/private — external callers will receive a fatal error.',
                'before' => <<<'PHP'
                    public function process(): void
                    PHP,
                'after' => <<<'PHP'
                    protected function process(): void
                    PHP,
            ],
            [
                'rule' => 'MethodSignatureRule',
                'severity' => Severity::HIGH,
                'title' => 'Parameters reordered',
                'description' => 'Method parameters were reordered — positional call sites will silently pass wrong values.',
                'before' => <<<'PHP'
                    public function create(string $name, int $quantity, float $price): void
                    PHP,
                'after' => <<<'PHP'
                    public function create(string $name, float $price, int $quantity): void
                    PHP,
            ],
            [
                'rule' => 'MethodSignatureRule',
                'severity' => Severity::HIGH,
                'title' => 'Method made static',
                'description' => 'A method changed from instance to static — $this-based calls will fail; callers may rely on instance state.',
                'before' => <<<'PHP'
                    public function resolve(): string
                    PHP,
                'after' => <<<'PHP'
                    public static function resolve(): string
                    PHP,
            ],
            [
                'rule' => 'ClassStructureRule',
                'severity' => Severity::HIGH,
                'title' => 'Parent class changed',
                'description' => 'The class now extends a different parent — inherited methods and properties may have changed.',
                'before' => <<<'PHP'
                    class PaymentController extends Controller
                    PHP,
                'after' => <<<'PHP'
                    class PaymentController extends BaseApiController
                    PHP,
            ],
            [
                'rule' => 'ClassStructureRule',
                'severity' => Severity::HIGH,
                'title' => 'Property removed',
                'description' => 'A class property was deleted — code accessing it will throw an error or return null.',
                'before' => <<<'PHP'
                    private string $apiKey;
                    PHP,
                'after' => <<<'PHP'
                    // $apiKey deleted
                    PHP,
            ],
            [
                'rule' => 'ClassStructureRule',
                'severity' => Severity::HIGH,
                'title' => 'Interface removed from class',
                'description' => 'The class no longer implements an interface — type-checks and DI bindings relying on the interface will fail.',
                'before' => <<<'PHP'
                    class Mailer implements MailerContract
                    PHP,
                'after' => <<<'PHP'
                    class Mailer
                    PHP,
            ],
            [
                'rule' => 'ValueRule',
                'severity' => Severity::HIGH,
                'title' => 'Class constant value changed',
                'description' => 'A class constant\'s value changed — any code comparing against or using the old value will silently use the wrong value.',
                'before' => <<<'PHP'
                    const MAX_RETRY_ATTEMPTS = 3;
                    PHP,
                'after' => <<<'PHP'
                    const MAX_RETRY_ATTEMPTS = 10;
                    PHP,
            ],
            [
                'rule' => 'EnumRule',
                'severity' => Severity::HIGH,
                'title' => 'Enum case removed',
                'description' => 'An enum case was deleted — any code referencing it will cause a compile-time error.',
                'before' => <<<'PHP'
                    enum Status: string {
                        case Active = 'active';
                        case Pending = 'pending';
                        case Archived = 'archived';
                    }
                    PHP,
                'after' => <<<'PHP'
                    enum Status: string {
                        case Active = 'active';
                        case Pending = 'pending';
                    }
                    PHP,
            ],
            [
                'rule' => 'MethodComplexityRule',
                'severity' => Severity::HIGH,
                'title' => 'Method complexity jumped directly to bad',
                'description' => 'A method\'s complexity metric crossed from good directly to bad in a single commit — consider breaking the method apart.',
                'before' => <<<'PHP'
                    public function handle(): void
                    {
                        $this->run();
                    }
                    PHP,
                'after' => <<<'PHP'
                    public function handle(): void
                    {
                        if ($this->a) { ... }
                        if ($this->b) { ... }
                        if ($this->c) { ... }
                        // ... many more branches
                    }
                    PHP,
            ],
            [
                'rule' => 'FileComplexityRule',
                'severity' => Severity::HIGH,
                'title' => 'File total complexity jumped directly to bad',
                'description' => 'The sum of CC or Flog across all methods in the file crossed from good directly to bad — the file as a whole has become very complex.',
                'before' => <<<'PHP'
                    // file total CC = 18 (good)
                    PHP,
                'after' => <<<'PHP'
                    // file total CC = 55 (bad, many new branches added)
                    PHP,
            ],
        ];
    }

    /**
     * @return list<array{rule: string, severity: Severity, title: string, description: string, before: string, after: string}>
     */
    private static function medium(): array
    {
        return [
            [
                'rule' => 'ControlFlowRule',
                'severity' => Severity::MEDIUM,
                'title' => 'If condition changed',
                'description' => 'The condition in an if statement was modified — the branch may now trigger in different circumstances.',
                'before' => <<<'PHP'
                    if ($order->status === 'paid') {
                    PHP,
                'after' => <<<'PHP'
                    if ($order->status === 'pending') {
                    PHP,
            ],
            [
                'rule' => 'ControlFlowRule',
                'severity' => Severity::MEDIUM,
                'title' => 'If statement added or removed',
                'description' => 'A new branching condition was introduced, or an existing one was deleted.',
                'before' => <<<'PHP'
                    $this->notify($user);
                    PHP,
                'after' => <<<'PHP'
                    if ($user->wantsNotifications()) {
                        $this->notify($user);
                    }
                    PHP,
            ],
            [
                'rule' => 'MethodChangedRule',
                'severity' => Severity::MEDIUM,
                'title' => 'Method body changed',
                'description' => 'The implementation of a method changed — review the diff for logic changes.',
                'before' => <<<'PHP'
                    public function discount(): float
                    {
                        return $this->price * 0.1;
                    }
                    PHP,
                'after' => <<<'PHP'
                    public function discount(): float
                    {
                        return $this->price * $this->discountRate;
                    }
                    PHP,
            ],
            [
                'rule' => 'ConstructorInjectionRule',
                'severity' => Severity::MEDIUM,
                'title' => 'New constructor dependency',
                'description' => 'A new dependency was injected — callers constructing this class manually will break.',
                'before' => <<<'PHP'
                    public function __construct(private UserRepository $users)
                    PHP,
                'after' => <<<'PHP'
                    public function __construct(
                        private UserRepository $users,
                        private MailerInterface $mailer,
                    )
                    PHP,
            ],
            [
                'rule' => 'LaravelMigrationRule',
                'severity' => Severity::MEDIUM,
                'title' => 'Migration modifies column',
                'description' => '$table->change() was called — column type, nullability, or default changed; may fail if existing data is incompatible.',
                'before' => <<<'PHP'
                    $table->string('phone')->nullable();
                    PHP,
                'after' => <<<'PHP'
                    $table->string('phone', 20)->nullable()->change();
                    PHP,
            ],
            [
                'rule' => 'LaravelMigrationRule',
                'severity' => Severity::MEDIUM,
                'title' => 'Migration creates table',
                'description' => 'Schema::create() was added — a new table will be created.',
                'before' => <<<'PHP'
                    // no table creation
                    PHP,
                'after' => <<<'PHP'
                    Schema::create('subscriptions', function (Blueprint $table) {
                        $table->id();
                        $table->foreignId('user_id')->constrained();
                        $table->timestamps();
                    });
                    PHP,
            ],
            [
                'rule' => 'SideEffectRule',
                'severity' => Severity::MEDIUM,
                'title' => 'Side-effect call added',
                'description' => 'A call like event(), dispatch(), Mail::send(), Log::info() was introduced — external systems may now be triggered.',
                'before' => <<<'PHP'
                    $this->save();
                    PHP,
                'after' => <<<'PHP'
                    $this->save();
                    event(new OrderShipped($this));
                    PHP,
            ],
            [
                'rule' => 'LaravelEloquentRule',
                'severity' => Severity::MEDIUM,
                'title' => 'Model casts changed',
                'description' => '$casts was modified — data returned from the model may now be a different type.',
                'before' => <<<'PHP'
                    protected $casts = ['settings' => 'array'];
                    PHP,
                'after' => <<<'PHP'
                    protected $casts = ['settings' => 'json'];
                    PHP,
            ],
            [
                'rule' => 'LaravelRouteRule',
                'severity' => Severity::MEDIUM,
                'title' => 'Route added',
                'description' => 'A new route was added — verify it has the appropriate middleware.',
                'before' => <<<'PHP'
                    // route did not exist
                    PHP,
                'after' => <<<'PHP'
                    Route::get('/api/reports', [ReportController::class, 'index'])
                        ->middleware('auth:api');
                    PHP,
            ],
            [
                'rule' => 'ErrorHandlingRule',
                'severity' => Severity::MEDIUM,
                'title' => 'Error suppression operator added',
                'description' => 'The @ operator was added — errors from that expression will be silenced.',
                'before' => <<<'PHP'
                    $result = file_get_contents($url);
                    PHP,
                'after' => <<<'PHP'
                    $result = @file_get_contents($url);
                    PHP,
            ],
            [
                'rule' => 'StrictTypesRule',
                'severity' => Severity::MEDIUM,
                'title' => 'strict_types=1 removed',
                'description' => 'declare(strict_types=1) was removed — PHP will now silently coerce types instead of throwing TypeErrors.',
                'before' => <<<'PHP'
                    <?php declare(strict_types=1);
                    PHP,
                'after' => <<<'PHP'
                    <?php
                    PHP,
            ],
            [
                'rule' => 'TypeSystemRule',
                'severity' => Severity::MEDIUM,
                'title' => 'Type hint removed',
                'description' => 'A parameter or return type was removed — PHP will no longer enforce the type.',
                'before' => <<<'PHP'
                    public function handle(Request $request): Response
                    PHP,
                'after' => <<<'PHP'
                    public function handle($request)
                    PHP,
            ],
            [
                'rule' => 'MethodComplexityRule',
                'severity' => Severity::MEDIUM,
                'title' => 'Method complexity worsened into bad zone',
                'description' => 'A method\'s complexity metric was already in the warning zone and has now crossed into the bad zone — refactoring is recommended.',
                'before' => <<<'PHP'
                    // cc = 6 (warn)
                    public function resolve($type): mixed
                    {
                        if ($type === 'a') { return $this->a(); }
                        if ($type === 'b') { return $this->b(); }
                        if ($type === 'c') { return $this->c(); }
                        return null;
                    }
                    PHP,
                'after' => <<<'PHP'
                    // cc = 12 (bad)
                    public function resolve($type, $flag): mixed
                    {
                        if ($type === 'a') { return $flag ? $this->a1() : $this->a2(); }
                        if ($type === 'b') { return $flag ? $this->b1() : $this->b2(); }
                        if ($type === 'c') { return $flag ? $this->c1() : $this->c2(); }
                        // ...
                        return null;
                    }
                    PHP,
            ],
            [
                'rule' => 'FileComplexityRule',
                'severity' => Severity::MEDIUM,
                'title' => 'File total complexity worsened into bad zone',
                'description' => 'The sum of CC or Flog across all methods in the file was already in the warning zone and has now crossed into the bad zone — consider splitting the file.',
                'before' => <<<'PHP'
                    // file total CC = 28 (warn)
                    PHP,
                'after' => <<<'PHP'
                    // file total CC = 54 (bad)
                    PHP,
            ],
            [
                'rule' => 'LaravelQueryBuilderRule',
                'severity' => Severity::MEDIUM,
                'title' => 'DB query where condition removed',
                'description' => 'A where/whereNull/whereNotNull clause was removed from a DB query — the query now returns a broader result set, which may expose unintended rows.',
                'before' => <<<'PHP'
                    DB::table('sessions')->where('user_id', $id)->where('active', 1)->get();
                    PHP,
                'after' => <<<'PHP'
                    DB::table('sessions')->where('user_id', $id)->get();
                    PHP,
            ],
            [
                'rule' => 'LaravelQueryBuilderRule',
                'severity' => Severity::MEDIUM,
                'title' => 'DB query join removed',
                'description' => 'A join clause was removed from a DB query — the result set structure changes and downstream code relying on the joined columns will break.',
                'before' => <<<'PHP'
                    DB::table('orders')->join('users', 'orders.user_id', '=', 'users.id')->get();
                    PHP,
                'after' => <<<'PHP'
                    DB::table('orders')->get();
                    PHP,
            ],
        ];
    }

    /**
     * @return list<array{rule: string, severity: Severity, title: string, description: string, before: string, after: string}>
     */
    private static function low(): array
    {
        return [
            [
                'rule' => 'LaravelCacheRule',
                'severity' => Severity::LOW,
                'title' => 'Cache read added or changed',
                'description' => 'Cache::get() or Cache::remember() was added or modified — a cache lookup was introduced.',
                'before' => <<<'PHP'
                    return $this->users->find($id);
                    PHP,
                'after' => <<<'PHP'
                    return Cache::remember('user.'.$id, 3600, fn () => $this->users->find($id));
                    PHP,
            ],
            [
                'rule' => 'LaravelDbFacadeRule',
                'severity' => Severity::LOW,
                'title' => 'DB read query added',
                'description' => 'DB::select() or DB::table()->get() was added — a new read query is being issued.',
                'before' => <<<'PHP'
                    return $this->model->all();
                    PHP,
                'after' => <<<'PHP'
                    return DB::select('select * from users where active = 1');
                    PHP,
            ],
            [
                'rule' => 'LaravelQueryBuilderRule',
                'severity' => Severity::LOW,
                'title' => 'DB query select field removed',
                'description' => 'A field was dropped from a ->select([...]) clause — code that reads that column from the result will receive null or throw an undefined key error.',
                'before' => <<<'PHP'
                    DB::table('users')->select(['id', 'name', 'email'])->get();
                    PHP,
                'after' => <<<'PHP'
                    DB::table('users')->select(['id', 'name'])->get();
                    PHP,
            ],
            [
                'rule' => 'LaravelQueryBuilderRule',
                'severity' => Severity::LOW,
                'title' => 'DB query where condition added',
                'description' => 'A new where clause was added to a DB query — the query now filters more strictly and may return fewer rows than callers expect.',
                'before' => <<<'PHP'
                    DB::table('users')->get();
                    PHP,
                'after' => <<<'PHP'
                    DB::table('users')->where('active', 1)->get();
                    PHP,
            ],
            [
                'rule' => 'LaravelQueryBuilderRule',
                'severity' => Severity::LOW,
                'title' => 'DB query join added',
                'description' => 'A join clause was added to a DB query — the result set now includes columns from the joined table and row count may change if the join is not 1:1.',
                'before' => <<<'PHP'
                    DB::table('orders')->select(['id', 'total'])->get();
                    PHP,
                'after' => <<<'PHP'
                    DB::table('orders')->select(['id', 'total', 'users.name'])->join('users', 'orders.user_id', '=', 'users.id')->get();
                    PHP,
            ],
            [
                'rule' => 'MethodComplexityRule',
                'severity' => Severity::LOW,
                'title' => 'Method complexity crossed into warning zone',
                'description' => 'A method\'s complexity metric (CC, LLOC, or param count) moved from good to warn — it is approaching the threshold where reviewers should take note.',
                'before' => <<<'PHP'
                    public function process($a, $b): void
                    {
                        $this->doA($a);
                        $this->doB($b);
                    }
                    PHP,
                'after' => <<<'PHP'
                    public function process($a, $b, $c, $d, $e): void
                    {
                        $this->doA($a);
                        $this->doB($b);
                        if ($c) { $this->doC($c); }
                        if ($d) { $this->doD($d); }
                        if ($e) { $this->doE($e); }
                    }
                    PHP,
            ],
            [
                'rule' => 'FileComplexityRule',
                'severity' => Severity::LOW,
                'title' => 'File total complexity crossed into warning zone',
                'description' => 'The sum of CC or Flog across all methods in the file moved from good to warn — the file is accumulating complexity.',
                'before' => <<<'PHP'
                    // file total CC = 18 (good)
                    PHP,
                'after' => <<<'PHP'
                    // file total CC = 27 (warn)
                    PHP,
            ],
        ];
    }

    /**
     * @return list<array{rule: string, severity: Severity, title: string, description: string, before: string, after: string}>
     */
    private static function info(): array
    {
        return [
            [
                'rule' => 'MethodAddedRule',
                'severity' => Severity::INFO,
                'title' => 'Method added',
                'description' => 'A new method was added to the class — no existing callers are affected.',
                'before' => <<<'PHP'
                    // method did not exist
                    PHP,
                'after' => <<<'PHP'
                    public function archive(): void
                    {
                        $this->status = 'archived';
                        $this->save();
                    }
                    PHP,
            ],
            [
                'rule' => 'ImportRule',
                'severity' => Severity::INFO,
                'title' => 'Import added or removed',
                'description' => 'A use statement was added or removed — no runtime impact on its own.',
                'before' => <<<'PHP'
                    use App\Models\User;
                    PHP,
                'after' => <<<'PHP'
                    use App\Models\User;
                    use Illuminate\Support\Facades\Cache;
                    PHP,
            ],
            [
                'rule' => 'CosmeticRule',
                'severity' => Severity::INFO,
                'title' => 'Whitespace / formatting change',
                'description' => 'Only whitespace or comment changes were detected — no runtime impact.',
                'before' => <<<'PHP'
                    public function foo(){return $this->bar;}
                    PHP,
                'after' => <<<'PHP'
                    public function foo(): mixed
                    {
                        return $this->bar;
                    }
                    PHP,
            ],
            [
                'rule' => 'StrictTypesRule',
                'severity' => Severity::INFO,
                'title' => 'strict_types=1 added',
                'description' => 'declare(strict_types=1) was added — type coercion is now strict in this file.',
                'before' => <<<'PHP'
                    <?php
                    PHP,
                'after' => <<<'PHP'
                    <?php declare(strict_types=1);
                    PHP,
            ],
            [
                'rule' => 'FileLevelRule',
                'severity' => Severity::INFO,
                'title' => 'Test file modified',
                'description' => 'A test file was changed — no production behaviour changed.',
                'before' => <<<'PHP'
                    it('creates a user', fn () => ...);
                    PHP,
                'after' => <<<'PHP'
                    it('creates a user', fn () => ...);
                    it('deletes a user', fn () => ...);
                    PHP,
            ],
            [
                'rule' => 'MethodComplexityRule',
                'severity' => Severity::INFO,
                'title' => 'Method complexity improved',
                'description' => 'A method\'s complexity metric improved to a lower severity band — the method is simpler than before.',
                'before' => <<<'PHP'
                    // cc = 7 (warn)
                    public function build(): array
                    {
                        if ($this->a) { ... }
                        if ($this->b) { ... }
                        if ($this->c) { ... }
                        // ...
                    }
                    PHP,
                'after' => <<<'PHP'
                    // cc = 3 (good)
                    public function build(): array
                    {
                        return $this->builder->compile($this->config);
                    }
                    PHP,
            ],
            [
                'rule' => 'FileComplexityRule',
                'severity' => Severity::INFO,
                'title' => 'File total complexity improved',
                'description' => 'The sum of CC or Flog across all methods in the file improved to a lower severity band — the file has been simplified.',
                'before' => <<<'PHP'
                    // file total CC = 32 (warn)
                    PHP,
                'after' => <<<'PHP'
                    // file total CC = 19 (good, methods extracted or simplified)
                    PHP,
            ],
            [
                'rule' => 'LaravelQueryBuilderRule',
                'severity' => Severity::INFO,
                'title' => 'DB query select field added',
                'description' => 'A new field was added to a ->select([...]) clause — more data is returned from the query.',
                'before' => <<<'PHP'
                    DB::table('users')->select(['id', 'name'])->get();
                    PHP,
                'after' => <<<'PHP'
                    DB::table('users')->select(['id', 'name', 'email'])->get();
                    PHP,
            ],
            [
                'rule' => 'LaravelQueryBuilderRule',
                'severity' => Severity::INFO,
                'title' => 'DB query ordering added or removed',
                'description' => 'An orderBy/orderByDesc/latest clause was added or removed — the sort order of results has changed.',
                'before' => <<<'PHP'
                    DB::table('users')->get();
                    PHP,
                'after' => <<<'PHP'
                    DB::table('users')->orderBy('created_at', 'desc')->get();
                    PHP,
            ],
        ];
    }
}
