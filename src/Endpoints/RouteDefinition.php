<?php

namespace Vistik\LaravelCodeAnalytics\Endpoints;

readonly class RouteDefinition
{
    public function __construct(
        public string $method,
        public string $uri,
        public ?string $name,
        public array $middleware,
        public ?string $handlerFqcn,
        public string $handlerMethod,
    ) {}
}
