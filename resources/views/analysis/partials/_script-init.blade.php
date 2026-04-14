const PR_URL = '{{ $prUrl }}';
const REPO = '{{ $repo }}';
const HEAD_COMMIT = '{{ $headCommit }}';

const groupColor = {
  model: '#3fb950', core: '#f0883e', db: '#8957e5', nova: '#d2a8ff',
  action: '#d29922', http: '#d29922', console: '#d29922', provider: '#d29922', test: '#58a6ff',
  job: '#e3b341', event: '#f778ba', service: '#79c0ff', view: '#7ee787',
  frontend: '#ff7b72', config: '#ffa657', route: '#ffa657', other: '#8b949e',
  // method-graph visibility groups
  vis_public: '#79c0ff', vis_protected: '#e3b341', vis_private: '#8957e5', vis_external: '#484f58',
};
const groupLabel = {
  model: 'Model', core: 'Core', db: 'Database', nova: 'Nova Admin',
  action: 'Action', http: 'HTTP', console: 'Console', provider: 'Provider', test: 'Test',
  job: 'Job', event: 'Event', service: 'Service', view: 'View',
  frontend: 'Frontend', config: 'Config', route: 'Route', other: 'Other',
  vis_public: 'Public', vis_protected: 'Protected', vis_private: 'Private', vis_external: 'External',
};

const filesData = {!! $nodesJson !!};
const edgesData = {!! $edgesJson !!};
const fileDiffs = {!! $diffsJson !!};
const parsedDiffs = {!! $parsedDiffsJson !!};
const fileContents = {!! $fileContentsJson !!};
const analysisData = {!! $analysisJson !!};
const metricsData = {!! $metricsJson !!};
const methodThresholds = {!! $methodThresholdsJson !!};
{!! $severityDataJs !!}

// ── Canvas setup ──────────────────────────────────────────────────────────────
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');
const tooltip = document.getElementById('tooltip');
const panel = document.getElementById('panel');
let W, H;
function resize() { W = canvas.width = window.innerWidth; H = canvas.height = window.innerHeight; }
resize();
window.addEventListener('resize', resize);

// ── Build nodes ───────────────────────────────────────────────────────────────
const nodeMap = {};
const nodes = filesData.map((f, i) => {
  const total = f.add + f.del;
  const r = f.isConnected ? 12 : Math.max(14, Math.min(44, 8 + Math.sqrt(total) * 3));
  const node = {
    ...f,
    x: W/2, y: H/2, vx: 0, vy: 0, r,
    color: f.isConnected ? '#484f58' : (f.domainColor || groupColor[f.group] || '#8b949e'),
    pinned: false,
  };
  nodeMap[f.id] = node;
  return node;
});

const links = edgesData.map(([s, t, type, line]) => ({ source: nodeMap[s], target: nodeMap[t], depType: type || 'use', callLine: line || null })).filter(l => l.source && l.target);

// Pre-compute bridge nodes: connected (non-diff) nodes referenced by ≥2 diff nodes.
// These are the "connecting files" that explain why two diff files are related.
var bridgeNodeIds = (function() {
  var refs = {};
  links.forEach(function(l) {
    var src = l.source, tgt = l.target;
    if (tgt && tgt.isConnected && src && !src.isConnected) {
      if (!refs[tgt.id]) refs[tgt.id] = new Set();
      refs[tgt.id].add(src.id);
    }
    if (src && src.isConnected && tgt && !tgt.isConnected) {
      if (!refs[src.id]) refs[src.id] = new Set();
      refs[src.id].add(tgt.id);
    }
  });
  var ids = new Set();
  Object.keys(refs).forEach(function(id) { if (refs[id].size >= 2) ids.add(id); });
  return ids;
})();
var bridgeCountEl = document.getElementById('bridgeCount');
if (bridgeCountEl) bridgeCountEl.textContent = '(' + bridgeNodeIds.size + ')';
var bridgesRow = document.getElementById('bridgesToggleRow');
if (bridgesRow) bridgesRow.style.display = bridgeNodeIds.size > 0 ? '' : 'none';

// Navigation indices pre-computed server-side by GraphIndexBuilder.
// callersIndex entries: {nodeId, line}  (resolve full node via nodeMap[entry.nodeId])
// implementorsIndex entries: {nodeId}   (resolve full node via nodeMap[entry.nodeId])
// implementeeIndex values: string[]     (interface node ids)
const graphIndex = {!! $graphIndexJson !!};
var methodNameIndex   = graphIndex.methodNameIndex;
var classNameIndex    = graphIndex.classNameIndex;
var callersIndex      = graphIndex.callersIndex;
var implementorsIndex = graphIndex.implementorsIndex;
var implementeeIndex  = graphIndex.implementeeIndex;

// ── Circular dependency stats ─────────────────────────────────────────────────
const cycleGroupCount = new Set(nodes.filter(n => n.cycleId != null).map(n => n.cycleId)).size;

function hexAlpha(hex, alpha) {
  var r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
  return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
}
if (cycleGroupCount > 0) {
  var cycleEl = document.getElementById('cycleCount');
  if (cycleEl) cycleEl.textContent = '\u00b7 ' + cycleGroupCount + ' circular dep' + (cycleGroupCount > 1 ? 's' : '') + ' detected';
}

// ── Visibility toggles ────────────────────────────────────────────────────────
let selectedNode = null;
let hoveredNode = null;
var externalHighlightNodes = new Set(); // set by parent frame on cycle-panel hover
var _fd = {!! $filterDefaultsJson !!};
function _toHiddenSet(arr) { var h = {}; arr.forEach(function(v) { h[v] = true; }); return h; }
var hideConnected = _fd.hide_connected;
var showBridges = false;
var hiddenExts = _toHiddenSet(_fd.hidden_extensions);
var hiddenDomains = _toHiddenSet(_fd.hidden_domains);
var hiddenSeverities = _toHiddenSet(_fd.hidden_severities);
var hiddenChangeTypes = _toHiddenSet(_fd.hidden_change_types);
var hiddenKinds = _toHiddenSet(_fd.hidden_kinds || []);
var reviewedNodes = new Set();
var hideReviewed = _fd.hide_reviewed;
var pathfindNodes = new Set();
var pathResult = { nodes: new Set(), edges: new Set() };

function notifyParentVisibility() {
  if (window.parent !== window) {
    var ids = nodes.filter(function(n) { return isVisible(n); }).map(function(n) { return n.id; });
    window.parent.postMessage({ type: 'visibleNodesChanged', ids: ids }, '*');
  }
}

function broadcastFilterState() {
  if (window.parent === window) return;
  window.parent.postMessage({
    type: 'filterStateChanged',
    state: {
      hideConnected: hideConnected,
      showBridges: showBridges,
      hiddenExts: hiddenExts,
      hiddenDomains: hiddenDomains,
      hiddenSeverities: hiddenSeverities,
      hiddenChangeTypes: hiddenChangeTypes,
      hideReviewed: hideReviewed,
    }
  }, '*');
}

function clearHidden() {
  if (selectedNode && !isVisible(selectedNode)) closePanel();
  if (hoveredNode && !isVisible(hoveredNode)) { hoveredNode = null; tooltip.style.display = 'none'; }
  nodes.forEach(function(n) { if (!isVisible(n)) { n.pinned = false; pathfindNodes.delete(n.id); } });
  if (pathfindNodes.size > 0) computePathfinding();
  else { pathResult = { nodes: new Set(), edges: new Set() }; updatePathfindingUI(0); }
  if (typeof recomputeLayout === 'function') recomputeLayout();
  notifyParentVisibility();
}

document.getElementById('toggleConnected').addEventListener('change', function() {
  hideConnected = !this.checked;
  clearHidden();
  broadcastFilterState();
});

document.getElementById('toggleBridges').addEventListener('change', function() {
  showBridges = this.checked;
  clearHidden();
  broadcastFilterState();
});

document.getElementById('toggleReviewed').addEventListener('change', function() {
  hideReviewed = !this.checked;
  clearHidden();
  if (window.parent !== window) window.parent.postMessage({ type: 'showReviewedChanged', show: this.checked }, '*');
  broadcastFilterState();
});

function updateReviewedCount() {
  var total = nodes.filter(function(n) { return !n.isConnected; }).length;
  var count = 0;
  reviewedNodes.forEach(function(id) { var n = nodeMap[id]; if (n && !n.isConnected) count++; });
  document.getElementById('reviewedToggleCount').textContent = '(' + count + ')';
  document.getElementById('reviewedCount').textContent = count > 0 ? count + '/' + total + ' reviewed' : '';
}

function markReviewed(n) {
  reviewedNodes.add(n.id);
  updateReviewedCount();
  clearHidden();
  if (window.parent !== window) window.parent.postMessage({ type: 'fileReviewed', nodeId: n.id }, '*');
  closePanel();
}

function unmarkReviewed(n) {
  reviewedNodes.delete(n.id);
  updateReviewedCount();
  clearHidden();
  if (window.parent !== window) window.parent.postMessage({ type: 'fileUnreviewed', nodeId: n.id }, '*');
}

function markReviewedAndGoNext(n) {
  reviewedNodes.add(n.id);
  updateReviewedCount();
  clearHidden();
  if (window.parent !== window) window.parent.postMessage({ type: 'fileReviewedOpenNext', nodeId: n.id }, '*');
}

document.querySelectorAll('.ext-toggle').forEach(function(cb) {
  cb.addEventListener('change', function() {
    var ext = this.dataset.ext;
    if (this.checked) { delete hiddenExts[ext]; }
    else { hiddenExts[ext] = true; }
    clearHidden();
    broadcastFilterState();
  });
});

document.querySelectorAll('.domain-toggle').forEach(function(cb) {
  cb.addEventListener('change', function() {
    var domain = this.dataset.domain;
    if (this.checked) { delete hiddenDomains[domain]; }
    else { hiddenDomains[domain] = true; }
    clearHidden();
    broadcastFilterState();
  });
});

document.querySelectorAll('.change-type-toggle').forEach(function(cb) {
  cb.addEventListener('change', function() {
    var changeType = this.dataset.changeType;
    if (this.checked) { delete hiddenChangeTypes[changeType]; }
    else { hiddenChangeTypes[changeType] = true; }
    clearHidden();
    if (window.parent !== window) window.parent.postMessage({ type: 'changeTypeFilterChanged', hiddenChangeTypes: hiddenChangeTypes }, '*');
    broadcastFilterState();
  });
});

document.querySelectorAll('.severity-toggle').forEach(function(cb) {
  cb.addEventListener('change', function() {
    var severity = this.dataset.severity;
    if (this.checked) { delete hiddenSeverities[severity]; }
    else { hiddenSeverities[severity] = true; }
    clearHidden();
    broadcastFilterState();
  });
});

document.querySelectorAll('.kind-toggle').forEach(function(cb) {
  cb.addEventListener('change', function() {
    var kind = this.dataset.kind;
    if (this.checked) { delete hiddenKinds[kind]; }
    else { hiddenKinds[kind] = true; }
    clearHidden();
    broadcastFilterState();
  });
});

notifyParentVisibility();

function isSeverityFiltered(n) {
  if (!n.severity) return false;
  return !!hiddenSeverities[n.severity];
}
function isChangeTypeFiltered(n) {
  if (n.isConnected) return false;
  var changeType = (n.status === 'renamed') ? 'modified' : (n.status || 'modified');
  return !!hiddenChangeTypes[changeType];
}
function isConnectedVisible(n) {
  if (!n.isConnected) return true;
  if (!hideConnected) return true;                        // "Dependencies" on
  if (showBridges && bridgeNodeIds.has(n.id)) return true; // bridge visible via "Shared dependencies"
  return false;
}
function isKindFiltered(n) { return n.kind ? !!hiddenKinds[n.kind] : false; }
function isVisible(n) { return !hiddenExts[n.ext] && !hiddenDomains[n.domain || '(root)'] && !isChangeTypeFiltered(n) && isConnectedVisible(n) && !(hideReviewed && reviewedNodes.has(n.id)) && !isSeverityFiltered(n) && !isKindFiltered(n); }
function isLinkVisible(l) { return isVisible(l.source) && isVisible(l.target); }

{!! $simulationJs !!}

{!! $layoutSetupJs !!}

// ── Pan & Zoom ────────────────────────────────────────────────────────────────
let panX = 0, panY = 0, zoom = 1;
let isPanning = false, panStartX, panStartY;

canvas.addEventListener('wheel', e => {
  e.preventDefault();
  const oldZoom = zoom;
  zoom *= e.deltaY < 0 ? 1.08 : 0.93;
  zoom = Math.max(0.2, Math.min(4, zoom));
  const mx = e.clientX, my = e.clientY;
  panX = mx - (mx - panX) * (zoom / oldZoom);
  panY = my - (my - panY) * (zoom / oldZoom);
}, { passive: false });

function screenToWorld(sx, sy) { return [(sx - panX) / zoom, (sy - panY) / zoom]; }
