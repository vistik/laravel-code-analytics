// ── Pathfinding ───────────────────────────────────────────────────────────────
function buildAdjacency() {
  var adj = {};
  for (var i = 0; i < nodes.length; i++) { if (isVisible(nodes[i])) adj[nodes[i].id] = []; }
  for (var i = 0; i < links.length; i++) {
    var l = links[i];
    if (!isLinkVisible(l)) continue;
    if (adj[l.source.id]) adj[l.source.id].push({ nodeId: l.target.id, link: l });
    if (adj[l.target.id]) adj[l.target.id].push({ nodeId: l.source.id, link: l });
  }
  return adj;
}

function computePathfinding() {
  var selected = Array.from(pathfindNodes);
  pathResult = { nodes: new Set(), edges: new Set() };
  if (selected.length < 2) { updatePathfindingUI(0); return; }
  var adj = buildAdjacency();
  var totalPaths = 0;
  for (var i = 0; i < selected.length; i++) {
    for (var j = i + 1; j < selected.length; j++) {
      totalPaths += findPaths(adj, selected[i], selected[j], pathResult, 10);
    }
  }
  for (var k = 0; k < selected.length; k++) pathResult.nodes.add(selected[k]);
  updatePathfindingUI(totalPaths);
}

function findPaths(adj, startId, endId, result, maxDepth) {
  var count = 0, maxPaths = 100;
  function dfs(current, visited, edges, depth) {
    if (count >= maxPaths || depth > maxDepth) return;
    if (current === endId) {
      count++;
      visited.forEach(function(nId) { result.nodes.add(nId); });
      for (var i = 0; i < edges.length; i++) result.edges.add(edges[i]);
      return;
    }
    var neighbors = adj[current] || [];
    for (var i = 0; i < neighbors.length; i++) {
      var nb = neighbors[i];
      if (visited.has(nb.nodeId)) continue;
      visited.add(nb.nodeId);
      edges.push(nb.link);
      dfs(nb.nodeId, visited, edges, depth + 1);
      edges.pop();
      visited.delete(nb.nodeId);
    }
  }
  var visited = new Set([startId]);
  dfs(startId, visited, [], 1);
  return count;
}

function updatePathfindingUI(totalPaths) {
  var bar = document.getElementById('pathfindBar');
  var info = document.getElementById('pathfindInfo');
  if (pathfindNodes.size === 0) { bar.classList.remove('active'); return; }
  bar.classList.add('active');
  if (pathfindNodes.size === 1) {
    info.textContent = '1 node selected \u2014 shift+click another to find paths';
  } else {
    var p = totalPaths || 0;
    var intermediate = pathResult.nodes.size - pathfindNodes.size;
    info.textContent = pathfindNodes.size + ' nodes selected \u00b7 ' +
      (p > 0 ? p + ' path' + (p > 1 ? 's' : '') + ' found \u00b7 ' + intermediate + ' intermediate node' + (intermediate !== 1 ? 's' : '') : 'no paths found');
  }
}

function clearPathfinding() {
  pathfindNodes.clear();
  pathResult = { nodes: new Set(), edges: new Set() };
  updatePathfindingUI(0);
}

// Auto-reveal a connected/bridge node if it's currently hidden, then open its panel.
function autoRevealAndOpen(nd) {
  if (nd.isConnected && !isVisible(nd)) {
    if (bridgeNodeIds.has(nd.id)) {
      showBridges = true;
      var tb = document.getElementById('toggleBridges');
      if (tb) tb.checked = true;
    } else {
      hideConnected = false;
      var tc = document.getElementById('toggleConnected');
      if (tc) tc.checked = true;
    }
    clearHidden();
    broadcastFilterState();
  }
  openPanel(nd);
}

// Handle dep-item clicks via event delegation (avoids inline onclick escaping issues)
document.getElementById('panel-body').addEventListener('click', function(e) {
  var item = e.target.closest('.dep-item');
  if (item && item.dataset.nodeId) {
    var target = nodeMap[item.dataset.nodeId];
    if (target) autoRevealAndOpen(target);
  }
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closePanel(); clearPathfinding(); } });
