<?php

namespace Vistik\LaravelCodeAnalytics\Renderers;

class ForceGraphRenderer implements LayoutRenderer
{
    public function getLayoutSetupJs(): string
    {
        return <<<'JS'
// ── Force graph: circular initial positioning ─────────────────────────────────
for (var i = 0; i < nodes.length; i++) {
  var n = nodes[i];
  var angle = (i / nodes.length) * Math.PI * 2;
  var spread = Math.min(W, H) * 0.32;
  var connSpread = spread * 1.3;
  n.x = W/2 + Math.cos(angle) * (n.isConnected ? connSpread : spread) + (Math.random() - .5) * 80;
  n.y = H/2 + Math.sin(angle) * (n.isConnected ? connSpread : spread) + (Math.random() - .5) * 80;
}
JS;
    }

    public function getSimulationJs(): string
    {
        return <<<'JS'
// ── Physics ───────────────────────────────────────────────────────────────────
function simulate() {
  const repulsion = 4500, attraction = 0.004, damping = 0.88, centerPull = 0.002;
  const vis = nodes.filter(isVisible);
  for (let i = 0; i < vis.length; i++) {
    for (let j = i + 1; j < vis.length; j++) {
      const a = vis[i], b = vis[j];
      let dx = a.x - b.x, dy = a.y - b.y;
      let dist = Math.sqrt(dx*dx + dy*dy) || 1;
      let force = repulsion / (dist * dist);
      let fx = (dx / dist) * force, fy = (dy / dist) * force;
      if (!a.pinned) { a.vx += fx; a.vy += fy; }
      if (!b.pinned) { b.vx -= fx; b.vy -= fy; }
    }
  }
  for (const l of links) {
    if (!isLinkVisible(l)) continue;
    let dx = l.target.x - l.source.x, dy = l.target.y - l.source.y;
    let dist = Math.sqrt(dx*dx + dy*dy) || 1;
    let force = (dist - 120) * attraction;
    let fx = (dx / dist) * force, fy = (dy / dist) * force;
    if (!l.source.pinned) { l.source.vx += fx; l.source.vy += fy; }
    if (!l.target.pinned) { l.target.vx -= fx; l.target.vy -= fy; }
  }
  for (const n of vis) {
    if (n.pinned) continue;
    n.vx += (W/2 - n.x) * centerPull;
    n.vy += (H/2 - n.y) * centerPull;
    n.vx *= damping; n.vy *= damping;
    n.x += n.vx; n.y += n.vy;
  }
}
JS;
    }

    public function getFrameHookJs(): string
    {
        return 'simulate();';
    }
}
