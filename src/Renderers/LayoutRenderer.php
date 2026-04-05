<?php

namespace Vistik\LaravelCodeAnalytics\Renderers;

interface LayoutRenderer
{
    /** JS inserted after node/link construction — position nodes and define layout functions. */
    public function getLayoutSetupJs(): string;

    /** JS for the simulation/layout function definition (replaces the physics section). */
    public function getSimulationJs(): string;

    /** JS called at the start of each animation frame (e.g. "simulate();" or ""). */
    public function getFrameHookJs(): string;
}
