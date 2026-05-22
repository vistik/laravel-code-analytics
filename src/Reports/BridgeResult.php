<?php

namespace Vistik\LaravelCodeAnalytics\Reports;

class BridgeResult
{
    /**
     * @param  array<string, string>  $bridges  nodeId in payload A => nodeId in payload B
     * @param  list<string>  $sharedPaths  file paths that appear in both PR diffs (same-repo)
     */
    public function __construct(
        public readonly array $bridges,
        public readonly array $sharedPaths = [],
    ) {}

    public function bridgeIdsInA(): array
    {
        return array_keys($this->bridges);
    }

    public function bridgeIdsInB(): array
    {
        return array_values($this->bridges);
    }

    public function isEmpty(): bool
    {
        return empty($this->bridges);
    }
}
