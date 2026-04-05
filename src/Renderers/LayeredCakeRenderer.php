<?php

namespace Vistik\LaravelCodeAnalytics\Renderers;

class LayeredCakeRenderer implements LayoutRenderer
{
    private LayerStack $stack;

    public function __construct(?LayerStack $stack = null)
    {
        $this->stack = $stack ?? LayerStack::fromConfig();
    }

    public function getLayoutSetupJs(): string
    {
        $cakeLayersJs = $this->stack->buildLayerMapJs();
        $cakeLayerColorsJs = $this->stack->buildColorsJs();
        $cakeLayerLabelsJs = $this->stack->buildLabelsJs();
        $fallbackLayer = $this->stack->fallbackIndex();

        return <<<JS
// ── Layered Cake: concentric rings with force-directed inner nodes ───────────

// Map groups to architectural layers (0 = outermost entry, higher = deeper)
var cakeLayers = {$cakeLayersJs};

var cakeLayerColors = {$cakeLayerColorsJs};

var cakeLayerLabels = {$cakeLayerLabelsJs};

// Assign layer info to each node
nodes.forEach(function(n) {
  var info = cakeLayers[n.group] || { layer: {$fallbackLayer}, label: 'Other' };
  n.cakeLayer = info.layer;
  n.cakeLabel = info.label;
});

// Ring geometry
var cakeRingData = []; // [{innerR, outerR, midR, color, label, nodes}]

function computeCakeLayout() {
  var vis = nodes.filter(isVisible);
  if (vis.length === 0) { cakeRingData = []; return; }

  var cx = W / 2, cy = H / 2;
  var maxR = Math.min(W, H) * 0.44;

  // Group visible nodes by layer
  var layerBuckets = {};
  vis.forEach(function(n) {
    if (!layerBuckets[n.cakeLayer]) layerBuckets[n.cakeLayer] = [];
    layerBuckets[n.cakeLayer].push(n);
  });

  // Find which layers are populated
  var activeLayers = Object.keys(layerBuckets).map(Number).sort(function(a, b) { return a - b; });
  var numRings = activeLayers.length;
  if (numRings === 0) { cakeRingData = []; return; }

  // Build rings from outside in: layer 0 = outermost ring
  var ringThickness = maxR / numRings;
  cakeRingData = [];

  activeLayers.forEach(function(layerIdx, ringIdx) {
    var outerR = maxR - ringIdx * ringThickness;
    var innerR = outerR - ringThickness + 4; // small gap between rings
    if (innerR < 0) innerR = 0;
    var midR = (outerR + innerR) / 2;

    cakeRingData.push({
      innerR: innerR,
      outerR: outerR,
      midR: midR,
      color: cakeLayerColors[layerIdx] || '#8b949e',
      label: cakeLayerLabels[layerIdx] || 'Other',
      layer: layerIdx,
      nodes: layerBuckets[layerIdx],
    });
  });

  // Position entry-layer nodes (ring 0) evenly on their ring, pinned
  var entryRing = cakeRingData[0];
  if (entryRing) {
    var entryNodes = entryRing.nodes;
    for (var i = 0; i < entryNodes.length; i++) {
      var angle = (i / entryNodes.length) * Math.PI * 2 - Math.PI / 2;
      entryNodes[i].x = cx + Math.cos(angle) * entryRing.midR;
      entryNodes[i].y = cy + Math.sin(angle) * entryRing.midR;
      entryNodes[i].cakePinned = true;
    }
  }

  // Position inner nodes on their ring with slight randomness
  for (var ri = 1; ri < cakeRingData.length; ri++) {
    var ring = cakeRingData[ri];
    var rNodes = ring.nodes;
    for (var i = 0; i < rNodes.length; i++) {
      if (rNodes[i].pinned) continue;
      var angle = (i / rNodes.length) * Math.PI * 2 - Math.PI / 2 + (Math.random() - 0.5) * 0.3;
      rNodes[i].x = cx + Math.cos(angle) * ring.midR;
      rNodes[i].y = cy + Math.sin(angle) * ring.midR;
      rNodes[i].cakePinned = false;
    }
  }
}

var recomputeLayout = computeCakeLayout;
computeCakeLayout();
JS;
    }

    public function getSimulationJs(): string
    {
        return <<<'JS'
// ── Layered Cake physics: force-directed with radial constraints ─────────────
function simulate() {
  var repulsion = 3000, attraction = 0.005, damping = 0.85;
  var radialStrength = 0.06;
  var cx = W / 2, cy = H / 2;

  var vis = nodes.filter(isVisible);

  // Repulsion between all visible nodes
  for (var i = 0; i < vis.length; i++) {
    for (var j = i + 1; j < vis.length; j++) {
      var a = vis[i], b = vis[j];
      var dx = a.x - b.x, dy = a.y - b.y;
      var dist = Math.sqrt(dx * dx + dy * dy) || 1;
      var force = repulsion / (dist * dist);
      var fx = (dx / dist) * force, fy = (dy / dist) * force;
      if (!a.pinned && !a.cakePinned) { a.vx += fx; a.vy += fy; }
      if (!b.pinned && !b.cakePinned) { b.vx -= fx; b.vy -= fy; }
    }
  }

  // Edge attraction
  for (var li = 0; li < links.length; li++) {
    var l = links[li];
    if (!isLinkVisible(l)) continue;
    var dx = l.target.x - l.source.x, dy = l.target.y - l.source.y;
    var dist = Math.sqrt(dx * dx + dy * dy) || 1;
    var force = (dist - 100) * attraction;
    var fx = (dx / dist) * force, fy = (dy / dist) * force;
    if (!l.source.pinned && !l.source.cakePinned) { l.source.vx += fx; l.source.vy += fy; }
    if (!l.target.pinned && !l.target.cakePinned) { l.target.vx -= fx; l.target.vy -= fy; }
  }

  // Radial constraint: pull each node toward its ring's midR
  if (typeof cakeRingData !== 'undefined') {
    // Build layer-to-ring lookup
    var ringByLayer = {};
    for (var ri = 0; ri < cakeRingData.length; ri++) {
      ringByLayer[cakeRingData[ri].layer] = cakeRingData[ri];
    }

    for (var i = 0; i < vis.length; i++) {
      var n = vis[i];
      if (n.pinned || n.cakePinned) continue;
      var ring = ringByLayer[n.cakeLayer];
      if (!ring) continue;

      var dx = n.x - cx, dy = n.y - cy;
      var currentR = Math.sqrt(dx * dx + dy * dy) || 1;
      var targetR = ring.midR;
      var diff = targetR - currentR;

      // Push node toward target radius
      n.vx += (dx / currentR) * diff * radialStrength;
      n.vy += (dy / currentR) * diff * radialStrength;
    }
  }

  // Apply velocity with damping
  for (var i = 0; i < vis.length; i++) {
    var n = vis[i];
    if (n.pinned || n.cakePinned) continue;
    n.vx *= damping;
    n.vy *= damping;
    n.x += n.vx;
    n.y += n.vy;
  }
}
JS;
    }

    public function getFrameHookJs(): string
    {
        return <<<'JS'
  simulate();

  // Draw concentric ring bands
  if (typeof cakeRingData !== 'undefined') {
    var cx = W / 2, cy = H / 2;

    for (var ri = cakeRingData.length - 1; ri >= 0; ri--) {
      var ring = cakeRingData[ri];

      // Ring band fill
      ctx.beginPath();
      ctx.arc(cx, cy, ring.outerR, 0, Math.PI * 2);
      if (ring.innerR > 0) {
        ctx.arc(cx, cy, ring.innerR, 0, Math.PI * 2, true);
      }
      ctx.closePath();
      ctx.fillStyle = ring.color + '08';
      ctx.fill();

      // Ring border
      ctx.beginPath();
      ctx.arc(cx, cy, ring.outerR, 0, Math.PI * 2);
      ctx.strokeStyle = ring.color + '25';
      ctx.lineWidth = 1;
      ctx.stroke();

      // Layer label on the outer edge (top)
      ctx.font = 'bold 11px -apple-system, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'bottom';
      ctx.fillStyle = ring.color + '80';
      ctx.fillText(ring.label, cx, cy - ring.outerR + 16);
    }
  }
JS;
    }
}
