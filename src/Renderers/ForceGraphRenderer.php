<?php

namespace Vistik\LaravelCodeAnalytics\Renderers;

class ForceGraphRenderer implements LayoutRenderer
{
    public function getLayoutSetupJs(): string
    {
        return <<<'JS'
// ── Force graph: circular initial positioning ─────────────────────────────────
var _cnt = nodes.length;
// Scale radius so adjacent nodes are ≥15 px apart — prevents the explosive
// repulsion burst that occurs when hundreds of nodes are crammed onto a tiny circle.
var spread = Math.max(Math.min(W, H) * 0.32, (_cnt * 15) / (2 * Math.PI));
spread = Math.min(spread, Math.min(W, H) * 0.7);
var connSpread = spread * 1.3;
for (var i = 0; i < _cnt; i++) {
  var n = nodes[i];
  var angle = (i / _cnt) * Math.PI * 2;
  n.x = W/2 + Math.cos(angle) * (n.isConnected ? connSpread : spread) + (Math.random() - .5) * 60;
  n.y = H/2 + Math.sin(angle) * (n.isConnected ? connSpread : spread) + (Math.random() - .5) * 60;
}
// Pre-warm: run before first render. With the spatial grid each tick is cheap
// so 300 ticks is fine even for large graphs.
for (var _w = 0; _w < 300; _w++) simulate();
JS;
    }

    public function getSimulationJs(): string
    {
        return <<<'JS'
// ── Physics ───────────────────────────────────────────────────────────────────
var _simFrame = 0, _simSettled = false;
function simulate() {
  const vis = nodes.filter(isVisible);
  const cnt = vis.length;
  if (!cnt) return;
  // Scale repulsion so total force stays bounded as node count grows.
  // 60 nodes → 4500 (unchanged); 300 nodes → 900; 763 nodes → 354.
  const repulsion = Math.min(4500, 4500 * 60 / Math.max(cnt, 60));
  const attraction = 0.004, damping = 0.88, velCap = 40;
  // Center pull strengthens slightly on large graphs to resist the spread.
  const centerPull = Math.max(0.002, 0.001 * cnt / 50);
  // ── Spatial grid: only compute repulsion between nearby nodes ──────────────
  // cell size covers the effective range where repulsion is non-trivial.
  const cell = Math.ceil(Math.sqrt(repulsion) * 3);
  const grid = Object.create(null);
  for (let i = 0; i < cnt; i++) {
    vis[i]._vi = i;
    const k = (Math.floor(vis[i].x / cell) + 500) * 1000 + (Math.floor(vis[i].y / cell) + 500);
    grid[k] ? grid[k].push(vis[i]) : (grid[k] = [vis[i]]);
  }
  for (let i = 0; i < cnt; i++) {
    const a = vis[i];
    const gx = Math.floor(a.x / cell), gy = Math.floor(a.y / cell);
    for (let ddx = -1; ddx <= 1; ddx++) {
      for (let ddy = -1; ddy <= 1; ddy++) {
        const bucket = grid[(gx + ddx + 500) * 1000 + (gy + ddy + 500)];
        if (!bucket) continue;
        for (let k = 0; k < bucket.length; k++) {
          const b = bucket[k];
          if (b._vi <= a._vi) continue; // each pair once
          let dx = a.x - b.x, dy = a.y - b.y;
          let dist = Math.sqrt(dx*dx + dy*dy) || 1;
          let f = repulsion / (dist * dist);
          let fx = dx / dist * f, fy = dy / dist * f;
          if (!a.pinned) { a.vx += fx; a.vy += fy; }
          if (!b.pinned) { b.vx -= fx; b.vy -= fy; }
        }
      }
    }
  }
  // ── Link spring ────────────────────────────────────────────────────────────
  for (const l of links) {
    if (!isLinkVisible(l)) continue;
    let dx = l.target.x - l.source.x, dy = l.target.y - l.source.y;
    let dist = Math.sqrt(dx*dx + dy*dy) || 1;
    let f = (dist - 120) * attraction;
    let fx = dx / dist * f, fy = dy / dist * f;
    if (!l.source.pinned) { l.source.vx += fx; l.source.vy += fy; }
    if (!l.target.pinned) { l.target.vx -= fx; l.target.vy -= fy; }
  }
  // ── Integrate: center pull, damping, velocity cap, convergence check ───────
  let maxV2 = 0;
  for (const n of vis) {
    if (n.pinned) continue;
    n.vx += (W/2 - n.x) * centerPull;
    n.vy += (H/2 - n.y) * centerPull;
    n.vx *= damping; n.vy *= damping;
    if (n.vx > velCap) n.vx = velCap; else if (n.vx < -velCap) n.vx = -velCap;
    if (n.vy > velCap) n.vy = velCap; else if (n.vy < -velCap) n.vy = -velCap;
    n.x += n.vx; n.y += n.vy;
    const v2 = n.vx * n.vx + n.vy * n.vy;
    if (v2 > maxV2) maxV2 = v2;
  }
  _simSettled = maxV2 < 0.05;
}
JS;
    }

    public function getFrameHookJs(): string
    {
        return <<<'JS'
// Run more ticks per frame early on for faster visual convergence, then taper off.
// Once settled, keep 1 tick/frame so dragging and filter changes stay reactive.
var _t = _simSettled ? 1 : (++_simFrame < 120) ? 10 : (_simFrame < 400) ? 4 : 1;
for (var _i = 0; _i < _t; _i++) simulate();
JS;
    }
}
