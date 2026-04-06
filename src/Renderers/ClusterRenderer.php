<?php

namespace Vistik\LaravelCodeAnalytics\Renderers;

class ClusterRenderer implements LayoutRenderer
{
    public function getLayoutSetupJs(): string
    {
        return <<<'JS'
// ── Cluster layout: groups nodes by dependency cluster ──────────────────────
var clusterBoxes = [];

// Assign a distinct colour to each cluster index
var clusterPalette = [
  '#58a6ff', '#3fb950', '#d29922', '#f78166', '#d2a8ff',
  '#f778ba', '#79c0ff', '#7ee787', '#ff7b72', '#e3b341',
];

function computeClusterLayout() {
  // Reset cluster-hidden state so algo switches are reflected immediately
  nodes.forEach(function(n) { n._clusterHidden = false; });

  var vis = nodes.filter(isVisible);
  if (vis.length === 0) { clusterBoxes = []; return; }

  // Build a path → clusterIndex map from clustersData
  var pathToCluster = {};
  if (typeof clustersData !== 'undefined' && clustersData.length) {
    for (var ci = 0; ci < clustersData.length; ci++) {
      var files = clustersData[ci].files;
      for (var fi = 0; fi < files.length; fi++) {
        pathToCluster[files[fi]] = ci;
      }
    }
  }

  // Group visible nodes by cluster; unclustered nodes are hidden entirely
  var groups = {}; // clusterIndex → [nodes]
  vis.forEach(function(n) {
    var idx = pathToCluster[n.path];
    if (idx === undefined) {
      n._clusterHidden = true;
      return;
    }
    if (!groups[idx]) groups[idx] = [];
    groups[idx].push(n);
  });

  // Sort cluster indices ascending
  var groupKeys = Object.keys(groups).map(Number).sort(function(a, b) { return a - b; });

  // Layout constants
  var padding = 28;
  var nodeSpacing = 56;
  var headerH = 34;
  var boxPadding = 16;

  var boxes = groupKeys.map(function(idx) {
    var clusterNodes = groups[idx];
    clusterNodes.sort(function(a, b) { return a.id.localeCompare(b.id); });

    var maxR = 0;
    clusterNodes.forEach(function(n) { if (n.r > maxR) maxR = n.r; });

    var color = clusterPalette[idx % clusterPalette.length];
    var label = 'Cluster ' + (idx + 1) + ' \u00B7 ' + clusterNodes.length + ' files';

    var boxW = Math.max(200, maxR * 2 + 140);
    var boxH = headerH + boxPadding + clusterNodes.length * nodeSpacing + boxPadding;

    return { idx: idx, label: label, color: color, nodes: clusterNodes, w: boxW, h: boxH };
  });

  // Arrange in balanced columns
  var cols = Math.max(1, Math.min(5, Math.ceil(Math.sqrt(boxes.length))));
  var columns = [];
  for (var i = 0; i < cols; i++) columns.push({ boxes: [], height: 0 });

  boxes.forEach(function(box) {
    var shortest = columns[0];
    for (var i = 1; i < columns.length; i++) {
      if (columns[i].height < shortest.height) shortest = columns[i];
    }
    shortest.boxes.push(box);
    shortest.height += box.h + padding;
  });

  var colWidths = columns.map(function(col) {
    var maxW = 0;
    col.boxes.forEach(function(b) { if (b.w > maxW) maxW = b.w; });
    return maxW;
  });

  var totalWidth = colWidths.reduce(function(acc, w) { return acc + w + padding; }, -padding);
  var maxHeight = columns.reduce(function(acc, col) { return Math.max(acc, col.height); }, 0);

  // Position boxes and nodes
  clusterBoxes = [];
  var curX = W / 2 - totalWidth / 2;

  columns.forEach(function(col, ci) {
    var curY = H / 2 - maxHeight / 2;
    col.boxes.forEach(function(box) {
      var nodeX = curX + colWidths[ci] / 2;
      var innerY = curY + headerH + boxPadding;

      box.nodes.forEach(function(n) {
        if (!n.pinned) { n.x = nodeX; n.y = innerY + nodeSpacing / 2; }
        // Shorten label to strip common prefixes since the box shows the cluster
        n.displayLabel = n.id;
        innerY += nodeSpacing;
      });

      clusterBoxes.push({
        label: box.label, color: box.color,
        x: curX, y: curY, w: colWidths[ci], h: box.h,
      });
      curY += box.h + padding;
    });
    curX += colWidths[ci] + padding;
  });
}

var nodeDraggingDisabled = true;
var recomputeLayout = computeClusterLayout;
computeClusterLayout();
JS;
    }

    public function getSimulationJs(): string
    {
        return '// Cluster layout: no physics simulation';
    }

    public function getFrameHookJs(): string
    {
        return <<<'JS'
  // Draw cluster group boxes
  if (typeof clusterBoxes !== 'undefined') {
    for (var bi = 0; bi < clusterBoxes.length; bi++) {
      var box = clusterBoxes[bi];

      // Box background
      ctx.beginPath();
      ctx.roundRect(box.x, box.y, box.w, box.h, 8);
      ctx.fillStyle = 'rgba(22,27,34,0.85)';
      ctx.fill();
      ctx.strokeStyle = box.color + '50';
      ctx.lineWidth = 1;
      ctx.stroke();

      // Header bar
      ctx.beginPath();
      ctx.roundRect(box.x, box.y, box.w, 32, [8, 8, 0, 0]);
      ctx.fillStyle = box.color + '25';
      ctx.fill();

      // Header label
      ctx.font = 'bold 11px -apple-system, sans-serif';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = box.color;
      ctx.fillText(box.label, box.x + 12, box.y + 16);
    }
  }
JS;
    }
}
