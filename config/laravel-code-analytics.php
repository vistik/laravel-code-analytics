<?php

use Vistik\LaravelCodeAnalytics\Enums\FileGroup;
use Vistik\LaravelCodeAnalytics\Renderers\CakeLayer;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;

return [

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
            'base'       => 100,
            'signal_pct' => 0.20,
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

    'layer_stack' => new LayerStack(
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

];
