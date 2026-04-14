<?php

use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\AssignmentRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\AttributeRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ClassStructureRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ConstructorInjectionRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ControlFlowRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\CosmeticRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\DateTimeRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\DependencyRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\EnumRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ErrorHandlingRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\FileLevelRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ImportRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelApiResourceRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelAuthRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelCacheRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConfigDependencyRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConfigRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConsoleArgumentAddedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConsoleArgumentDefaultChangedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConsoleArgumentRemovedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConsoleRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelConsoleSignatureChangedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelDataMigrationRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelDbFacadeRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelEloquentRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelEnvironmentRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelLivewireRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelMigrationRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelNotificationRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelQueueRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelRedirectRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelRouteRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelServiceContainerRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelTableMigrationRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\LaravelUnauthorizedRouteRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MagicMethodRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodAddedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodChangedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodRemovedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodRenamedRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\MethodSignatureRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\NewPhpDependencyRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\OperatorRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\SideEffectRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\StrictTypesRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\TypeSystemRule;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Rules\ValueRule;
use Vistik\LaravelCodeAnalytics\Enums\FileGroup;
use Vistik\LaravelCodeAnalytics\Renderers\CakeLayer;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;
use Vistik\LaravelCodeAnalytics\Support\Detection\LaravelAppDetector;
use Vistik\LaravelCodeAnalytics\Support\Detection\LaravelPackageDetector;
use Vistik\LaravelCodeAnalytics\Support\Detection\PhpPackageDetector;
use Vistik\LaravelCodeAnalytics\Support\Detection\ProjectType;

return [

    /*
    |--------------------------------------------------------------------------
    | Project Type Detectors
    |--------------------------------------------------------------------------
    |
    | Ordered list of detectors used to determine the project type of the
    | repository being analysed. The first detector that returns true wins.
    | Each entry maps a Detector class to the ProjectType it represents.
    |
    | You may remove, reorder, or add your own detectors here. Every
    | detector must implement the Detector interface.
    |
    */

    'detectors' => [
        LaravelAppDetector::class => ProjectType::LaravelApp,
        LaravelPackageDetector::class => ProjectType::LaravelPackage,
        PhpPackageDetector::class => ProjectType::PhpPackage,
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis Rules
    |--------------------------------------------------------------------------
    |
    | Rules are grouped by project type. "generic" rules run for every project.
    | Additional rules are loaded based on the detected project type using the
    | ProjectType enum case name as key (e.g. "LaravelApp", "LaravelPackage").
    |
    | Each entry is a Rule class name. Constructor dependencies (AstComparer,
    | criticalTables, repoPath) are injected automatically.
    |
    | You may add, remove, or reorder rules per group.
    |
    */

    'rules' => [
        'generic' => [
            FileLevelRule::class,
            DependencyRule::class,
            CosmeticRule::class,
            ImportRule::class,
            StrictTypesRule::class,
            TypeSystemRule::class,
            MethodAddedRule::class,
            MethodChangedRule::class,
            MethodRemovedRule::class,
            MethodRenamedRule::class,
            MethodSignatureRule::class,
            ConstructorInjectionRule::class,
            NewPhpDependencyRule::class,
            ClassStructureRule::class,
            EnumRule::class,
            AttributeRule::class,
            MagicMethodRule::class,
            ControlFlowRule::class,
            OperatorRule::class,
            ValueRule::class,
            SideEffectRule::class,
            ErrorHandlingRule::class,
            AssignmentRule::class,
            DateTimeRule::class,
        ],

        ProjectType::LaravelApp->value => [
            LaravelMigrationRule::class,
            LaravelTableMigrationRule::class,
            LaravelDataMigrationRule::class,
            LaravelRouteRule::class,
            LaravelUnauthorizedRouteRule::class,
            LaravelEloquentRule::class,
            LaravelAuthRule::class,
            LaravelQueueRule::class,
            LaravelRedirectRule::class,
            LaravelNotificationRule::class,
            LaravelServiceContainerRule::class,
            LaravelConfigRule::class,
            LaravelConfigDependencyRule::class,
            LaravelApiResourceRule::class,
            LaravelLivewireRule::class,
            LaravelConsoleRule::class,
            LaravelConsoleSignatureChangedRule::class,
            LaravelConsoleArgumentAddedRule::class,
            LaravelConsoleArgumentRemovedRule::class,
            LaravelConsoleArgumentDefaultChangedRule::class,
            LaravelEnvironmentRule::class,
            LaravelCacheRule::class,
            LaravelDbFacadeRule::class,
        ],

        ProjectType::LaravelPackage->value => [
            // Laravel package-specific rules go here
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Watched Files
    |--------------------------------------------------------------------------
    |
    | List files or path patterns that should always surface at the top of the
    | file list, regardless of their signal score. Supports fnmatch-style globs
    | and directory prefixes ending in '/'. An optional 'reason' is shown in
    | the UI tooltip.
    |
    | Examples:
    |   ['pattern' => 'app/Http/Kernel.php', 'reason' => 'Boot critical'],
    |   ['pattern' => 'app/Models/*'],
    |   ['pattern' => 'config/',             'reason' => 'Config changes'],
    |
    */

    /*
    |--------------------------------------------------------------------------
    | PHP Method Metric Thresholds
    |--------------------------------------------------------------------------
    |
    | Controls the colour-coding of per-method badges shown in the diff viewer.
    | Each metric has a 'warn' level (amber) and a 'bad' level (red).
    | Values at or below 'warn' are shown in green.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Filter Defaults
    |--------------------------------------------------------------------------
    |
    | Set the default state of the interactive UI filters in the HTML report.
    | These can also be configured per-run via the JSON config file using the
    | same keys under "filter_defaults".
    |
    | hide_connected     - Hide dependency-only files (not in the diff) by default
    | hide_reviewed      - Hide files marked as reviewed by default
    | hidden_domains     - Domains hidden by default (e.g. ["tests"])
    | hidden_severities  - Severity levels hidden by default (e.g. ["info", "low"])
    | hidden_extensions  - File extensions hidden by default (e.g. [".blade.php"])
    | hidden_change_types - Change types hidden by default (e.g. ["added", "deleted"])
    |
    */

    // 'filter_defaults' => [
    //     'hide_connected'     => true,
    //     'hide_reviewed'      => true,
    //     'hidden_domains'     => ['tests'],
    //     'hidden_severities'  => [],
    //     'hidden_extensions'  => [],
    //     'hidden_change_types' => [],
    // ],

    /*
    |--------------------------------------------------------------------------
    | File Signal Scoring
    |--------------------------------------------------------------------------
    |
    | Controls how the per-file signal score is adjusted for special conditions.
    |
    | circular_dependency:
    |   Applied after the base signal (findings + changes + metrics) is computed.
    |   boost = base + signal_pct × base_signal
    |   Raise 'base' to always push cycle files high regardless of other factors.
    |   Raise 'signal_pct' to make the boost grow with the file's existing hotness.
    |
    */

    'file_signal' => [
        'circular_dependency' => [
            'base' => 100,
            'signal_pct' => 0.20,
        ],
        'lloc' => [
            'cutoff' => 200,
            'multiplier' => 0.5,
        ],
        'pr_connections' => [
            // Score added per edge to/from another changed file in the same PR.
            // More connections = more central to the PR = reviewed first.
            'multiplier' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk Scoring
    |--------------------------------------------------------------------------
    |
    | Controls how the overall 0-100 risk score is calculated. Each factor
    | contributes up to its 'max_score'; the total is then normalised to 0-100,
    | so raising one factor's max_score increases its relative weight.
    |
    | Thresholds must be listed highest-first. Any key omitted here will fall
    | back to this default, and per-run overrides can be added under
    | "risk_scoring" in the JSON config file.
    |
    */

    'risk_scoring' => [
        'change_size' => [
            'max_score' => 25,
            'thresholds' => [
                ['lines' => 1000, 'score' => 25],
                ['lines' => 500, 'score' => 15],
                ['lines' => 200, 'score' => 10],
                ['lines' => 50, 'score' => 5],
            ],
        ],
        'file_spread' => [
            'max_score' => 10,
            'thresholds' => [
                ['files' => 30, 'score' => 10],
                ['files' => 15, 'score' => 7],
                ['files' => 8, 'score' => 4],
                ['files' => 3, 'score' => 2],
            ],
        ],
        'deletion_ratio' => [
            'max_score' => 10,
            'thresholds' => [
                ['ratio' => 0.8, 'score' => 10],
                ['ratio' => 0.6, 'score' => 6],
                ['ratio' => 0.4, 'score' => 3],
            ],
        ],
        'severity_findings' => [
            'max_score' => 40,
            'weights' => [
                'very_high' => 10,
                'high' => 6,
                'medium' => 3,
                'low' => 1,
            ],
        ],
        'php_metrics' => [
            'max_score' => 15,
            'thresholds' => [
                ['hotspots' => 11, 'score' => 15],
                ['hotspots' => 8, 'score' => 12],
                ['hotspots' => 5, 'score' => 9],
                ['hotspots' => 3, 'score' => 6],
                ['hotspots' => 1, 'score' => 3],
            ],
        ],
    ],

    'method_metric_thresholds' => [
        'cc' => ['warn' => 5,  'bad' => 10],
        'lloc' => ['warn' => 20, 'bad' => 50],
        'params' => ['warn' => 3,  'bad' => 5],
    ],

    'watched_files' => [
    ],

    /*
    |--------------------------------------------------------------------------
    | File Groups
    |--------------------------------------------------------------------------
    |
    | Map FileGroup names to arrays of regex patterns. The first matching
    | pattern wins. Use this to override the default Laravel path conventions
    | or to teach the analyser about your custom directory layout.
    |
    | Keys must be valid FileGroup values: test, db, nova, model, action, job,
    | controller, request, http, console, provider, core, event, service,
    | view, frontend, config, route, other.
    |
    | Examples:
    |   FileGroup::MODEL->value    => ['app/Domain/.+/Models/', 'app/Models/'],
    |   FileGroup::SERVICE->value  => ['app/Domain/.+/Services/'],
    |   FileGroup::TEST->value     => ['^tests/', '^src/.+Test\.php$'],
    |
    */

    // 'file_groups' => [
    // ],

    /*
    |--------------------------------------------------------------------------
    | File Group Patterns (per project type)
    |--------------------------------------------------------------------------
    |
    | Maps FileGroup names to regex patterns for each detected project type.
    | The outer key is a ProjectType enum case name (LaravelApp, LaravelPackage,
    | PhpPackage). The inner keys are FileGroup values, and the values are arrays
    | of regex patterns — first match wins, groups are checked in definition order.
    |
    | When a project type is detected, its pattern set is used automatically.
    | If no entry exists for the detected type the built-in defaults apply.
    |
    */

    'file_group_patterns' => [

        ProjectType::LaravelApp->value => [
            FileGroup::TEST->value => ['^tests/'],
            FileGroup::DB->value => ['^database/'],
            FileGroup::NOVA->value => ['Nova/'],
            FileGroup::MODEL->value => ['^app/Models/'],
            FileGroup::ACTION->value => ['^app/Actions/'],
            FileGroup::JOB->value => ['^app/Jobs/'],
            FileGroup::CONTROLLER->value => ['^app/Http/Controllers/'],
            FileGroup::REQUEST->value => ['^app/Http/Requests/', '^app/Http/Resources/'],
            FileGroup::HTTP->value => ['^app/Http/'],
            FileGroup::CONSOLE->value => ['^app/Console/'],
            FileGroup::PROVIDER->value => ['^app/Providers/'],
            FileGroup::CORE->value => ['^app/Exceptions/', '^app/Enums/'],
            FileGroup::EVENT->value => ['^app/Events/', '^app/Listeners/'],
            FileGroup::SERVICE->value => ['^app/Services/'],
            FileGroup::VIEW->value => ['^resources/views/'],
            FileGroup::FRONTEND->value => ['^resources/(js|css)/'],
            FileGroup::CONFIG->value => ['^config/'],
            FileGroup::ROUTE->value => ['^routes/'],
        ],

        ProjectType::LaravelPackage->value => [
            FileGroup::TEST->value => ['^tests/'],
            FileGroup::DB->value => ['^database/'],
            FileGroup::NOVA->value => ['Nova/'],
            FileGroup::CONSOLE->value => ['^src/Console/'],
            FileGroup::PROVIDER->value => ['^src/Providers/', 'ServiceProvider\.php$'],
            FileGroup::CONTROLLER->value => ['^src/Http/Controllers/'],
            FileGroup::REQUEST->value => ['^src/Http/Requests/', '^src/Http/Resources/'],
            FileGroup::HTTP->value => ['^src/Http/'],
            FileGroup::ROUTE->value => ['^routes/', '^src/Routes/'],
            FileGroup::ACTION->value => ['^src/Actions/'],
            FileGroup::JOB->value => ['^src/Jobs/'],
            FileGroup::EVENT->value => ['^src/Events/', '^src/Listeners/'],
            FileGroup::SERVICE->value => ['^src/Services/'],
            FileGroup::MODEL->value => ['^src/Models/'],
            FileGroup::CORE->value => ['^src/Enums/', '^src/Exceptions/', '^src/Contracts/', '^src/'],
            FileGroup::VIEW->value => ['^resources/views/'],
            FileGroup::FRONTEND->value => ['^resources/(js|css)/'],
            FileGroup::CONFIG->value => ['^config/'],
        ],

        ProjectType::PhpPackage->value => [
            FileGroup::TEST->value => ['^tests/'],
            FileGroup::DB->value => ['^database/'],
            FileGroup::CONSOLE->value => ['^src/Console/', '^bin/'],
            FileGroup::HTTP->value => ['^src/Http/'],
            FileGroup::ACTION->value => ['^src/Actions/'],
            FileGroup::SERVICE->value => ['^src/Services/'],
            FileGroup::EVENT->value => ['^src/Events/', '^src/Listeners/'],
            FileGroup::MODEL->value => ['^src/Models/', '^src/Entities/', '^src/Entity/'],
            FileGroup::CORE->value => ['^src/'],
            FileGroup::VIEW->value => ['^resources/views/'],
            FileGroup::FRONTEND->value => ['^resources/(js|css)/'],
            FileGroup::CONFIG->value => ['^config/'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Architecture Layer Stack
    |--------------------------------------------------------------------------
    |
    | Define the architectural layers used by the "cake" and "arch" layouts.
    | Each layer is rendered as a ring (cake) or column (arch) from outermost/
    | leftmost (first) to innermost/rightmost (last). Every FileGroup must
    | appear in exactly one layer. Groups not listed here fall into the last
    | layer automatically.
    |
    */

    'layer_stacks' => [

        ProjectType::LaravelApp->value => new LayerStack(
            new CakeLayer(label: 'Entry', color: '#ffa657', groups: [
                FileGroup::ROUTE,
                FileGroup::CONFIG,
            ]),
            new CakeLayer(label: 'Controllers', color: '#d29922', groups: [
                FileGroup::CONTROLLER,
                FileGroup::HTTP,
                FileGroup::CONSOLE,
            ]),
            new CakeLayer(label: 'Requests / Resources', color: '#e3b341', groups: [
                FileGroup::REQUEST,
            ]),
            new CakeLayer(label: 'Application', color: '#79c0ff', groups: [
                FileGroup::SERVICE,
                FileGroup::ACTION,
                FileGroup::JOB,
                FileGroup::EVENT,
            ]),
            new CakeLayer(label: 'Domain', color: '#3fb950', groups: [
                FileGroup::MODEL,
                FileGroup::CORE,
                FileGroup::NOVA,
            ]),
            new CakeLayer(label: 'Infrastructure', color: '#8957e5', groups: [
                FileGroup::DB,
                FileGroup::PROVIDER,
            ]),
            new CakeLayer(label: 'Presentation', color: '#7ee787', groups: [
                FileGroup::VIEW,
                FileGroup::FRONTEND,
            ]),
            new CakeLayer(label: 'Testing', color: '#58a6ff', groups: [
                FileGroup::TEST,
                FileGroup::OTHER,
            ]),
        ),

        ProjectType::LaravelPackage->value => new LayerStack(
            new CakeLayer(label: 'Provider & Config', color: '#56d4dd', groups: [
                FileGroup::PROVIDER,
                FileGroup::CONFIG,
            ]),
            new CakeLayer(label: 'Console', color: '#b392f0', groups: [
                FileGroup::CONSOLE,
            ]),
            new CakeLayer(label: 'HTTP', color: '#f0883e', groups: [
                FileGroup::CONTROLLER,
                FileGroup::HTTP,
                FileGroup::REQUEST,
                FileGroup::ROUTE,
            ]),
            new CakeLayer(label: 'Application', color: '#79c0ff', groups: [
                FileGroup::ACTION,
                FileGroup::SERVICE,
                FileGroup::JOB,
                FileGroup::EVENT,
            ]),
            new CakeLayer(label: 'Core', color: '#3fb950', groups: [
                FileGroup::CORE,
                FileGroup::MODEL,
            ]),
            new CakeLayer(label: 'Infrastructure', color: '#8957e5', groups: [
                FileGroup::DB,
                FileGroup::NOVA,
            ]),
            new CakeLayer(label: 'Presentation', color: '#7ee787', groups: [
                FileGroup::VIEW,
                FileGroup::FRONTEND,
            ]),
            new CakeLayer(label: 'Testing', color: '#58a6ff', groups: [
                FileGroup::TEST,
                FileGroup::OTHER,
            ]),
        ),

        ProjectType::PhpPackage->value => new LayerStack(
            new CakeLayer(label: 'Config', color: '#ffa657', groups: [
                FileGroup::CONFIG,
            ]),
            new CakeLayer(label: 'Application', color: '#79c0ff', groups: [
                FileGroup::ACTION,
                FileGroup::SERVICE,
                FileGroup::JOB,
                FileGroup::EVENT,
            ]),
            new CakeLayer(label: 'Domain', color: '#3fb950', groups: [
                FileGroup::CORE,
                FileGroup::MODEL,
            ]),
            new CakeLayer(label: 'HTTP', color: '#f0883e', groups: [
                FileGroup::CONTROLLER,
                FileGroup::HTTP,
                FileGroup::REQUEST,
                FileGroup::ROUTE,
            ]),
            new CakeLayer(label: 'Infrastructure', color: '#8957e5', groups: [
                FileGroup::DB,
                FileGroup::PROVIDER,
                FileGroup::NOVA,
            ]),
            new CakeLayer(label: 'Presentation', color: '#7ee787', groups: [
                FileGroup::CONSOLE,
                FileGroup::VIEW,
                FileGroup::FRONTEND,
            ]),
            new CakeLayer(label: 'Testing', color: '#58a6ff', groups: [
                FileGroup::TEST,
                FileGroup::OTHER,
            ]),
        ),

    ],

];
