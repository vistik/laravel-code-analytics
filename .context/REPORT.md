# laravel-code-analytics

AST-based code analysis tool for Laravel git diffs. Produces risk scores and interactive HTML visualizations.

## Stack

| Layer | Tech |
|-------|------|
| Language | PHP 8.2+ |
| Framework | Laravel 11/12/13 |
| AST Parser | `nikic/php-parser` v5 |
| Metrics | `phpmetrics/phpmetrics` v2.9 |
| Tests | PestPHP v4 |
| Static Analysis | PHPStan v3 + Larastan |
| Formatter | Laravel Pint |

## Directory Map

```
src/
├── Actions/
│   ├── AnalyzeCode.php              # Orchestrator: diff → classify → score → render
│   └── GenerateHtmlReport.php       # Produces standalone HTML
├── Console/Commands/
│   └── CodeAnalyzeCommand.php       # `php artisan code:analyze`
├── DiffAnalyzer/
│   ├── DiffParser.php               # Unified diff → FileDiff[]
│   ├── AstComparer.php              # Old/new AST comparison
│   ├── ChangeClassifier.php         # Applies rules → ClassifiedChange[]
│   ├── Enums/
│   │   ├── ChangeCategory.php       # 28 categories (see below)
│   │   ├── Severity.php             # INFO(1) LOW(3) MEDIUM(5) HIGH(7) VERY_HIGH(10)
│   │   └── FileStatus.php           # ADDED | MODIFIED | DELETED | RENAMED
│   ├── Data/                        # Immutable DTOs: ClassifiedChange, FileReport, Report
│   ├── Rules/                       # 38 rule classes (see below)
│   └── Contracts/                   # FileGroupResolver interface
├── RiskScoring/
│   ├── CalculateRiskScore.php       # Orchestrates 5 factors → 0-100
│   ├── RiskScore.php                # Result DTO
│   └── Factors/
│       ├── ChangeSizeFactor.php
│       ├── FileSpreadFactor.php
│       ├── DeletionRatioFactor.php
│       ├── SeverityFindingsFactor.php
│       └── PhpMetricsFactor.php
├── Renderers/                       # HTML viz generators
│   ├── ForceGraphRenderer.php       # Node-link force graph
│   ├── TreeRenderer.php             # Hierarchical tree
│   ├── GroupedRenderer.php          # Domain-grouped
│   ├── LayeredCakeRenderer.php      # Concentric rings (arch layers)
│   ├── LayeredArchRenderer.php      # Column-based arch view
│   ├── CakeLayer.php                # Layer definition DTO
│   └── LayerStack.php               # Ordered layer collection
├── FileSignal/                      # File importance scoring
├── PhpMetrics/                      # Complexity/hotspot scoring
├── Enums/
│   └── NodeGroup.php                # 19 groups: MODEL, CONTROLLER, SERVICE, etc.
├── Support/
│   ├── PhpMetrics.php
│   └── PhpMetricsRunner.php
└── LaravelCodeAnalyticsServiceProvider.php
```

## Data Flow

```
Git Diff (local or GitHub PR)
  → DiffParser        → FileDiff[]
  → AstComparer       → old/new AST nodes
  → ChangeClassifier  → ClassifiedChange[] (category + severity)
  → CalculateRiskScore → RiskScore (0-100, 5 factors)
  → Renderers          → standalone HTML with 5 viz views
```

## Analysis Rules (38 classes)

### Generic (23)

| Rule | Detects |
|------|---------|
| AssignmentRule | Variable assignments, compound operators |
| AttributeRule | PHP attribute changes |
| ClassStructureRule | Class/interface/enum structural changes |
| ConstructorInjectionRule | Constructor DI changes |
| ControlFlowRule | If/else branch changes |
| CosmeticRule | Whitespace, formatting, comments |
| DateTimeRule | Date/time manipulation |
| DependencyRule | Dependency changes |
| EnumRule | Enum case changes |
| ErrorHandlingRule | Try-catch changes |
| FileLevelRule | File type/status classification |
| ImportRule | Use/import statements |
| MagicMethodRule | __construct, __toString, etc. |
| MethodAddedRule | New methods |
| MethodChangedRule | Method body changes |
| MethodRemovedRule | Deleted methods |
| MethodSignatureRule | Param/return/visibility changes |
| OperatorRule | Operator changes |
| SideEffectRule | External calls, I/O |
| StrictTypesRule | declare(strict_types) changes |
| TypeSystemRule | Type hint changes |
| ValueRule | Constants, default values |
| ControlFlowRule (switch) | Switch/match arm changes |

### Laravel-Specific (15)

| Rule | Detects |
|------|---------|
| LaravelApiResourceRule | API resource changes |
| LaravelAuthRule | Auth/guard changes |
| LaravelCacheRule | Cache add/modify/remove |
| LaravelConfigRule | Config changes |
| LaravelConsoleRule | Artisan command changes |
| LaravelDataMigrationRule | Data migration patterns |
| LaravelEloquentRule | Relationship add/remove/change |
| LaravelEnvironmentRule | Env variable changes |
| LaravelLivewireRule | Livewire component changes |
| LaravelMigrationRule | Schema migration changes |
| LaravelNotificationRule | Notification changes |
| LaravelQueueRule | Queue/job changes |
| LaravelRouteRule | Route definition changes |
| LaravelServiceContainerRule | Service binding changes |
| LaravelTableMigrationRule | Migration-model table linking |

## Risk Scoring

5 factors normalized to 0-100:

| Factor | Measures |
|--------|----------|
| ChangeSizeFactor | Total lines changed |
| FileSpreadFactor | Number of files touched |
| DeletionRatioFactor | Ratio of deletions to total changes |
| SeverityFindingsFactor | Weighted sum of finding severities |
| PhpMetricsFactor | Complexity degradation via phpmetrics |

## Severity Scale

| Level | Score | Color |
|-------|-------|-------|
| INFO | 1 | `#58a6ff` |
| LOW | 3 | `#e3b341` |
| MEDIUM | 5 | `#d29922` |
| HIGH | 7 | `#ff7b72` |
| VERY_HIGH | 10 | `#f85149` |

## Change Categories (28)

`COSMETIC` `TYPE_SYSTEM` `CONDITIONAL` `LOOP` `TRY_CATCH` `RETURN` `SWITCH_MATCH` `OPERATORS` `VALUES` `METHOD_SIGNATURE` `METHOD_ADDED` `METHOD_CHANGED` `METHOD_REMOVED` `CLASS_STRUCTURE` `SIDE_EFFECTS` `LARAVEL` `RELATIONSHIP_ADDED` `RELATIONSHIP_REMOVED` `RELATIONSHIP_TYPE_CHANGED` `RELATIONSHIP_CHANGED` `RELATIONSHIP_CONSTRAINT_CHANGED` `MIGRATION_MODEL_LINK` `IMPORTS` `ASSIGNMENT` `FILE_LEVEL` `DATETIME` `CACHE_ADDED` `CACHE_MODIFIED` `CACHE_REMOVED`

## Architecture Layers (outermost → innermost)

| Layer | NodeGroups |
|-------|-----------|
| Entry | ROUTE, CONFIG |
| Controllers | CONTROLLER, HTTP, CONSOLE |
| Requests/Resources | REQUEST |
| Application | SERVICE, ACTION, JOB, EVENT |
| Domain | MODEL, CORE, NOVA |
| Infrastructure | DB, PROVIDER |
| Presentation | VIEW, FRONTEND |
| Testing | TEST, OTHER |

## NodeGroups (19)

`TEST` `DB` `NOVA` `MODEL` `ACTION` `JOB` `CONTROLLER` `REQUEST` `HTTP` `CONSOLE` `PROVIDER` `CORE` `EVENT` `SERVICE` `VIEW` `FRONTEND` `CONFIG` `ROUTE` `OTHER`

## Commands

```bash
php artisan code:analyze [path] [output]    # Analyze local repo
  --base=main                                # Base branch
  --pr=https://github.com/.../123            # Analyze GitHub PR
  --open                                     # Auto-open HTML
  --config=rules.json                        # Custom grouping config

composer test                                # PestPHP
composer analyse                             # PHPStan level 5
composer format                              # Pint
```

## Key Files (by importance)

| File | LOC (approx) | Role |
|------|------|------|
| `src/Actions/AnalyzeCode.php` | 350+ | Main orchestrator |
| `src/DiffAnalyzer/AstComparer.php` | ~300 | Core AST diff engine |
| `src/DiffAnalyzer/ChangeClassifier.php` | ~100 | Rule dispatcher |
| `src/DiffAnalyzer/DiffParser.php` | ~200 | Unified diff parser |
| `src/RiskScoring/CalculateRiskScore.php` | ~80 | Score aggregator |
| `src/Actions/GenerateHtmlReport.php` | ~150 | HTML report builder |
| `resources/views/analysis/wrapper.blade.php` | 300+ | HTML template + CSS/JS |

## Stats

- **Total PHP LOC**: ~12,400
- **Source files**: ~88
- **Test files**: 3 (minimal coverage)
- **PHPStan level**: 5
