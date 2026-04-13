<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Return_;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\ClassifiedChange;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Data\FileDiff;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\ChangeCategory;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\Concerns\AnalyzesLaravelCode;

class LaravelEloquentRule implements Rule
{
    use AnalyzesLaravelCode;

    /** @var list<string> Eloquent relationship methods */
    private const RELATIONSHIP_METHODS = [
        'hasOne', 'hasMany', 'belongsTo', 'belongsToMany',
        'hasOneThrough', 'hasManyThrough',
        'morphOne', 'morphMany', 'morphTo', 'morphToMany', 'morphedByMany',
    ];

    /** @var array<string, array{description: string, severity: Severity}> */
    private const SIGNIFICANT_PROPERTIES = [
        'fillable' => ['description' => 'Mass-assignable fields changed', 'severity' => Severity::VERY_HIGH],
        'guarded' => ['description' => 'Mass-assignment guard changed', 'severity' => Severity::VERY_HIGH],
        'hidden' => ['description' => 'Hidden attributes changed (API serialization)', 'severity' => Severity::MEDIUM],
        'visible' => ['description' => 'Visible attributes changed (API serialization)', 'severity' => Severity::MEDIUM],
        'appends' => ['description' => 'Appended attributes changed (JSON representation)', 'severity' => Severity::MEDIUM],
        'with' => ['description' => 'Default eager loads changed (performance impact)', 'severity' => Severity::MEDIUM],
        'table' => ['description' => 'Database table changed', 'severity' => Severity::VERY_HIGH],
        'connection' => ['description' => 'Database connection changed', 'severity' => Severity::VERY_HIGH],
        'primaryKey' => ['description' => 'Primary key changed', 'severity' => Severity::VERY_HIGH],
        'keyType' => ['description' => 'Primary key type changed', 'severity' => Severity::VERY_HIGH],
        'incrementing' => ['description' => 'Auto-incrementing changed', 'severity' => Severity::MEDIUM],
        'timestamps' => ['description' => 'Timestamps behavior changed', 'severity' => Severity::MEDIUM],
        'dateFormat' => ['description' => 'Date format changed', 'severity' => Severity::MEDIUM],
        'dispatchesEvents' => ['description' => 'Model event dispatching changed', 'severity' => Severity::MEDIUM],
    ];

    /** @var list<string> Eager loading method calls */
    private const EAGER_LOADING_METHODS = ['with', 'load', 'loadMissing', 'loadCount', 'loadMorph', 'withCount'];

    public function __construct()
    {
        $this->initializeAnalyzer();
    }

    public function shortDescription(): string
    {
        return 'Detects Eloquent model relationship, property, and behavior changes';
    }

    public function description(): string
    {
        return 'Detects Eloquent model changes: relationship modifications, property changes ($fillable, $guarded, $hidden, $with, etc.), casts, scopes, accessors/mutators, model events, eager loading changes, SoftDeletes, and DB::transaction() usage.';
    }

    public function analyze(FileDiff $file, array $comparison): array
    {
        $changes = [];

        $this->analyzeModelProperties($comparison, $changes);
        $this->analyzeRelationships($comparison, $changes);
        $this->analyzeCasts($comparison, $changes);
        $this->analyzeScopes($comparison, $changes);
        $this->analyzeAccessorsMutators($comparison, $changes);
        $this->analyzeModelEvents($comparison, $changes);
        $this->analyzeEagerLoading($comparison, $changes);
        $this->analyzeSoftDeletes($file, $comparison, $changes);
        $this->analyzeTransactions($comparison, $changes);

        return $changes;
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeModelProperties(array $comparison, array &$changes): void
    {
        foreach ($comparison['properties'] as $key => $pair) {
            $propName = ltrim(explode('::$', $key)[1] ?? '', '$');

            if (! isset(self::SIGNIFICANT_PROPERTIES[$propName])) {
                continue;
            }

            $meta = self::SIGNIFICANT_PROPERTIES[$propName];

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $oldVal = $this->printer->prettyPrint([$pair['old']]);
                $newVal = $this->printer->prettyPrint([$pair['new']]);

                if ($oldVal !== $newVal) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: $meta['severity'],
                        description: "{$meta['description']} on {$this->getClassName($key)}",
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            } elseif ($pair['old'] === null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: $meta['severity'],
                    description: "\${$propName} added on {$this->getClassName($key)}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            } elseif ($pair['old'] !== null && $pair['new'] === null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: $meta['severity'],
                    description: "\${$propName} removed from {$this->getClassName($key)}",
                    location: $key,
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeRelationships(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            $oldRelations = $pair['old'] !== null ? $this->extractRelationshipCalls($pair['old']) : [];
            $newRelations = $pair['new'] !== null ? $this->extractRelationshipCalls($pair['new']) : [];

            if (count($oldRelations) === 0 && count($newRelations) === 0) {
                continue;
            }

            if ($pair['old'] === null) {
                $types = $this->extractRelationshipTypes($pair['new']);
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::RELATIONSHIP_ADDED,
                    severity: Severity::HIGH,
                    description: 'Eloquent relationship added: '.$key.' ('.implode(', ', $types).')',
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            } elseif ($pair['new'] === null) {
                $types = $this->extractRelationshipTypes($pair['old']);
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::RELATIONSHIP_REMOVED,
                    severity: Severity::VERY_HIGH,
                    description: 'Eloquent relationship removed: '.$key.' ('.implode(', ', $types).')',
                    location: $key,
                );
            } elseif ($oldRelations !== $newRelations) {
                $oldTypes = $this->extractRelationshipTypes($pair['old']);
                $newTypes = $this->extractRelationshipTypes($pair['new']);

                if ($oldTypes !== $newTypes) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::RELATIONSHIP_TYPE_CHANGED,
                        severity: Severity::VERY_HIGH,
                        description: 'Eloquent relationship type changed in '.$key.': '.implode(', ', $oldTypes).' → '.implode(', ', $newTypes),
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                } else {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::RELATIONSHIP_CHANGED,
                        severity: Severity::VERY_HIGH,
                        description: "Eloquent relationship changed in {$key}",
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            } else {
                // Relationship call itself unchanged — check if chained constraints changed
                $oldChains = $this->extractRelationshipChains($pair['old']);
                $newChains = $this->extractRelationshipChains($pair['new']);

                if ($oldChains !== $newChains) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::RELATIONSHIP_CONSTRAINT_CHANGED,
                        severity: Severity::HIGH,
                        description: "Eloquent relationship constraint changed in {$key}",
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeCasts(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            if ($this->getMethodName($key) !== 'casts') {
                continue;
            }

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Model casts changed in {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }

        // Also check $casts property
        foreach ($comparison['properties'] as $key => $pair) {
            if (! str_ends_with($key, '::$casts')) {
                continue;
            }

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $oldVal = $this->printer->prettyPrint([$pair['old']]);
                $newVal = $this->printer->prettyPrint([$pair['new']]);

                if ($oldVal !== $newVal) {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: 'Model $casts property changed on '.$this->getClassName($key),
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeScopes(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            $methodName = $this->getMethodName($key);

            if (! str_starts_with($methodName, 'scope')) {
                continue;
            }

            if ($pair['old'] !== null && $pair['new'] !== null) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Eloquent scope modified: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );
            }
        }

        // Global scopes in booted()
        foreach ($comparison['methods'] as $key => $pair) {
            $methodName = $this->getMethodName($key);

            if ($methodName !== 'booted' && $methodName !== 'boot') {
                continue;
            }

            if ($pair['new'] === null) {
                continue;
            }

            $newScopeCalls = $this->findStaticCalls($pair['new'], 'static');
            foreach ($newScopeCalls as $call) {
                if ($call->name instanceof Node\Identifier && $call->name->toString() === 'addGlobalScope') {
                    $oldHasScope = false;
                    if ($pair['old'] !== null) {
                        foreach ($this->findStaticCalls($pair['old'], 'static') as $oldCall) {
                            if ($oldCall->name instanceof Node\Identifier && $oldCall->name->toString() === 'addGlobalScope') {
                                $oldHasScope = true;

                                break;
                            }
                        }
                    }

                    if (! $oldHasScope) {
                        $changes[] = new ClassifiedChange(
                            category: ChangeCategory::LARAVEL,
                            severity: Severity::MEDIUM,
                            description: "Global scope added in {$key}",
                            location: $key,
                            line: $call->getStartLine(),
                        );
                    }
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeAccessorsMutators(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $methodName = $this->getMethodName($key);

            // Old-style: get*Attribute / set*Attribute
            if (preg_match('/^(get|set).+Attribute$/', $methodName)) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Accessor/mutator changed: {$key}",
                    location: $key,
                    line: $pair['new']->getStartLine(),
                );

                continue;
            }

            // New-style: methods returning Attribute::make()
            $returnStatements = $this->finder->findInstanceOf([$pair['new']], Return_::class);
            foreach ($returnStatements as $return) {
                if ($return->expr instanceof Expr\StaticCall
                    && $return->expr->class instanceof Node\Name
                    && $return->expr->class->getLast() === 'Attribute'
                    && $return->expr->name instanceof Node\Identifier
                    && $return->expr->name->toString() === 'make') {
                    $changes[] = new ClassifiedChange(
                        category: ChangeCategory::LARAVEL,
                        severity: Severity::MEDIUM,
                        description: "Accessor/mutator changed: {$key}",
                        location: $key,
                        line: $pair['new']->getStartLine(),
                    );

                    break;
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeModelEvents(array $comparison, array &$changes): void
    {
        $eventMethods = ['creating', 'created', 'updating', 'updated', 'saving', 'saved',
            'deleting', 'deleted', 'restoring', 'restored', 'replicating', 'trashed', 'forceDeleting', 'forceDeleted'];

        foreach ($comparison['methods'] as $key => $pair) {
            $methodName = $this->getMethodName($key);

            if ($methodName !== 'booted' && $methodName !== 'boot') {
                continue;
            }

            if ($pair['new'] === null) {
                continue;
            }

            foreach ($this->findStaticCalls($pair['new'], 'static') as $call) {
                $callName = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

                if (in_array($callName, $eventMethods, true)) {
                    $wasPresent = false;
                    if ($pair['old'] !== null) {
                        foreach ($this->findStaticCalls($pair['old'], 'static') as $oldCall) {
                            $oldCallName = $oldCall->name instanceof Node\Identifier ? $oldCall->name->toString() : '';
                            if ($oldCallName === $callName) {
                                $wasPresent = true;

                                break;
                            }
                        }
                    }

                    if (! $wasPresent) {
                        $changes[] = new ClassifiedChange(
                            category: ChangeCategory::LARAVEL,
                            severity: Severity::MEDIUM,
                            description: "Model event hook added: static::{$callName}() in {$key}",
                            location: $key,
                            line: $call->getStartLine(),
                        );
                    }
                }
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeEagerLoading(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldCalls = $this->extractMethodCallNames($pair['old']);
            $newCalls = $this->extractMethodCallNames($pair['new']);

            $oldEager = array_intersect($oldCalls, self::EAGER_LOADING_METHODS);
            $newEager = array_intersect($newCalls, self::EAGER_LOADING_METHODS);

            $added = array_diff($newEager, $oldEager);
            $removed = array_diff($oldEager, $newEager);

            foreach ($added as $method) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::INFO,
                    description: "Eager loading added in {$key}: ->{$method}()",
                    location: $key,
                );
            }

            foreach ($removed as $method) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "Eager loading removed in {$key}: ->{$method}() (potential N+1)",
                    location: $key,
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeSoftDeletes(FileDiff $file, array $comparison, array &$changes): void
    {
        foreach ($comparison['classes'] ?? [] as $name => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldTraits = $this->extractTraitNames($pair['old']);
            $newTraits = $this->extractTraitNames($pair['new']);

            if (! in_array('SoftDeletes', $oldTraits, true) && in_array('SoftDeletes', $newTraits, true)) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::VERY_HIGH,
                    description: "SoftDeletes added to {$name} — delete behavior changes, ensure deleted_at column exists",
                    location: $name,
                    line: $pair['new']->getStartLine(),
                );
            }

            if (in_array('SoftDeletes', $oldTraits, true) && ! in_array('SoftDeletes', $newTraits, true)) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::VERY_HIGH,
                    description: "SoftDeletes removed from {$name} — deletes are now permanent",
                    location: $name,
                    line: $pair['new']->getStartLine(),
                );
            }
        }
    }

    /**
     * @param  list<ClassifiedChange>  $changes
     */
    private function analyzeTransactions(array $comparison, array &$changes): void
    {
        foreach ($comparison['methods'] as $key => $pair) {
            if ($pair['old'] === null || $pair['new'] === null) {
                continue;
            }

            $oldHasTx = count($this->findStaticCalls($pair['old'], 'DB')) > 0
                && in_array('transaction', $this->extractStaticCallMethods($pair['old'], 'DB'), true);
            $newHasTx = count($this->findStaticCalls($pair['new'], 'DB')) > 0
                && in_array('transaction', $this->extractStaticCallMethods($pair['new'], 'DB'), true);

            if (! $oldHasTx && $newHasTx) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::INFO,
                    description: "DB::transaction() added in {$key}",
                    location: $key,
                );
            }

            if ($oldHasTx && ! $newHasTx) {
                $changes[] = new ClassifiedChange(
                    category: ChangeCategory::LARAVEL,
                    severity: Severity::MEDIUM,
                    description: "DB::transaction() removed in {$key} — data integrity risk",
                    location: $key,
                );
            }
        }
    }

    /**
     * Returns the full pretty-printed return expression for each return statement that contains
     * a relationship call — capturing the complete chain including any chained constraints
     * like ->where(), ->orderBy(), ->withTrashed(), etc.
     *
     * @return list<string>
     */
    private function extractRelationshipChains(Node $node): array
    {
        $chains = [];

        foreach ($this->finder->findInstanceOf([$node], Return_::class) as $return) {
            if ($return->expr === null) {
                continue;
            }

            $hasRelation = false;
            foreach ($this->finder->findInstanceOf([$return->expr], Expr\MethodCall::class) as $call) {
                if ($call->name instanceof Node\Identifier && in_array($call->name->toString(), self::RELATIONSHIP_METHODS, true)) {
                    $hasRelation = true;
                    break;
                }
            }

            if ($hasRelation) {
                $chains[] = $this->printer->prettyPrintExpr($return->expr);
            }
        }

        return $chains;
    }

    /**
     * @return list<string>
     */
    private function extractRelationshipTypes(Node $node): array
    {
        $types = [];

        foreach ($this->finder->findInstanceOf([$node], Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier) {
                $name = $call->name->toString();
                if (in_array($name, self::RELATIONSHIP_METHODS, true)) {
                    $types[] = $name;
                }
            }
        }

        return $types;
    }

    /**
     * @return list<string>
     */
    private function extractRelationshipCalls(Node $node): array
    {
        $relations = [];

        foreach ($this->finder->findInstanceOf([$node], Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier) {
                $name = $call->name->toString();
                if (in_array($name, self::RELATIONSHIP_METHODS, true)) {
                    $relations[] = $this->printer->prettyPrintExpr($call);
                }
            }
        }

        return $relations;
    }

    /**
     * @return list<string>
     */
    private function extractTraitNames(Stmt\Class_ $class): array
    {
        $traits = [];

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $traits[] = $trait->getLast();
                }
            }
        }

        return $traits;
    }
}
