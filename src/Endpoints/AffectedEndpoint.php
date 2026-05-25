<?php

namespace Vistik\LaravelCodeAnalytics\Endpoints;

readonly class AffectedEndpoint
{
    public function __construct(
        public RouteDefinition $route,
        public string $triggeredByPath,
        public array $dependencyChain,
        public ?string $controllerNodeId = null,
        public array $reachableNodeIds = [],
        public array $reachableDepths = [],
    ) {}

    public function toArray(): array
    {
        return [
            'method' => $this->route->method,
            'uri' => $this->route->uri,
            'name' => $this->route->name,
            'middleware' => $this->route->middleware,
            'handlerFqcn' => $this->route->handlerFqcn,
            'handlerMethod' => $this->route->handlerMethod,
            'triggeredByPath' => $this->triggeredByPath,
            'dependencyChain' => $this->dependencyChain,
            'controllerNodeId' => $this->controllerNodeId,
            'reachableNodeIds' => $this->reachableNodeIds,
            'reachableDepths' => $this->reachableDepths,
        ];
    }
}
