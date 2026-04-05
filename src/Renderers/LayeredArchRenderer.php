<?php

namespace Vistik\LaravelCodeAnalytics\Renderers;

class LayeredArchRenderer implements LayoutRenderer
{
    private LayerStack $stack;

    public function __construct(?LayerStack $stack = null)
    {
        $this->stack = $stack ?? LayerStack::fromConfig();
    }

    public function getLayoutSetupJs(): string
    {
        $layersJs = $this->stack->buildLayerMapJs();
        $colorsJs = $this->stack->buildColorsJs();
        $labelsJs = $this->stack->buildLabelsJs();
        $fallbackLayer = $this->stack->fallbackIndex();

        return <<<JS
// ── Layered Architecture: left-to-right columns ──────────────────────────────

var archLayers = {$layersJs};
var archLayerColors = {$colorsJs};
var archLayerLabels = {$labelsJs};

nodes.forEach(function(n) {
  var info = archLayers[n.group] || { layer: {$fallbackLayer}, label: 'Other' };
  n.archLayer = info.layer;
  n.archLabel = info.label;
});

var archColumnData = [];

function computeArchLayout() {
  var vis = nodes.filter(isVisible);
  if (vis.length === 0) { archColumnData = []; return; }

  var padding = 80;
  var headerHeight = 32;
  var colGap = 24;

  // Group visible nodes by layer
  var layerBuckets = {};
  vis.forEach(function(n) {
    if (!layerBuckets[n.archLayer]) layerBuckets[n.archLayer] = [];
    layerBuckets[n.archLayer].push(n);
  });

  var activeLayers = Object.keys(layerBuckets).map(Number).sort(function(a, b) { return a - b; });
  var numCols = activeLayers.length;
  if (numCols === 0) { archColumnData = []; return; }

  var totalGaps = (numCols - 1) * colGap;
  var colWidth = (W - padding * 2 - totalGaps) / numCols;
  archColumnData = [];

  activeLayers.forEach(function(layerIdx, colIdx) {
    var x1 = padding + colIdx * (colWidth + colGap);
    var x2 = x1 + colWidth;
    var midX = (x1 + x2) / 2;
    var colNodes = layerBuckets[layerIdx];

    archColumnData.push({
      x1: x1,
      x2: x2,
      midX: midX,
      color: archLayerColors[layerIdx] || '#8b949e',
      label: archLayerLabels[layerIdx] || 'Other',
      layer: layerIdx,
      nodes: colNodes,
    });

    // Distribute nodes vertically with generous spacing
    var yStart = padding + headerHeight;
    var yEnd = H - padding;
    var yRange = yEnd - yStart;
    var nodeSpacing = Math.min(60, yRange / (colNodes.length + 1));
    var totalNodeHeight = nodeSpacing * (colNodes.length - 1);
    var yOffset = yStart + (yRange - totalNodeHeight) / 2;

    for (var i = 0; i < colNodes.length; i++) {
      colNodes[i].x = midX;
      colNodes[i].y = colNodes.length === 1
        ? yStart + yRange / 2
        : yOffset + i * nodeSpacing;
      colNodes[i].pinned = true;
    }
  });
}

var recomputeLayout = computeArchLayout;
computeArchLayout();
JS;
    }

    public function getSimulationJs(): string
    {
        return <<<'JS'
// ── Layered Architecture: static layout, no physics ──────────────────────────
function simulate() {}
JS;
    }

    public function getFrameHookJs(): string
    {
        return <<<'JS'
  // Draw column bands
  if (typeof archColumnData !== 'undefined') {
    var padding = 60;

    for (var ci = 0; ci < archColumnData.length; ci++) {
      var col = archColumnData[ci];

      // Column background
      ctx.fillStyle = col.color + '08';
      ctx.fillRect(col.x1, padding, col.x2 - col.x1, H - padding * 2);

      // Column left border (skip first column)
      if (ci > 0) {
        ctx.beginPath();
        ctx.moveTo(col.x1, padding);
        ctx.lineTo(col.x1, H - padding);
        ctx.strokeStyle = col.color + '20';
        ctx.lineWidth = 1;
        ctx.stroke();
      }

      // Column outer border
      ctx.strokeStyle = col.color + '25';
      ctx.lineWidth = 1;
      ctx.strokeRect(col.x1, padding, col.x2 - col.x1, H - padding * 2);

      // Layer label at top
      ctx.font = 'bold 11px -apple-system, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'top';
      ctx.fillStyle = col.color + '90';
      ctx.fillText(col.label, col.midX, padding + 8);
    }

    // Draw flow arrows between adjacent columns
    ctx.strokeStyle = '#484f5820';
    ctx.lineWidth = 1;
    for (var ci = 0; ci < archColumnData.length - 1; ci++) {
      var col = archColumnData[ci];
      var midY = H / 2;
      var arrowX = col.x2;

      ctx.beginPath();
      ctx.moveTo(arrowX - 6, midY - 4);
      ctx.lineTo(arrowX, midY);
      ctx.lineTo(arrowX - 6, midY + 4);
      ctx.stroke();
    }
  }
JS;
    }
}
