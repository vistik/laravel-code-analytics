<?php

use Vistik\LaravelCodeAnalytics\Enums\NodeGroup;
use Vistik\LaravelCodeAnalytics\Renderers\CakeLayer;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;

return [

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
