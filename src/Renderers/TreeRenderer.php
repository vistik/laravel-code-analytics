<?php

namespace Vistik\LaravelCodeAnalytics\Renderers;

class TreeRenderer implements LayoutRenderer
{
    public function getLayoutSetupJs(): string
    {
        return <<<'JS'
// ── Tree layout: hierarchical positioning ─────────────────────────────────────
function computeTreeLayout() {
  var vis = nodes.filter(isVisible);
  if (vis.length === 0) return;

  // Build adjacency for visible nodes
  var incoming = {}, outgoing = {};
  vis.forEach(function(n) { incoming[n.id] = []; outgoing[n.id] = []; });
  links.forEach(function(l) {
    if (!isLinkVisible(l)) return;
    if (outgoing[l.source.id]) outgoing[l.source.id].push(l.target.id);
    if (incoming[l.target.id]) incoming[l.target.id].push(l.source.id);
  });

  // Entry-point groups that anchor the tree (routes, HTTP controllers, jobs, scheduled commands)
  var entryGroups = ['route', 'http', 'controller', 'job', 'console'];
  var roots = vis.filter(function(n) { return entryGroups.indexOf(n.group) !== -1; });

  // Fall back to topological roots (no incoming edges)
  if (roots.length === 0) {
    roots = vis.filter(function(n) { return incoming[n.id].length === 0; });
  }
  if (roots.length === 0) {
    // All cycles: pick nodes with most outgoing edges
    var sorted = vis.slice().sort(function(a, b) { return (outgoing[b.id] || []).length - (outgoing[a.id] || []).length; });
    roots = [sorted[0]];
  }

  // BFS longest-path layering
  var layer = {};
  var queue = [];
  roots.forEach(function(n) { layer[n.id] = 0; queue.push(n.id); });

  var maxIter = vis.length * 10, iter = 0;
  while (queue.length > 0 && iter < maxIter) {
    iter++;
    var id = queue.shift();
    var targets = outgoing[id] || [];
    for (var i = 0; i < targets.length; i++) {
      var tid = targets[i];
      var newLayer = layer[id] + 1;
      if (layer[tid] === undefined || layer[tid] < newLayer) {
        layer[tid] = newLayer;
        queue.push(tid);
      }
    }
  }

  // Assign unvisited nodes to layer 0
  vis.forEach(function(n) { if (layer[n.id] === undefined) layer[n.id] = 0; });

  // Group by layer, sort within layer by domain
  var layerGroups = {};
  vis.forEach(function(n) {
    var l = layer[n.id];
    if (!layerGroups[l]) layerGroups[l] = [];
    layerGroups[l].push(n);
  });
  Object.keys(layerGroups).forEach(function(l) {
    layerGroups[l].sort(function(a, b) {
      return (a.domain || '').localeCompare(b.domain || '') || a.id.localeCompare(b.id);
    });
  });

  // Compute positions (left-to-right)
  var layerKeys = Object.keys(layerGroups).map(Number).sort(function(a, b) { return a - b; });
  var xSpacing = 260;
  var ySpacing = 70;

  // Find tallest layer for centering
  var maxInLayer = 0;
  layerKeys.forEach(function(l) { maxInLayer = Math.max(maxInLayer, layerGroups[l].length); });

  var totalWidth = layerKeys.length * xSpacing;
  var startX = W / 2 - totalWidth / 2 + xSpacing / 2;

  layerKeys.forEach(function(l, li) {
    var group = layerGroups[l];
    var totalHeight = group.length * ySpacing;
    var startY = H / 2 - totalHeight / 2 + ySpacing / 2;
    for (var i = 0; i < group.length; i++) {
      var n = group[i];
      if (!n.pinned) {
        n.x = startX + li * xSpacing;
        n.y = startY + i * ySpacing;
      }
    }
  });
}

var recomputeLayout = computeTreeLayout;
computeTreeLayout();
JS;
    }

    public function getSimulationJs(): string
    {
        return '// Tree layout: no physics simulation';
    }

    public function getFrameHookJs(): string
    {
        return '// static layout';
    }
}
