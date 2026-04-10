<?php

use Vistik\LaravelCodeAnalytics\Enums\NodeGroup;
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

    'method_metric_thresholds' => [
        'cc' => ['warn' => 5,  'bad' => 10],
        'lloc' => ['warn' => 20, 'bad' => 50],
        'params' => ['warn' => 3,  'bad' => 5],
    ],

    'watched_files' => [
    ],

    /*
    |--------------------------------------------------------------------------
    | Architecture Layer Stack
    |--------------------------------------------------------------------------
    |
    | Define the architectural layers used by the "cake" and "arch" layouts.
    | Each layer is rendered as a ring (cake) or column (arch) from outermost/
    | leftmost (first) to innermost/rightmost (last). Every NodeGroup must
    | appear in exactly one layer. Groups not listed here fall into the last
    | layer automatically.
    |
    */

    'layer_stack' => new LayerStack(
        new CakeLayer(label: 'Entry', color: '#ffa657', groups: [
            NodeGroup::ROUTE,
            NodeGroup::CONFIG,
        ]),
        new CakeLayer(label: 'Controllers', color: '#d29922', groups: [
            NodeGroup::CONTROLLER,
            NodeGroup::HTTP,
            NodeGroup::CONSOLE,
        ]),
        new CakeLayer(label: 'Requests / Resources', color: '#e3b341', groups: [
            NodeGroup::REQUEST,
        ]),
        new CakeLayer(label: 'Application', color: '#79c0ff', groups: [
            NodeGroup::SERVICE,
            NodeGroup::ACTION,
            NodeGroup::JOB,
            NodeGroup::EVENT,
        ]),
        new CakeLayer(label: 'Domain', color: '#3fb950', groups: [
            NodeGroup::MODEL,
            NodeGroup::CORE,
            NodeGroup::NOVA,
        ]),
        new CakeLayer(label: 'Infrastructure', color: '#8957e5', groups: [
            NodeGroup::DB,
            NodeGroup::PROVIDER,
        ]),
        new CakeLayer(label: 'Presentation', color: '#7ee787', groups: [
            NodeGroup::VIEW,
            NodeGroup::FRONTEND,
        ]),
        new CakeLayer(label: 'Testing', color: '#58a6ff', groups: [
            NodeGroup::TEST,
            NodeGroup::OTHER,
        ]),
    ),

];
