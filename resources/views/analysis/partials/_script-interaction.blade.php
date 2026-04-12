// ── Panel resize ──────────────────────────────────────────────────────────────
var panelWidth = Math.round(window.innerWidth * 0.3);
panel.style.width = panelWidth + 'px';
var resizeHandle = document.getElementById('panel-resize');
var isResizing = false;

resizeHandle.addEventListener('mousedown', function(e) {
  e.preventDefault();
  e.stopPropagation();
  isResizing = true;
  resizeHandle.classList.add('active');
  panel.style.transition = 'none';
  document.body.style.cursor = 'col-resize';
  document.body.style.userSelect = 'none';
});

document.addEventListener('mousemove', function(e) {
  if (!isResizing) return;
  var newWidth = window.innerWidth - e.clientX;
  newWidth = Math.max(360, Math.min(newWidth, window.innerWidth * 0.85));
  panelWidth = newWidth;
  panel.style.width = panelWidth + 'px';
});

document.addEventListener('mouseup', function() {
  if (!isResizing) return;
  isResizing = false;
  resizeHandle.classList.remove('active');
  panel.style.transition = '';
  document.body.style.cursor = '';
  document.body.style.userSelect = '';
});

// ── Interaction ───────────────────────────────────────────────────────────────
let dragNode = null, dragStartX = 0, dragStartY = 0, didDrag = false;

canvas.addEventListener('mousedown', e => {
  const [wx, wy] = screenToWorld(e.clientX, e.clientY);
  for (const n of nodes) {
    if (!isVisible(n)) continue;
    const dx = wx - n.x, dy = wy - n.y;
    if (dx*dx + dy*dy < n.r * n.r) {
      dragNode = n; dragStartX = e.clientX; dragStartY = e.clientY; didDrag = false;
      canvas.classList.add('grabbing');
      return;
    }
  }
  isPanning = true; didDrag = false; panStartX = e.clientX - panX; panStartY = e.clientY - panY;
  canvas.classList.add('grabbing');
});

canvas.addEventListener('mousemove', e => {
  if (isPanning) { panX = e.clientX - panStartX; panY = e.clientY - panStartY; didDrag = true; return; }
  if (dragNode) {
    if (typeof nodeDraggingDisabled !== 'undefined' && nodeDraggingDisabled) return;
    const dx = e.clientX - dragStartX, dy = e.clientY - dragStartY;
    if (dx*dx + dy*dy > 16) didDrag = true;
    const [wx, wy] = screenToWorld(e.clientX, e.clientY);
    dragNode.x = wx; dragNode.y = wy; dragNode.vx = 0; dragNode.vy = 0;
    return;
  }
  const [wx, wy] = screenToWorld(e.clientX, e.clientY);
  hoveredNode = null;
  for (const n of nodes) {
    if (!isVisible(n)) continue;
    const dx = wx - n.x, dy = wy - n.y;
    if (dx*dx + dy*dy < n.r * n.r) { hoveredNode = n; break; }
  }
  canvas.style.cursor = hoveredNode ? 'pointer' : 'grab';
  if (hoveredNode) {
    const n = hoveredNode;
    tooltip.style.display = 'block';
    tooltip.style.left = Math.min(e.clientX + 16, W - 400) + 'px';
    tooltip.style.top = (e.clientY + 16) + 'px';
    if (n.isConnected) {
      tooltip.innerHTML =
        '<div class="path">' + n.path + '</div>' +
        '<div class="stat"><span style="color:#6e7681">CONNECTED</span> &nbsp;Not changed in this PR</div>' +
        '<div class="hint">Click for details &middot; Shift+click to find paths</div>';
    } else {
      var severityLine = '';
      if (n.analysisCount > 0) {
        var parts = [];
        if (n.veryHighCount > 0) parts.push('<span style="color:' + sevColors.very_high + '">' + n.veryHighCount + ' very high</span>');
        if (n.highCount > 0) parts.push('<span style="color:' + sevColors.high + '">' + n.highCount + ' high</span>');
        if (n.mediumCount > 0) parts.push('<span style="color:' + sevColors.medium + '">' + n.mediumCount + ' medium</span>');
        if (n.lowCount > 0) parts.push('<span style="color:' + sevColors.low + '">' + n.lowCount + ' low</span>');
        if (n.infoCount > 0) parts.push('<span style="color:' + sevColors.info + '">' + n.infoCount + ' info</span>');
        if (parts.length > 0) severityLine = '<div class="stat">' + parts.join(' &middot; ') + '</div>';
      }
      var watchLine = '';
      if (n.watched) {
        watchLine = '<div class="stat"><span style="color:#d29922">&#9670; Watched</span>' +
          (n.watchReason ? ' <span style="color:#6e7681">' + n.watchReason + '</span>' : '') + '</div>';
      }
      var cycleLine = '';
      if (n.cycleId != null) {
        cycleLine = '<div class="stat"><span style="color:' + n.cycleColor + '">&#8635; Cycle ' + n.cycleId + '</span></div>';
      }
      tooltip.innerHTML =
        '<div class="path">' + n.path + '</div>' +
        '<div class="stat"><span class="added">+' + n.add + '</span> &nbsp;<span class="removed">&minus;' + n.del + '</span> &nbsp;' +
        (function(s) { var m = { added: ['#3fb950','NEW'], deleted: ['#f85149','DELETED'], renamed: ['#a5b4fc','RENAMED'], modified: ['#d29922','MODIFIED'] }; var p = m[s] || m.modified; return '<span style="color:' + p[0] + '">' + p[1] + '</span>'; })(n.status) + '</div>' +
        severityLine +
        watchLine +
        cycleLine +
        '<div class="hint">Click for details &middot; Shift+click to find paths</div>';
    }
  } else {
    tooltip.style.display = 'none';
  }
});

canvas.addEventListener('mouseup', e => {
  canvas.classList.remove('grabbing');
  var canPin = typeof nodeDraggingDisabled === 'undefined' || !nodeDraggingDisabled;
  if (dragNode && !didDrag) {
    closeLegend();
    if (e.shiftKey) {
      if (pathfindNodes.has(dragNode.id)) pathfindNodes.delete(dragNode.id);
      else pathfindNodes.add(dragNode.id);
      if (canPin) dragNode.pinned = true;
      computePathfinding();
    } else {
      clearPathfinding();
      clearNavStack();
      openPanel(dragNode);
      if (canPin) dragNode.pinned = true;
    }
  } else if (dragNode && didDrag && canPin) { dragNode.pinned = true; }
  else if (isPanning && !didDrag) { closeLegend(); closePanel(); clearPathfinding(); }
  dragNode = null; isPanning = false;
});

// ── Draw ──────────────────────────────────────────────────────────────────────
function draw() {
  ctx.clearRect(0, 0, W, H);
  ctx.save();
  ctx.translate(panX, panY);
  ctx.scale(zoom, zoom);
  {!! $frameHookJs !!}

  var extActive = externalHighlightNodes.size > 0;
  var ehov = hoveredNode;
  var activeRef = extActive || ehov || selectedNode;
  var pathActive = pathfindNodes.size >= 2;
  for (const l of links) {
    if (!isLinkVisible(l)) continue;
    const isSelected = selectedNode && (l.source === selectedNode || l.target === selectedNode);
    const isHovered = (ehov && (l.source === ehov || l.target === ehov)) ||
                      (extActive && externalHighlightNodes.has(l.source) && externalHighlightNodes.has(l.target));
    const isPath = pathActive && pathResult.edges.has(l);
    const highlight = isSelected || isHovered || isPath;
    const dimmed = pathActive ? (!isPath && !isHovered) : (activeRef && !highlight);
    const isConnEdge = l.source.isConnected || l.target.isConnected;
    const isCycleEdge = !isConnEdge && l.source.cycleId != null && l.source.cycleId === l.target.cycleId;

    // Direction color: outgoing (depends on) = red, incoming (used by) = green
    var dirColor = null;
    if (highlight && !isPath && activeRef) {
      if (l.source === activeRef) dirColor = 'rgba(248,81,73,0.85)';  // red: depends on
      else if (l.target === activeRef) dirColor = 'rgba(63,185,80,0.85)';  // green: used by
    }

    // Line — style varies by dependency type
    // constructor_injection: solid, thicker | method_injection: solid | new_instance: long dash
    // container_resolved: dash-dot | static_call: dotted | use: faint solid
    var depDash = [];
    if (!isConnEdge) {
      if      (l.depType === 'new_instance')       depDash = [8, 4];
      else if (l.depType === 'container_resolved') depDash = [6, 3, 2, 3];
      else if (l.depType === 'static_call')        depDash = [2, 4];
      else if (l.depType === 'use')                depDash = [3, 6];
    }
    ctx.beginPath(); ctx.moveTo(l.source.x, l.source.y); ctx.lineTo(l.target.x, l.target.y);
    if (isConnEdge && !highlight) {
      ctx.setLineDash([4, 4]);
      ctx.strokeStyle = dimmed ? 'rgba(48,54,61,0.1)' : 'rgba(72,79,88,0.35)';
    } else {
      ctx.setLineDash(highlight ? [] : depDash);
      const cycleEdgeColor = isCycleEdge ? hexAlpha(l.source.cycleColor, dimmed ? 0.15 : 0.7) : null;
      ctx.strokeStyle = isPath ? 'rgba(247,129,102,0.9)' : dirColor || cycleEdgeColor || (highlight ? 'rgba(88,166,255,0.85)' : dimmed ? 'rgba(48,54,61,0.2)' : 'rgba(139,148,158,0.3)');
    }
    var depWidth = (l.depType === 'constructor_injection' || l.depType === 'container_resolved') ? 2 : 1.5;
    ctx.lineWidth = isPath ? 3 : highlight ? 2.5 : (isCycleEdge ? 2 : depWidth); ctx.stroke(); ctx.setLineDash([]);

    // Arrowhead (always drawn, brighter when highlighted)
    const dx = l.target.x - l.source.x, dy = l.target.y - l.source.y;
    const dist = Math.sqrt(dx*dx+dy*dy) || 1;
    const ux = dx/dist, uy = dy/dist;
    const tipX = l.target.x - ux * l.target.r, tipY = l.target.y - uy * l.target.r;
    const size = isPath ? 10 : highlight ? 9 : 6;
    ctx.beginPath(); ctx.moveTo(tipX, tipY);
    ctx.lineTo(tipX - ux*size - uy*size*0.5, tipY - uy*size + ux*size*0.5);
    ctx.lineTo(tipX - ux*size + uy*size*0.5, tipY - uy*size - ux*size*0.5);
    ctx.closePath();
    const cycleArrowColor = isCycleEdge ? hexAlpha(l.source.cycleColor, dimmed ? 0.15 : 0.7) : null;
    ctx.fillStyle = isPath ? 'rgba(247,129,102,0.9)' : dirColor || cycleArrowColor || (highlight ? 'rgba(88,166,255,0.85)' : dimmed ? 'rgba(48,54,61,0.2)' : 'rgba(139,148,158,0.35)');
    ctx.fill();
  }

  for (const n of nodes) {
    if (!isVisible(n)) continue;
    const isHov = n === ehov || (extActive && externalHighlightNodes.has(n)), isSel = n === selectedNode;
    const isConn = (ehov && links.some(l => isLinkVisible(l) && ((l.source === ehov && l.target === n) || (l.target === ehov && l.source === n)))) ||
                   (selectedNode && links.some(l => isLinkVisible(l) && ((l.source === selectedNode && l.target === n) || (l.target === selectedNode && l.source === n))));
    const isPathNode = pathActive && pathResult.nodes.has(n.id);
    const isPathSelected = pathfindNodes.has(n.id);
    const dim = pathActive ? (!isPathNode && !isHov) : (activeRef && !isHov && !isSel && !isConn);

    if (n.focal && !n.isConnected) {
      ctx.beginPath(); ctx.arc(n.x, n.y, n.r + 8, 0, Math.PI * 2);
      const focalGrad = ctx.createRadialGradient(n.x, n.y, n.r, n.x, n.y, n.r + 8);
      focalGrad.addColorStop(0, n.color + '55'); focalGrad.addColorStop(1, 'transparent');
      ctx.fillStyle = focalGrad; ctx.fill();
    }
    if (n.status === 'added' && n.group !== 'test' && !n.isConnected) {
      ctx.beginPath(); ctx.arc(n.x, n.y, n.r + 6, 0, Math.PI * 2);
      const grad = ctx.createRadialGradient(n.x, n.y, n.r, n.x, n.y, n.r + 6);
      grad.addColorStop(0, n.color + '40'); grad.addColorStop(1, 'transparent');
      ctx.fillStyle = grad; ctx.fill();
    }
    if (n.status === 'deleted' && !n.isConnected) {
      ctx.beginPath(); ctx.arc(n.x, n.y, n.r + 16, 0, Math.PI * 2);
      const grad = ctx.createRadialGradient(n.x, n.y, n.r * 0.4, n.x, n.y, n.r + 16);
      grad.addColorStop(0, dim ? '#f8514918' : '#f8514960');
      grad.addColorStop(0.5, dim ? '#f8514908' : '#f8514930');
      grad.addColorStop(1, 'transparent');
      ctx.fillStyle = grad; ctx.fill();
    }
    if (isPathSelected) {
      ctx.beginPath(); ctx.arc(n.x, n.y, n.r + 5, 0, Math.PI * 2);
      ctx.strokeStyle = '#f78166'; ctx.lineWidth = 3;
      if (pathfindNodes.size < 2) { ctx.setLineDash([5, 5]); }
      ctx.stroke(); ctx.setLineDash([]);
    } else if (isSel) {
      ctx.beginPath(); ctx.arc(n.x, n.y, n.r + 4, 0, Math.PI * 2);
      ctx.strokeStyle = '#58a6ff'; ctx.lineWidth = 2.5; ctx.stroke();
    }
    // Circular dependency ring
    if (n.cycleId != null) {
      ctx.beginPath(); ctx.arc(n.x, n.y, n.r + (isSel ? 8 : isHov ? 7 : 4), 0, Math.PI * 2);
      ctx.setLineDash([4, 3]);
      ctx.strokeStyle = hexAlpha(n.cycleColor, dim ? 0.2 : 0.85);
      ctx.lineWidth = 1.5; ctx.stroke(); ctx.setLineDash([]);
    }
    ctx.beginPath(); ctx.arc(n.x, n.y, n.r, 0, Math.PI * 2);
    if (n.isConnected) {
      ctx.fillStyle = dim ? '#48405810' : ((isHov || isSel || isPathSelected) ? '#484f5860' : isPathNode ? '#484f5850' : '#484f5830');
    } else {
      ctx.fillStyle = dim ? (n.color + '25') : (n.color + ((isHov || isSel || isPathSelected) ? 'ff' : isPathNode ? 'dd' : (n.status === 'deleted' ? '65' : 'bb')));
    }
    ctx.fill();
    if (n.isConnected) {
      ctx.setLineDash([3, 3]); ctx.strokeStyle = dim ? '#48405820' : ((isHov || isSel) ? '#6e7681' : '#484f58');
      ctx.lineWidth = 1.5; ctx.stroke(); ctx.setLineDash([]);
    } else if (n.status === 'added') {
      ctx.setLineDash([4, 3]); ctx.strokeStyle = dim ? (n.color + '25') : n.color;
      ctx.lineWidth = 2; ctx.stroke(); ctx.setLineDash([]);
    } else if (n.status === 'deleted') {
      ctx.setLineDash([3, 3]); ctx.strokeStyle = dim ? '#f8514930' : '#f85149';
      ctx.lineWidth = 2.5; ctx.stroke(); ctx.setLineDash([]);
    } else if (n.status === 'renamed') {
      ctx.setLineDash([6, 3]); ctx.strokeStyle = dim ? '#a5b4fc25' : '#a5b4fc';
      ctx.lineWidth = 1.5; ctx.stroke(); ctx.setLineDash([]);
    } else {
      ctx.strokeStyle = dim ? '#30363d30' : '#30363d'; ctx.lineWidth = 1.5; ctx.stroke();
    }
    // Deleted X overlay
    if (n.status === 'deleted' && !n.isConnected) {
      const xOff = n.r * 0.48;
      ctx.save();
      ctx.beginPath(); ctx.arc(n.x, n.y, n.r - 2, 0, Math.PI * 2); ctx.clip();
      ctx.beginPath();
      ctx.moveTo(n.x - xOff, n.y - xOff); ctx.lineTo(n.x + xOff, n.y + xOff);
      ctx.moveTo(n.x + xOff, n.y - xOff); ctx.lineTo(n.x - xOff, n.y + xOff);
      ctx.strokeStyle = dim ? '#f8514920' : '#f8514970';
      ctx.lineWidth = dim ? 1.5 : 2;
      ctx.stroke();
      ctx.restore();
    }
    // Severity indicator dot
    if (!dim && !n.isConnected && n.severity && n.severity !== 'info') {
      const dotR = Math.max(4, Math.min(6, n.r * 0.15));
      const dotX = n.x + n.r * 0.7;
      const dotY = n.y - n.r * 0.7;
      ctx.beginPath(); ctx.arc(dotX, dotY, dotR, 0, Math.PI * 2);
      ctx.fillStyle = sevColors[n.severity] || sevColors.low;
      ctx.fill();
      ctx.strokeStyle = '#161b22'; ctx.lineWidth = 1.5; ctx.stroke();
    }
    // Watched file indicator (eye icon as triangle marker)
    if (!dim && n.watched) {
      const s = Math.max(5, Math.min(8, n.r * 0.2));
      const ix = n.x - n.r * 0.7;
      const iy = n.y - n.r * 0.7;
      const watchColor = '#d29922';
      // Draw diamond shape
      ctx.beginPath();
      ctx.moveTo(ix, iy - s);
      ctx.lineTo(ix + s, iy);
      ctx.lineTo(ix, iy + s);
      ctx.lineTo(ix - s, iy);
      ctx.closePath();
      ctx.fillStyle = watchColor; ctx.fill();
      ctx.strokeStyle = '#161b22'; ctx.lineWidth = 1.5; ctx.stroke();
    }
    // Label: node name + folder subtitle
    const fontSize = n.isConnected ? Math.max(8, Math.min(11, n.r * 0.5)) : Math.max(9, Math.min(13, n.r * 0.5));
    const hasFolder = n.folder && n.folder.length > 0;
    const labelY = hasFolder ? n.y - fontSize * 0.3 : n.y;

    ctx.font = ((isHov || isSel || isPathSelected) ? 'bold ' : '') + fontSize + 'px -apple-system, sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillStyle = dim ? '#8b949e30' : (n.isConnected ? '#8b949e' : (n.status === 'deleted' ? '#8b949ecc' : '#e6edf3'));
    ctx.fillText(n.displayLabel || n.id, n.x, labelY);
    if (n.status === 'deleted' && !n.isConnected) {
      const textW = ctx.measureText(n.displayLabel || n.id).width;
      ctx.beginPath();
      ctx.moveTo(n.x - textW / 2, labelY); ctx.lineTo(n.x + textW / 2, labelY);
      ctx.strokeStyle = dim ? '#f8514925' : '#f8514990';
      ctx.lineWidth = 1.5; ctx.stroke();
    }

    if (hasFolder) {
      const subSize = Math.max(7, fontSize * 0.7);
      ctx.font = subSize + 'px -apple-system, sans-serif';
      ctx.fillStyle = dim ? '#8b949e15' : (n.isConnected ? '#48405880' : '#ffffffaa');
      ctx.fillText(n.folder, n.x, labelY + fontSize * 0.9);
    }

    // +/- stats below the circle
    if (!dim && !n.isConnected && n.r > 16) {
      const iy = n.y + n.r + 10;
      ctx.font = '9px -apple-system, sans-serif'; ctx.textAlign = 'center';
      ctx.fillStyle = '#3fb950aa'; ctx.fillText('+' + n.add, n.x - (n.del > 0 ? 10 : 0), iy);
      if (n.del > 0) { ctx.fillStyle = '#f85149aa'; ctx.fillText('-' + n.del, n.x + 10, iy); }
    }
  }

  ctx.restore();
  requestAnimationFrame(draw);
}
draw();
