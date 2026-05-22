<?php

namespace Vistik\LaravelCodeAnalytics\Reports;

class BridgeResolver
{
    public function resolve(GraphPayload $a, GraphPayload $b): BridgeResult
    {
        $aByPath = [];
        $bByPath = [];

        foreach ($a->nodes as $node) {
            $aByPath[$node['path']] = $node;
        }
        foreach ($b->nodes as $node) {
            $bByPath[$node['path']] = $node;
        }

        // Same-repo: match by shared path (diff nodes only — exclude connected↔connected)
        $sharedPaths = [];
        $bridges = [];
        foreach (array_intersect(array_keys($aByPath), array_keys($bByPath)) as $path) {
            $nodeA = $aByPath[$path];
            $nodeB = $bByPath[$path];
            if (!($nodeA['isConnected'] ?? false) || !($nodeB['isConnected'] ?? false)) {
                $sharedPaths[] = $path;
                $bridges[$nodeA['id']] = $nodeB['id'];
            }
        }

        if (!empty($bridges)) {
            return new BridgeResult($bridges, $sharedPaths);
        }

        // Cross-repo: match diff nodes in A against connected (ghost) nodes in B by FQCN
        // and vice versa.
        $aFqcns = $a->nodeFqcns; // nodeId => fqcn
        $bFqcns = $b->nodeFqcns; // nodeId => fqcn

        // Build fqcn => nodeId maps for each side's connected nodes
        $bConnectedByFqcn = [];
        foreach ($b->nodes as $node) {
            if (!($node['isConnected'] ?? false)) {
                continue;
            }
            $fqcn = $bFqcns[$node['id']] ?? null;
            if ($fqcn !== null) {
                $bConnectedByFqcn[$fqcn] = $node['id'];
            }
        }

        $aConnectedByFqcn = [];
        foreach ($a->nodes as $node) {
            if (!($node['isConnected'] ?? false)) {
                continue;
            }
            $fqcn = $aFqcns[$node['id']] ?? null;
            if ($fqcn !== null) {
                $aConnectedByFqcn[$fqcn] = $node['id'];
            }
        }

        // A's diff nodes that appear as ghost nodes in B
        foreach ($a->nodes as $nodeA) {
            if ($nodeA['isConnected'] ?? false) {
                continue;
            }
            $fqcn = $aFqcns[$nodeA['id']] ?? null;
            if ($fqcn !== null && isset($bConnectedByFqcn[$fqcn])) {
                $bridges[$nodeA['id']] = $bConnectedByFqcn[$fqcn];
            }
        }

        // B's diff nodes that appear as ghost nodes in A
        foreach ($b->nodes as $nodeB) {
            if ($nodeB['isConnected'] ?? false) {
                continue;
            }
            $fqcn = $bFqcns[$nodeB['id']] ?? null;
            if ($fqcn !== null && isset($aConnectedByFqcn[$fqcn])) {
                $bridges[$aConnectedByFqcn[$fqcn]] = $nodeB['id'];
            }
        }

        return new BridgeResult($bridges, $sharedPaths);
    }
}
