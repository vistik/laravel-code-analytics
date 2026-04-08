<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PR #{{ $prNumber }} — {{ $prTitle }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { background: #0d1117; color: #c9d1d9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; overflow: hidden; }
  #canvas { width: 100vw; height: 100vh; cursor: grab; }
  #canvas.grabbing { cursor: grabbing; }

  .tooltip {
    position: absolute; pointer-events: none; background: #161b22; border: 1px solid #30363d;
    border-radius: 8px; padding: 10px 14px; font-size: 13px; line-height: 1.5;
    box-shadow: 0 8px 24px rgba(0,0,0,.4); max-width: 380px; display: none; z-index: 10;
  }
  .tooltip .path { color: #58a6ff; font-weight: 600; }
  .tooltip .stat { color: #8b949e; }
  .tooltip .added { color: #3fb950; }
  .tooltip .removed { color: #f85149; }
  .tooltip .hint { color: #6e7681; font-size: 11px; margin-top: 4px; }

  .legend {
    position: fixed; bottom: 20px; left: 20px; background: #161b22; border: 1px solid #30363d;
    border-radius: 10px; padding: 10px 14px; font-size: 12px; line-height: 1.8; z-index: 5;
    max-height: calc(100vh - 140px); max-width: 220px; overflow-y: auto;
  }
  .legend::-webkit-scrollbar { width: 5px; }
  .legend::-webkit-scrollbar-track { background: transparent; }
  .legend::-webkit-scrollbar-thumb { background: #30363d; border-radius: 3px; }
  .legend-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
  .legend-header { display: flex; align-items: center; justify-content: space-between; }
  .legend-chevron { background: none; border: none; padding: 2px; cursor: pointer; color: #6e7681; display: flex; align-items: center; transition: color 0.15s; }
  .legend-chevron:hover { color: #c9d1d9; }
  .legend-chevron svg { transition: transform 0.2s; }
  .legend-chevron.collapsed svg { transform: rotate(-90deg); }
  .legend-body { margin-top: 6px; }

  .toggle-row { display: flex; align-items: center; gap: 8px; margin-top: 6px; padding-top: 8px; border-top: 1px solid #30363d; }
  .toggle-label { font-size: 12px; color: #8b949e; cursor: pointer; user-select: none; }
  .toggle { position: relative; width: 36px; height: 20px; flex-shrink: 0; cursor: pointer; }
  .toggle input { opacity: 0; width: 0; height: 0; }
  .toggle .slider {
    position: absolute; inset: 0; background: #30363d; border-radius: 10px; transition: background 0.2s;
  }
  .toggle .slider::before {
    content: ''; position: absolute; width: 14px; height: 14px; left: 3px; top: 3px;
    background: #c9d1d9; border-radius: 50%; transition: transform 0.2s;
  }
  .toggle input:checked + .slider { background: #58a6ff; }
  .toggle input:checked + .slider::before { transform: translateX(16px); }

  .title-bar {
    position: fixed; top: 20px; left: 20px; background: #161b22; border: 1px solid #30363d;
    border-radius: 10px; padding: 14px 20px; font-size: 14px; max-width: 560px; z-index: 5;
  }
  .title-bar h2 { font-size: 16px; color: #58a6ff; margin-bottom: 4px; }
  .title-bar h2 a { color: #58a6ff; text-decoration: none; }
  .title-bar h2 a:hover { text-decoration: underline; }
  .title-bar p { color: #8b949e; font-size: 12px; }
  .layout-switcher { display: flex; gap: 4px; margin-top: 8px; }
  .layout-btn {
    padding: 4px 12px; border-radius: 6px; font-size: 11px; font-weight: 500;
    text-decoration: none; cursor: pointer; border: 1px solid #30363d;
    color: #8b949e; background: #21262d; transition: all 0.15s;
  }
  .layout-btn:hover { background: #30363d; color: #c9d1d9; }
  .layout-btn.active { background: #58a6ff; color: #fff; border-color: #58a6ff; cursor: default; }

  #panel {
    position: fixed; top: 0; right: 0; height: 100vh;
    background: #161b22; border-left: 1px solid #30363d; z-index: 20;
    display: flex; flex-direction: column;
    box-shadow: -4px 0 24px rgba(0,0,0,.5);
    transform: translateX(100%); transition: transform 0.25s ease;
  }
  #panel.open { transform: translateX(0); }

  #panel-resize {
    position: absolute; top: 0; left: -4px; width: 8px; height: 100%;
    cursor: col-resize; z-index: 25;
  }
  #panel-resize::after {
    content: ''; position: absolute; top: 0; left: 3px; width: 2px; height: 100%;
    background: transparent; transition: background 0.15s;
  }
  #panel-resize:hover::after, #panel-resize.active::after { background: #58a6ff; }

  .panel-header {
    padding: 20px 24px 16px; border-bottom: 1px solid #30363d; flex-shrink: 0;
  }
  .panel-header .file-name { font-size: 15px; font-weight: 600; color: #e6edf3; word-break: break-all; }
  .panel-header .file-path { font-size: 12px; color: #8b949e; margin-top: 4px; word-break: break-all; }
  .panel-header .badge-row { display: flex; gap: 8px; margin-top: 10px; align-items: center; flex-wrap: wrap; }
  .badge {
    display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 500;
  }
  .badge-new { background: #0d3520; color: #3fb950; border: 1px solid #238636; }
  .badge-mod { background: #2d1c00; color: #d29922; border: 1px solid #9e6a03; }
  .badge-deleted { background: #3d1214; color: #f85149; border: 1px solid #da3633; }
  .badge-renamed { background: #1c1d4e; color: #a5b4fc; border: 1px solid #6366f1; }
  .badge-conn { background: #1c2128; color: #6e7681; border: 1px dashed #484f58; }
  .badge-add { color: #3fb950; background: none; }
  .badge-del { color: #f85149; background: none; }

  .panel-actions {
    padding: 12px 24px; border-bottom: 1px solid #30363d; flex-shrink: 0; display: flex; gap: 8px; flex-wrap: wrap;
  }
  .btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 6px;
    font-size: 13px; font-weight: 500; text-decoration: none; cursor: pointer; border: 1px solid #30363d;
    transition: background 0.15s;
  }
  .btn-primary { background: #238636; color: #fff; border-color: #238636; }
  .btn-primary:hover { background: #2ea043; }
  .btn-secondary { background: #21262d; color: #c9d1d9; }
  .btn-secondary:hover { background: #30363d; }
  .btn-review { background: #21262d; color: #8b949e; }
  .btn-review:hover { background: #1a3a2a; color: #3fb950; border-color: #238636; }
  .btn-reviewed { background: #0d3520; color: #3fb950; border-color: #238636; }
  .btn-reviewed:hover { background: #21262d; color: #8b949e; border-color: #30363d; }

  .panel-body { flex: 1; overflow-y: auto; padding: 0; }

  .deps-section { padding: 16px 24px; }
  .deps-section h4 { font-size: 12px; text-transform: uppercase; color: #8b949e; letter-spacing: 0.5px; margin-bottom: 8px; }
  .dep-item {
    display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 6px;
    font-size: 13px; color: #c9d1d9; cursor: pointer; transition: background 0.15s;
  }
  .dep-item:hover { background: #21262d; }
  .dep-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

  .analysis-row { display: flex; align-items: flex-start; gap: 8px; padding: 5px 6px; font-size: 13px; color: #c9d1d9; border-radius: 4px; margin: 0 -6px; }
  .analysis-row.clickable { cursor: pointer; }
  .analysis-row.clickable:hover { background: #21262d; }
  .analysis-row.clickable .analysis-location { color: #58a6ff; }
  .analysis-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin-top: 5px; }
  .analysis-label { flex: 1; min-width: 0; }
  .analysis-desc { display: block; font-size: 11px; color: #6e7681; line-height: 1.4; margin-top: 1px; }
  .analysis-location { color: #6e7681; font-size: 11px; margin-left: auto; white-space: nowrap; flex-shrink: 0; }
  .diff-table tr.diff-highlight td { outline: 2px solid #58a6ff; outline-offset: -2px; }
  .diff-table tr.diff-highlight td.diff-ln { background: #1f3a5f; }
  .diff-table tr.diff-highlight td:not(.diff-ln) { background: #1f3a5f; color: #e6edf3; }
  .analysis-toggle { background: none; border: 1px solid #30363d; color: #58a6ff; padding: 4px 12px; border-radius: 6px; font-size: 12px; cursor: pointer; margin-top: 8px; }
  .analysis-toggle:hover { background: #21262d; }

  .change-bar-wrap { padding: 16px 24px; border-bottom: 1px solid #30363d; }
  .change-bar { display: flex; height: 8px; border-radius: 4px; overflow: hidden; background: #21262d; }
  .change-bar .add-seg { background: #3fb950; }
  .change-bar .del-seg { background: #f85149; }
  .change-bar-label { display: flex; justify-content: space-between; font-size: 11px; color: #8b949e; margin-top: 4px; }

  .panel-close {
    position: absolute; top: 16px; right: 16px; background: none; border: none;
    color: #8b949e; font-size: 20px; cursor: pointer; line-height: 1; padding: 4px;
  }
  .panel-close:hover { color: #e6edf3; }
  .panel-back {
    position: absolute; top: 16px; right: 44px; background: none; border: none;
    color: #8b949e; font-size: 12px; cursor: pointer; padding: 4px 8px;
    display: none; align-items: center; gap: 4px;
  }
  .panel-back:hover { color: #58a6ff; }
  .panel-back.visible { display: inline-flex; }

  .panel-body::-webkit-scrollbar { width: 6px; }
  .panel-body::-webkit-scrollbar-track { background: transparent; }
  .panel-body::-webkit-scrollbar-thumb { background: #30363d; border-radius: 3px; }

  /* ── Diff viewer ── */
  .diff-section { border-top: 1px solid #30363d; }
  .diff-section h4 {
    font-size: 12px; text-transform: uppercase; color: #8b949e; letter-spacing: 0.5px;
    padding: 12px 24px 8px; position: sticky; top: 0; background: #161b22; z-index: 1;
    display: flex; align-items: center;
  }
  .diff-view-controls { margin-left: auto; display: flex; gap: 4px; }
  .diff-view-btn {
    background: none; border: 1px solid #30363d; color: #6e7681; padding: 2px 8px;
    border-radius: 4px; font-size: 11px; cursor: pointer; text-transform: none;
    letter-spacing: normal; font-family: inherit;
  }
  .diff-view-btn:hover { background: #21262d; color: #c9d1d9; }
  .diff-view-btn.active { border-color: #58a6ff; color: #58a6ff; }
  .diff-table {
    width: 100%; border-collapse: collapse; font-family: 'SF Mono', 'Fira Code', 'Fira Mono', Menlo, Consolas, monospace;
    font-size: 12px; line-height: 1.5;
  }
  .diff-table td { padding: 0 12px; white-space: pre-wrap; word-break: break-all; vertical-align: top; }
  .diff-table .diff-ln {
    position: relative; width: 1px; min-width: 40px; color: #484f58; text-align: right; padding-right: 8px;
    user-select: none; font-size: 11px;
  }
  .diff-table .diff-add { background: #0d3520; color: #aff5b4; }
  .diff-table .diff-del { background: #3d1117; color: #ffa198; }
  .diff-table .diff-hunk { background: #161b2299; color: #58a6ff; font-style: italic; padding: 4px 12px; }
  .diff-table .diff-ln-add { background: #0a2e1a; }
  .diff-table .diff-ln-del { background: #30111a; }
  .diff-table .diff-ctx { color: #c9d1d9; }
  /* Split view */
  .diff-table.split .diff-code { width: 45%; }
  .diff-table.split .diff-empty { opacity: 0.4; }
  .diff-annotation {
    position: absolute; left: 4px; top: 50%; transform: translateY(-50%);
    width: 7px; height: 7px; border-radius: 50%; cursor: pointer; z-index: 2;
  }
  .diff-annotation-tip {
    display: none; position: fixed;
    background: #1c2128; border: 1px solid #30363d; border-radius: 8px;
    padding: 8px 12px; font-size: 12px; color: #c9d1d9; line-height: 1.5;
    white-space: normal; width: 300px; z-index: 50;
    box-shadow: 0 8px 24px rgba(0,0,0,.5);
  }
  .diff-annotation-tip .tip-entry { display: flex; align-items: flex-start; gap: 6px; padding: 3px 0; }
  .diff-annotation-tip .tip-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; margin-top: 5px; }
  .diff-annotation-tip .tip-sev { font-size: 10px; text-transform: uppercase; font-weight: 600; flex-shrink: 0; margin-top: 1px; }
  .diff-annotation-tip .tip-entry + .tip-entry { border-top: 1px solid #21262d; }

  .pathfind-bar {
    position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
    background: #161b22; border: 1px solid #f78166; border-radius: 10px;
    padding: 10px 20px; font-size: 13px; z-index: 15;
    display: none; align-items: center; gap: 12px;
    box-shadow: 0 4px 16px rgba(247,129,102,0.2);
  }
  .pathfind-bar.active { display: flex; }
  .pathfind-bar .clear-btn {
    background: #21262d; border: 1px solid #30363d; color: #c9d1d9;
    border-radius: 6px; padding: 4px 12px; cursor: pointer; font-size: 12px;
  }
  .pathfind-bar .clear-btn:hover { background: #30363d; }
</style>
</head>
<body>
<canvas id="canvas"></canvas>
<div class="diff-annotation-tip" id="diffTip"></div>
<div class="tooltip" id="tooltip"></div>

<div class="title-bar">
  <h2><a href="{{ $prUrl }}" target="_blank">PR #{{ $prNumber }}</a> — {{ $prTitle }}</h2>
  <p>{{ $fileCount }} files changed &middot; +{{ $prAdditions }} &minus;{{ $prDeletions }} &middot; <span id="reviewedCount" style="color:#3fb950"></span></p>
  <div class="layout-switcher">{!! $layoutSwitcher !!}</div>
</div>

<div class="legend">
  <div class="legend-header" onclick="toggleLegend()" style="cursor:pointer">
    <span style="font-size:11px;color:#6e7681;text-transform:uppercase;letter-spacing:0.5px">Filters</span>
    <button class="legend-chevron collapsed" id="legendChevron">
      <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M2 4L6 8L10 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </button>
  </div>
  <div class="legend-body" id="legendBody" style="display:none">
  {!! $riskPanel !!}
  <span style="font-size:11px;color:#8b949e">
    <span style="border-bottom:2px dashed #3fb950">Dashed border</span> = new file &middot;
    <span style="border-bottom:2px dashed #6e7681">Gray dashed</span> = connected file<br>
    Circle size = lines changed &middot; Scroll to zoom &middot; Click for details &middot; Shift+click to find paths
  </span>
  <div class="toggle-row">
    <label class="toggle"><input type="checkbox" id="toggleReviewed"><span class="slider"></span></label>
    <label class="toggle-label" for="toggleReviewed">Show reviewed <span id="reviewedToggleCount" style="color:#484f58">(0)</span></label>
  </div>
  <div class="toggle-row">
    <label class="toggle"><input type="checkbox" id="toggleConnected"><span class="slider"></span></label>
    <label class="toggle-label" for="toggleConnected">Show connected <span style="color:#484f58">({{ $connectedCount }})</span></label>
  </div>
  <div style="margin-top:4px;padding-top:8px;border-top:1px solid #30363d">
    <span style="font-size:11px;color:#6e7681;text-transform:uppercase;letter-spacing:0.5px">Severity</span>
  </div>
  {!! $severityTogglesHtml !!}
  <div style="margin-top:4px;padding-top:8px;border-top:1px solid #30363d">
    <span style="font-size:11px;color:#6e7681;text-transform:uppercase;letter-spacing:0.5px">File types</span>
  </div>
  {!! $extTogglesHtml !!}
  <div style="margin-top:4px;padding-top:8px;border-top:1px solid #30363d">
    <span style="font-size:11px;color:#6e7681;text-transform:uppercase;letter-spacing:0.5px">Domains</span>
  </div>
  {!! $folderTogglesHtml !!}
  <div style="margin-top:4px;padding-top:8px;border-top:1px solid #30363d">
    <span style="font-size:11px;color:#6e7681;text-transform:uppercase;letter-spacing:0.5px">Changes</span>
  </div>
  <div class="toggle-row">
    <span class="legend-dot" style="background:#3fb950"></span>
    <label class="toggle"><input type="checkbox" class="change-type-toggle" data-change-type="added" checked><span class="slider"></span></label>
    <label class="toggle-label">New</label>
  </div>
  <div class="toggle-row">
    <span class="legend-dot" style="background:#d29922"></span>
    <label class="toggle"><input type="checkbox" class="change-type-toggle" data-change-type="modified" checked><span class="slider"></span></label>
    <label class="toggle-label">Modified</label>
  </div>
  <div class="toggle-row">
    <span class="legend-dot" style="background:#f85149"></span>
    <label class="toggle"><input type="checkbox" class="change-type-toggle" data-change-type="deleted" checked><span class="slider"></span></label>
    <label class="toggle-label">Deleted</label>
  </div>
  </div>
</div>

<div class="pathfind-bar" id="pathfindBar">
  <span style="color:#f78166">&#9670;</span>
  <span id="pathfindInfo" style="color:#c9d1d9"></span>
  <button class="clear-btn" onclick="clearPathfinding()">Clear</button>
</div>

<div id="panel">
  <div id="panel-resize"></div>
  <button class="panel-back" id="panelBack" onclick="if(window.parent!==window)window.parent.postMessage({type:'backToFiles'},'*')">&#8592; Files</button>
  <button class="panel-close" onclick="closePanel()">&times;</button>
  <div class="panel-header" id="panel-header"></div>
  <div class="panel-actions" id="panel-actions"></div>
  <div class="change-bar-wrap" id="panel-bar"></div>
  <div class="panel-body" id="panel-body"></div>
</div>

<script>
const PR_URL = '{{ $prUrl }}';
const REPO = '{{ $repo }}';
const HEAD_COMMIT = '{{ $headCommit }}';

const groupColor = {
  model: '#3fb950', core: '#f0883e', db: '#8957e5', nova: '#d2a8ff',
  action: '#d29922', http: '#d29922', console: '#d29922', provider: '#d29922', test: '#58a6ff',
  job: '#e3b341', event: '#f778ba', service: '#79c0ff', view: '#7ee787',
  frontend: '#ff7b72', config: '#ffa657', route: '#ffa657', other: '#8b949e',
};
const groupLabel = {
  model: 'Model', core: 'Core', db: 'Database', nova: 'Nova Admin',
  action: 'Action', http: 'HTTP', console: 'Console', provider: 'Provider', test: 'Test',
  job: 'Job', event: 'Event', service: 'Service', view: 'View',
  frontend: 'Frontend', config: 'Config', route: 'Route', other: 'Other',
};

const filesData = {!! $nodesJson !!};
const edgesData = {!! $edgesJson !!};
const fileDiffs = {!! $diffsJson !!};
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

const links = edgesData.map(([s, t, type]) => ({ source: nodeMap[s], target: nodeMap[t], depType: type || 'type_reference' })).filter(l => l.source && l.target);

// ── Visibility toggles ────────────────────────────────────────────────────────
let selectedNode = null;
let hoveredNode = null;
var hideConnected = true;
var hiddenExts = {};
var hiddenDomains = { 'tests': true };
var hiddenSeverities = {};
var hiddenChangeTypes = {};
var reviewedNodes = new Set();
var hideReviewed = true;
var pathfindNodes = new Set();
var pathResult = { nodes: new Set(), edges: new Set() };

function notifyParentVisibility() {
  if (window.parent !== window) {
    var ids = nodes.filter(function(n) { return isVisible(n); }).map(function(n) { return n.id; });
    window.parent.postMessage({ type: 'visibleNodesChanged', ids: ids }, '*');
  }
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
});

document.getElementById('toggleReviewed').addEventListener('change', function() {
  hideReviewed = !this.checked;
  clearHidden();
  if (window.parent !== window) window.parent.postMessage({ type: 'showReviewedChanged', show: this.checked }, '*');
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
}

function unmarkReviewed(n) {
  reviewedNodes.delete(n.id);
  updateReviewedCount();
  clearHidden();
  if (window.parent !== window) window.parent.postMessage({ type: 'fileUnreviewed', nodeId: n.id }, '*');
}

document.querySelectorAll('.ext-toggle').forEach(function(cb) {
  cb.addEventListener('change', function() {
    var ext = this.dataset.ext;
    if (this.checked) { delete hiddenExts[ext]; }
    else { hiddenExts[ext] = true; }
    clearHidden();
  });
});

document.querySelectorAll('.domain-toggle').forEach(function(cb) {
  cb.addEventListener('change', function() {
    var domain = this.dataset.domain;
    if (this.checked) { delete hiddenDomains[domain]; }
    else { hiddenDomains[domain] = true; }
    clearHidden();
  });
});

document.querySelectorAll('.change-type-toggle').forEach(function(cb) {
  cb.addEventListener('change', function() {
    var changeType = this.dataset.changeType;
    if (this.checked) { delete hiddenChangeTypes[changeType]; }
    else { hiddenChangeTypes[changeType] = true; }
    clearHidden();
    if (window.parent !== window) window.parent.postMessage({ type: 'changeTypeFilterChanged', hiddenChangeTypes: hiddenChangeTypes }, '*');
  });
});

document.querySelectorAll('.severity-toggle').forEach(function(cb) {
  cb.addEventListener('change', function() {
    var severity = this.dataset.severity;
    if (this.checked) { delete hiddenSeverities[severity]; }
    else { hiddenSeverities[severity] = true; }
    clearHidden();
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
function isVisible(n) { return !hiddenExts[n.ext] && !hiddenDomains[n.domain || '(root)'] && !isChangeTypeFiltered(n) && !(hideConnected && n.isConnected) && !(hideReviewed && reviewedNodes.has(n.id)) && !isSeverityFiltered(n); }
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

// ── Syntax highlighting ───────────────────────────────────────────────────────
function escapeHtml(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function highlightPHP(code) {
  var tokens = [];
  var i = 0, len = code.length;
  while (i < len) {
    // Single-line comment
    if (code[i] === '/' && code[i+1] === '/') {
      tokens.push({type:'comment', text: code.substring(i)});
      break;
    }
    // Block comment (partial on one line)
    if (code[i] === '/' && code[i+1] === '*') {
      var end = code.indexOf('*/', i + 2);
      if (end === -1) { tokens.push({type:'comment', text: code.substring(i)}); break; }
      tokens.push({type:'comment', text: code.substring(i, end + 2)});
      i = end + 2; continue;
    }
    // Strings (double/single quoted, with escape handling)
    if (code[i] === '"' || code[i] === "'") {
      var q = code[i], j = i + 1;
      while (j < len && code[j] !== q) { if (code[j] === '\\') j++; j++; }
      tokens.push({type:'string', text: code.substring(i, j + 1)});
      i = j + 1; continue;
    }
    // Variables
    if (code[i] === '$' && /[a-zA-Z_]/.test(code[i+1] || '')) {
      var j = i + 1;
      while (j < len && /[a-zA-Z0-9_]/.test(code[j])) j++;
      tokens.push({type:'variable', text: code.substring(i, j)});
      i = j; continue;
    }
    // Numbers
    if (/[0-9]/.test(code[i]) && (i === 0 || !/[a-zA-Z_]/.test(code[i-1]))) {
      var j = i;
      while (j < len && /[0-9._]/.test(code[j])) j++;
      tokens.push({type:'number', text: code.substring(i, j)});
      i = j; continue;
    }
    // Words (keywords, types, identifiers)
    if (/[a-zA-Z_]/.test(code[i])) {
      var j = i;
      while (j < len && /[a-zA-Z0-9_]/.test(code[j])) j++;
      var word = code.substring(i, j);
      var kwType = 'plain';
      if (/^(if|else|elseif|while|for|foreach|do|switch|case|break|continue|return|throw|try|catch|finally|new|class|interface|trait|enum|extends|implements|use|namespace|function|fn|match|yield|as|instanceof|static|self|parent|abstract|final|public|private|protected|readonly|const|var|global|echo|print|isset|unset|empty|array|list)$/.test(word)) kwType = 'keyword';
      else if (/^(string|int|float|bool|void|null|true|false|array|object|mixed|never|callable|iterable|self|static)$/i.test(word) && (code[j] === ' ' || code[j] === ',' || code[j] === ')' || code[j] === '|' || code[j] === '>' || j >= len)) kwType = 'type';
      tokens.push({type: kwType, text: word});
      i = j; continue;
    }
    // Operators and punctuation
    tokens.push({type:'plain', text: code[i]});
    i++;
  }
  var out = '';
  for (var t = 0; t < tokens.length; t++) {
    var tok = tokens[t], esc = escapeHtml(tok.text);
    switch(tok.type) {
      case 'comment':  out += '<span style="color:#6e7681;font-style:italic">' + esc + '</span>'; break;
      case 'string':   out += '<span style="color:#a5d6ff">' + esc + '</span>'; break;
      case 'variable': out += '<span style="color:#ffa657">' + esc + '</span>'; break;
      case 'keyword':  out += '<span style="color:#ff7b72">' + esc + '</span>'; break;
      case 'type':     out += '<span style="color:#79c0ff">' + esc + '</span>'; break;
      case 'number':   out += '<span style="color:#79c0ff">' + esc + '</span>'; break;
      default:         out += esc; break;
    }
  }
  return out;
}

// ── Diff view ─────────────────────────────────────────────────────────────────
var diffViewMode = localStorage.getItem('diffViewMode') || 'unified';
var diffAnnotationsData = [];

function parseDiffLines(rawDiff) {
  var lines = rawDiff.split('\n');
  var result = [];
  var i = 0;
  while (i < lines.length) {
    var line = lines[i];
    if (line.startsWith('@@')) {
      var hm = line.match(/@@ -(\d+)(?:,\d+)? \+(\d+)/);
      result.push({ type: 'hunk', raw: line, oldStart: hm ? parseInt(hm[1]) : 1, newStart: hm ? parseInt(hm[2]) : 1 });
      i++;
    } else if (line.startsWith('\\')) {
      i++;
    } else if (line.startsWith('-') || line.startsWith('+')) {
      var dels = [], adds = [];
      while (i < lines.length) {
        if (lines[i].startsWith('\\')) { i++; continue; }
        if (lines[i].startsWith('-')) { dels.push(lines[i].substring(1)); i++; }
        else if (lines[i].startsWith('+')) { adds.push(lines[i].substring(1)); i++; }
        else break;
      }
      result.push({ type: 'change', dels: dels, adds: adds });
    } else {
      result.push({ type: 'ctx', text: line.length > 0 ? line.substring(1) : '' });
      i++;
    }
  }
  return result;
}

function renderUnifiedDiff(parsed, isPHP) {
  var html = '';
  var oldLn = 0, newLn = 0;
  for (var r = 0; r < parsed.length; r++) {
    var row = parsed[r];
    if (row.type === 'hunk') {
      oldLn = row.oldStart; newLn = row.newStart;
      var esc = row.raw.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      html += '<tr><td class="diff-ln"></td><td class="diff-ln"></td><td class="diff-hunk">' + esc + '</td></tr>';
    } else if (row.type === 'ctx') {
      var h = isPHP ? highlightPHP(row.text) : escapeHtml(row.text);
      html += '<tr data-new-ln="' + newLn + '" data-old-ln="' + oldLn + '"><td class="diff-ln">' + oldLn + '</td><td class="diff-ln">' + newLn + '</td><td class="diff-ctx"> ' + h + '</td></tr>';
      oldLn++; newLn++;
    } else {
      for (var j = 0; j < row.dels.length; j++) {
        var h = isPHP ? highlightPHP(row.dels[j]) : escapeHtml(row.dels[j]);
        html += '<tr data-old-ln="' + oldLn + '"><td class="diff-ln diff-ln-del">' + oldLn + '</td><td class="diff-ln diff-ln-del"></td><td class="diff-del">-' + h + '</td></tr>';
        oldLn++;
      }
      for (var j = 0; j < row.adds.length; j++) {
        var h = isPHP ? highlightPHP(row.adds[j]) : escapeHtml(row.adds[j]);
        html += '<tr data-new-ln="' + newLn + '"><td class="diff-ln diff-ln-add"></td><td class="diff-ln diff-ln-add">' + newLn + '</td><td class="diff-add">+' + h + '</td></tr>';
        newLn++;
      }
    }
  }
  return html;
}

function renderSplitDiff(parsed, isPHP) {
  var html = '';
  var oldLn = 0, newLn = 0;
  for (var r = 0; r < parsed.length; r++) {
    var row = parsed[r];
    if (row.type === 'hunk') {
      oldLn = row.oldStart; newLn = row.newStart;
      var esc = row.raw.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      html += '<tr><td colspan="4" class="diff-hunk">' + esc + '</td></tr>';
    } else if (row.type === 'ctx') {
      var h = isPHP ? highlightPHP(row.text) : escapeHtml(row.text);
      html += '<tr data-new-ln="' + newLn + '" data-old-ln="' + oldLn + '">' +
        '<td class="diff-ln">' + oldLn + '</td><td class="diff-ctx diff-code"> ' + h + '</td>' +
        '<td class="diff-ln">' + newLn + '</td><td class="diff-ctx diff-code"> ' + h + '</td>' +
        '</tr>';
      oldLn++; newLn++;
    } else {
      var dels = row.dels, adds = row.adds;
      var bOld = oldLn, bNew = newLn;
      var maxLen = Math.max(dels.length, adds.length);
      for (var j = 0; j < maxLen; j++) {
        var hasDel = j < dels.length, hasAdd = j < adds.length;
        var trAttrs = (hasDel ? ' data-old-ln="' + (bOld + j) + '"' : '') + (hasAdd ? ' data-new-ln="' + (bNew + j) + '"' : '');
        html += '<tr' + trAttrs + '>';
        if (hasDel) {
          var dh = isPHP ? highlightPHP(dels[j]) : escapeHtml(dels[j]);
          html += '<td class="diff-ln diff-ln-del">' + (bOld + j) + '</td><td class="diff-del diff-code">-' + dh + '</td>';
        } else {
          html += '<td class="diff-ln diff-ln-del"></td><td class="diff-del diff-code diff-empty"></td>';
        }
        if (hasAdd) {
          var ah = isPHP ? highlightPHP(adds[j]) : escapeHtml(adds[j]);
          html += '<td class="diff-ln diff-ln-add">' + (bNew + j) + '</td><td class="diff-add diff-code">+' + ah + '</td>';
        } else {
          html += '<td class="diff-ln diff-ln-add"></td><td class="diff-add diff-code diff-empty"></td>';
        }
        html += '</tr>';
      }
      oldLn += dels.length;
      newLn += adds.length;
    }
  }
  return html;
}

function renderFullFile(fileContent, parsedDiff, isPHP) {
  // Build maps from parsedDiff: which new-file lines are added, and which deleted
  // lines appear before each new-file line.
  var addedLines = {};
  var deletedBefore = {}; // newLn -> array of deleted line texts

  var oldLn = 0, newLn = 0;
  for (var r = 0; r < parsedDiff.length; r++) {
    var row = parsedDiff[r];
    if (row.type === 'hunk') {
      oldLn = row.oldStart; newLn = row.newStart;
    } else if (row.type === 'ctx') {
      oldLn++; newLn++;
    } else if (row.type === 'change') {
      var insPoint = newLn;
      if (row.dels.length > 0) {
        if (!deletedBefore[insPoint]) deletedBefore[insPoint] = [];
        for (var d = 0; d < row.dels.length; d++) deletedBefore[insPoint].push(row.dels[d]);
        oldLn += row.dels.length;
      }
      for (var j = 0; j < row.adds.length; j++) addedLines[newLn + j] = true;
      newLn += row.adds.length;
    }
  }

  var lines = fileContent.split('\n');
  if (lines.length > 0 && lines[lines.length - 1] === '') lines.pop();

  var html = '';
  for (var i = 0; i < lines.length; i++) {
    var ln = i + 1;
    var dels = deletedBefore[ln] || [];
    for (var d = 0; d < dels.length; d++) {
      var dh = isPHP ? highlightPHP(dels[d]) : escapeHtml(dels[d]);
      html += '<tr><td class="diff-ln diff-ln-del"></td><td class="diff-del">-' + dh + '</td></tr>';
    }
    var h = isPHP ? highlightPHP(lines[i]) : escapeHtml(lines[i]);
    var isAdded = !!addedLines[ln];
    html += '<tr data-new-ln="' + ln + '">' +
      '<td class="diff-ln' + (isAdded ? ' diff-ln-add' : '') + '">' + ln + '</td>' +
      '<td class="' + (isAdded ? 'diff-add' : 'diff-ctx') + '">' + (isAdded ? '+' : ' ') + h + '</td>' +
      '</tr>';
  }
  // Deletions past end of new file (lines deleted at end)
  var endKeys = Object.keys(deletedBefore).map(Number).filter(function(k) { return k > lines.length; }).sort(function(a,b){return a-b;});
  for (var ki = 0; ki < endKeys.length; ki++) {
    var eds = deletedBefore[endKeys[ki]];
    for (var d = 0; d < eds.length; d++) {
      var dh = isPHP ? highlightPHP(eds[d]) : escapeHtml(eds[d]);
      html += '<tr><td class="diff-ln diff-ln-del"></td><td class="diff-del">-' + dh + '</td></tr>';
    }
  }
  return html;
}

function scrollToDiffRow(target) {
  if (!target) return;
  document.querySelectorAll('.diff-table tr.diff-highlight').forEach(function(r) { r.classList.remove('diff-highlight'); });
  target.classList.add('diff-highlight');
  target.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function findDiffRowByLine(line) {
  var target = document.querySelector('.diff-table tr[data-new-ln="' + line + '"]');
  if (target) return target;
  var allRows = document.querySelectorAll('.diff-table tr[data-new-ln]');
  var closest = null, closestDist = Infinity;
  allRows.forEach(function(r) {
    var ln = parseInt(r.getAttribute('data-new-ln'));
    var dist = Math.abs(ln - parseInt(line));
    if (dist < closestDist) { closestDist = dist; closest = r; }
  });
  return closest;
}

function findDiffRowByLocation(location) {
  var method = location.indexOf('::') !== -1 ? location.split('::').pop() : location;
  var rows = document.querySelectorAll('.diff-table tr');
  for (var i = 0; i < rows.length; i++) {
    var cell = rows[i].querySelector('td:last-child');
    if (cell && cell.textContent.indexOf('function ' + method) !== -1) return rows[i];
  }
  for (var i = 0; i < rows.length; i++) {
    var cell = rows[i].querySelector('td:last-child');
    if (cell && cell.textContent.indexOf(method) !== -1) return rows[i];
  }
  return null;
}

function placeAnnotationDots() {
  document.querySelectorAll('.diff-annotation').forEach(function(d) { d.remove(); });
  var byRow = new Map();
  diffAnnotationsData.forEach(function(a) {
    var tr = a.line ? findDiffRowByLine(a.line) : findDiffRowByLocation(a.location);
    if (!tr) return;
    if (!byRow.has(tr)) byRow.set(tr, []);
    byRow.get(tr).push(a);
  });
  var diffTip = document.getElementById('diffTip');
  byRow.forEach(function(anns, tr) {
    anns.sort(function(a, b) { return (sevOrder[a.severity] || 4) - (sevOrder[b.severity] || 4); });
    var color = sevColors[anns[0].severity] || sevColors.info;
    var tooltipHtml = anns.map(function(a) {
      return '<div class="tip-entry">' +
        '<span class="tip-dot" style="background:' + (sevColors[a.severity] || sevColors.info) + '"></span>' +
        '<span class="tip-sev" style="color:' + (sevColors[a.severity] || sevColors.info) + '">' + (sevLabels[a.severity] || 'Info') + '</span>' +
        '<span>' + a.description.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span></div>';
    }).join('');
    var lnCell = tr.querySelector('.diff-ln');
    if (!lnCell) return;
    var dot = document.createElement('span');
    dot.className = 'diff-annotation';
    dot.style.background = color;
    lnCell.appendChild(dot);
    dot.addEventListener('mouseenter', function() {
      diffTip.innerHTML = tooltipHtml;
      diffTip.style.display = 'block';
      var rect = dot.getBoundingClientRect();
      diffTip.style.left = (rect.right + 8) + 'px';
      diffTip.style.top = (rect.top - 4) + 'px';
      var tipRect = diffTip.getBoundingClientRect();
      if (tipRect.bottom > window.innerHeight - 8) {
        diffTip.style.top = (window.innerHeight - 8 - tipRect.height) + 'px';
      }
    });
    dot.addEventListener('mouseleave', function() { diffTip.style.display = 'none'; });
  });
}

// ── Panel ─────────────────────────────────────────────────────────────────────
function openPanel(n) {
  selectedNode = n;
  const ghFileUrl = PR_URL + '/changes#diff-' + n.hash;
  const total = n.add + n.del;
  const addPct = total > 0 ? (n.add / total * 100) : 0;
  const delPct = total > 0 ? (n.del / total * 100) : 0;

  var statusBadges = { added: ['badge-new', 'New file'], deleted: ['badge-deleted', 'Deleted'], renamed: ['badge-renamed', 'Renamed'], modified: ['badge-mod', 'Modified'] };
  var sb = n.isConnected ? ['badge-conn', 'Connected'] : (statusBadges[n.status] || statusBadges.modified);
  var badgeClass = sb[0];
  var badgeText = sb[1];

  document.getElementById('panel-header').innerHTML =
    '<div class="file-name"' + (n.status === 'deleted' ? ' style="text-decoration:line-through;text-decoration-color:#f85149;color:#8b949e"' : '') + '>' + n.id + '</div>' +
    '<div class="file-path">' + n.path + '</div>' +
    '<div class="badge-row">' +
      '<span class="badge ' + badgeClass + '">' + badgeText + '</span>' +
      (reviewedNodes.has(n.id) ? '<span class="badge" style="background:#0d3520;color:#3fb950;border:1px solid #238636">&#10003; Reviewed</span>' : '') +
      (n.isConnected ? '' : '<span class="badge badge-add">+' + n.add + '</span><span class="badge badge-del">&minus;' + n.del + '</span>') +
      '<span class="badge" style="color:' + (n.isConnected ? (groupColor[n.group] || '#8b949e') : n.color) + ';background:none">' + (groupLabel[n.group] || n.group) + '</span>' +
      (n.veryHighCount > 0 ? '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#8b949e"><span style="width:8px;height:8px;border-radius:50%;background:' + sevColors.very_high + ';flex-shrink:0;display:inline-block"></span>' + n.veryHighCount + '</span>' : '') +
      (n.highCount > 0 ? '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#8b949e"><span style="width:8px;height:8px;border-radius:50%;background:' + sevColors.high + ';flex-shrink:0;display:inline-block"></span>' + n.highCount + '</span>' : '') +
      (n.mediumCount > 0 ? '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#8b949e"><span style="width:8px;height:8px;border-radius:50%;background:' + sevColors.medium + ';flex-shrink:0;display:inline-block"></span>' + n.mediumCount + '</span>' : '') +
      (n.lowCount > 0 ? '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#8b949e"><span style="width:8px;height:8px;border-radius:50%;background:' + sevColors.low + ';flex-shrink:0;display:inline-block"></span>' + n.lowCount + '</span>' : '') +
      (n.infoCount > 0 ? '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#8b949e"><span style="width:8px;height:8px;border-radius:50%;background:' + sevColors.info + ';flex-shrink:0;display:inline-block"></span>' + n.infoCount + '</span>' : '') +
      (n.watched ? '<span class="badge" style="background:#d29922;color:#000">&#9670; Watched</span>' : '') +
    '</div>';

  var isReviewed = reviewedNodes.has(n.id);
  var reviewBtn = '<button class="btn ' + (isReviewed ? 'btn-reviewed' : 'btn-review') + '" id="reviewBtn">' +
    (isReviewed ? '&#10003; Reviewed' : '&#9744; Mark as reviewed') + '</button>';

  if (n.isConnected) {
    document.getElementById('panel-actions').innerHTML =
      '<a class="btn btn-secondary" href="https://github.com/' + REPO + '/blob/' + HEAD_COMMIT + '/' + n.path + '" target="_blank" rel="noopener">' +
        '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>' +
        'View file on GitHub' +
      '</a>' + reviewBtn;
  } else {
    document.getElementById('panel-actions').innerHTML =
      '<a class="btn btn-primary" href="' + ghFileUrl + '" target="_blank" rel="noopener">' +
        '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>' +
        'View diff on GitHub' +
      '</a>' +
      reviewBtn;
  }

  document.getElementById('reviewBtn').addEventListener('click', function() {
    if (reviewedNodes.has(n.id)) { unmarkReviewed(n); }
    else { markReviewed(n); }
    openPanel(n);
  });

  if (n.isConnected) {
    document.getElementById('panel-bar').innerHTML =
      '<div style="font-size:12px;color:#6e7681;padding:4px 0">This file was not changed in the PR</div>';
  } else {
    document.getElementById('panel-bar').innerHTML =
      '<div class="change-bar"><div class="add-seg" style="width:' + addPct + '%"></div><div class="del-seg" style="width:' + delPct + '%"></div></div>' +
      '<div class="change-bar-label"><span>' + n.add + ' additions</span><span>' + n.del + ' deletions</span></div>';
  }

  const dependsOnLinks = links.filter(l => isLinkVisible(l) && l.source === n);
  const usedByLinks = links.filter(l => isLinkVisible(l) && l.target === n);
  const dependsOn = dependsOnLinks.map(l => l.target);
  const usedBy = usedByLinks.map(l => l.source);
  let bodyHtml = '';

  // Code Metrics section (PHP via phpmetrics, JS/TS via complexity-report)
  var m = metricsData[n.path];
  if (m) {
    function metricColor(val, warn, bad) { return val >= bad ? '#f85149' : val >= warn ? '#d29922' : '#3fb950'; }
    function metricCard(label, val, unit, warnThresh, badThresh, lowBad, beforeVal) {
      if (val == null) return '';
      var numVal = parseFloat(val);
      var display = unit === '%' ? val + unit : val;
      var color = lowBad ? metricColor(warnThresh - numVal + warnThresh, warnThresh, badThresh) : metricColor(numVal, warnThresh, badThresh);
      if (unit === '%') { color = numVal < 65 ? '#f85149' : numVal < 85 ? '#d29922' : '#3fb950'; }
      var deltaHtml = '';
      if (beforeVal != null) {
        var numBefore = parseFloat(beforeVal);
        var delta = numVal - numBefore;
        var improved = lowBad ? delta > 0 : delta < 0;
        var deltaColor = Math.abs(delta) >= 0.0005 ? (improved ? '#3fb950' : '#f85149') : '#484f58';
        var arrow = Math.abs(delta) >= 0.0005 ? (delta > 0 ? '\u2191' : '\u2193') : '\u2192';
        var absDelta = Math.abs(delta);
        var pct = numBefore !== 0 ? Math.round(Math.abs(delta / numBefore) * 100) : null;
        var deltaDisplay = Math.abs(delta) >= 0.0005
          ? arrow + (absDelta < 1 ? absDelta.toFixed(2).replace(/\.?0+$/, '') : Math.round(absDelta)) + (pct != null ? ' (' + pct + '%)' : '')
          : arrow;
        deltaHtml = '<span style="font-size:10px;color:' + deltaColor + '">' + deltaDisplay + '</span>';
      }
      return '<div style="background:#21262d;border-radius:6px;padding:8px 10px">' +
        '<div style="font-size:10px;color:#6e7681;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:4px">' + label + '</div>' +
        '<div style="font-size:20px;font-weight:700;color:' + color + ';line-height:1;display:flex;align-items:baseline;gap:4px">' + display + deltaHtml + '</div>' +
        '</div>';
    }
    var b = m.before || {};
    var metricsLabel = /\.(js|ts|jsx|tsx|vue|mjs|cjs)$/.test(n.path) ? 'JS Metrics' : 'PHP Metrics';
    bodyHtml += '<div class="deps-section"><h4>' + metricsLabel + '</h4>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px">' +
      metricCard('Cyclomatic Complexity', m.cc, '', 10, 20, false, b.cc) +
      metricCard('Maintainability', m.mi != null ? Math.round(m.mi) : null, '%', 85, 65, true, b.mi != null ? Math.round(b.mi) : null) +
      metricCard('Est. Bugs', m.bugs != null ? m.bugs.toFixed(3) : null, '', 0.1, 0.5, false, b.bugs) +
      metricCard('Coupling (Ce)', m.coupling, '', 10, 20, false, b.coupling) +
      (m.lloc != null ? metricCard('Lines of Code', m.lloc, '', 200, 500, false, b.lloc) : '') +
      (m.methods != null ? metricCard('Methods', m.methods, '', 10, 20, false, b.methods) : '') +
      '</div>';
    if (m.method_metrics && m.method_metrics.length > 0) {
      // Build set of added new-line numbers so we can mark each method as new/modified
      var addedNewLines = new Set();
      var rawDiffForMethods = fileDiffs[n.path];
      if (rawDiffForMethods) {
        var tmpNl = 0;
        rawDiffForMethods.split('\n').forEach(function(dl) {
          if (dl.startsWith('@@@@')) {
            var dhm = dl.match(/@@@@ -\d+(?:,\d+)? \+(\d+)/);
            if (dhm) tmpNl = parseInt(dhm[1]);
          } else if (dl.startsWith('+')) { addedNewLines.add(tmpNl++); }
          else if (dl.startsWith('-')) { /* deleted – no new line */ }
          else if (!dl.startsWith('\\')) { tmpNl++; }
        });
      }
      function methodDiffStatus(mth) {
        if (!mth.line) return null;
        if (addedNewLines.has(mth.line)) return 'new';
        for (var l = mth.line + 1; l <= mth.line + mth.lloc - 1; l++) {
          if (addedNewLines.has(l)) return 'modified';
        }
        return null;
      }

      var methodsSorted = m.method_metrics.slice().sort(function(a, b) { return b.cc - a.cc; });
      bodyHtml += '<div style="margin-top:10px">' +
        '<div style="font-size:10px;color:#6e7681;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:6px">Methods by Complexity</div>' +
        '<table style="width:100%;border-collapse:collapse;font-size:11px">' +
        '<thead><tr>' +
        '<th style="text-align:left;color:#6e7681;font-weight:500;padding:2px 8px 4px 0">Method</th>' +
        '<th style="text-align:right;color:#6e7681;font-weight:500;padding:2px 8px 4px 0">CC</th>' +
        '<th style="text-align:right;color:#6e7681;font-weight:500;padding:2px 8px 4px 0">Lines</th>' +
        '<th style="text-align:right;color:#6e7681;font-weight:500;padding:2px 0 4px 0">Params</th>' +
        '</tr></thead><tbody>';
      for (var mi2 = 0; mi2 < methodsSorted.length; mi2++) {
        var mth = methodsSorted[mi2];
        var ccColor = mth.cc > 10 ? '#f85149' : mth.cc > 5 ? '#d29922' : '#3fb950';
        var hasLine = mth.line ? ' data-method-line="' + mth.line + '"' : '';
        var status = methodDiffStatus(mth);
        var statusBadge = status === 'new'
          ? '<span style="color:#3fb950;font-size:9px;font-weight:500;margin-left:5px">new</span>'
          : status === 'modified'
          ? '<span style="color:#d29922;font-size:9px;font-weight:500;margin-left:5px">mod</span>'
          : '';
        bodyHtml += '<tr' + hasLine + (mth.line ? ' style="cursor:pointer"' : '') + '>' +
          '<td style="padding:2px 8px 2px 0;color:#c9d1d9;white-space:nowrap;max-width:160px;overflow:hidden;text-overflow:ellipsis" title="' + mth.name + '">' + mth.name + statusBadge + '</td>' +
          '<td style="padding:2px 8px 2px 0;text-align:right;color:' + ccColor + ';font-weight:600">' + mth.cc + '</td>' +
          '<td style="padding:2px 8px 2px 0;text-align:right;color:#8b949e">' + mth.lloc + '</td>' +
          '<td style="padding:2px 0;text-align:right;color:#8b949e">' + mth.params + '</td>' +
          '</tr>';
      }
      bodyHtml += '</tbody></table></div>';
    }
    bodyHtml += '</div>';
  }

  // Code Analysis section
  var analysisEntries = analysisData[n.path];
  if (analysisEntries && analysisEntries.length > 0) {
    var sorted = analysisEntries.slice().sort(function(a, b) { return (sevOrder[a.severity] ?? 4) - (sevOrder[b.severity] ?? 4); });
    var showCount = sorted.length > 10 ? 10 : sorted.length;
    bodyHtml += '<div class="deps-section"><h4>Code Analysis (' + sorted.length + ' changes)</h4>';
    for (var ai = 0; ai < sorted.length; ai++) {
      var entry = sorted[ai];
      var dotColor = sevColors[entry.severity] || sevColors.info;
      var locText = entry.location || (entry.line ? 'line ' + entry.line : '');
      var hiddenStyle = ai >= 10 ? ' style="display:none"' : '';
      var lineAttr = entry.line ? ' data-line="' + entry.line + '"' : '';
      var locAttr = entry.location ? ' data-location="' + (entry.location || '').replace(/"/g, '&quot;') + '"' : '';
      var sevAttr = ' data-severity="' + (entry.severity || 'info') + '"';
      var label = (entry.shortDescription || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      var desc = (entry.description || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      bodyHtml += '<div class="analysis-row" data-analysis-idx="' + ai + '"' + lineAttr + locAttr + sevAttr + hiddenStyle + '>' +
        '<span class="analysis-dot" style="background:' + dotColor + '"></span>' +
        '<span class="analysis-label">' + label + (desc ? '<span class="analysis-desc">' + desc + '</span>' : '') + '</span>' +
        (locText ? '<span class="analysis-location">' + locText.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>' : '') +
        '</div>';
    }
    if (sorted.length > 10) {
      bodyHtml += '<button class="analysis-toggle" id="analysisToggle" data-expanded="false">Show all ' + sorted.length + ' changes</button>';
    }
    bodyHtml += '</div>';
  }

  if (n.desc) {
    bodyHtml += '<div class="deps-section"><h4>Summary</h4><p style="font-size:13px;color:#c9d1d9;line-height:1.6">' + n.desc + '</p></div>';
  }

  var depTypeLabel = { constructor_injection: 'injected', method_injection: 'method param', new_instance: 'new', container_resolved: 'app()', static_call: 'static', type_reference: 'type' };
  var depTypeBadgeColor = { constructor_injection: '#1f6feb', method_injection: '#388bfd', new_instance: '#d29922', container_resolved: '#8957e5', static_call: '#6e7681', type_reference: '#30363d' };
  if (dependsOn.length) {
    bodyHtml += '<div class="deps-section"><h4>Depends on (' + dependsOn.length + ')</h4>';
    for (const l of dependsOnLinks) {
      var d = l.target;
      var depStat = d.isConnected ? '<span style="color:#6e7681;margin-left:auto;font-size:11px">not changed</span>' : '<span style="color:#8b949e;margin-left:auto;font-size:11px">+' + d.add + ' &minus;' + d.del + '</span>';
      var dtLabel = depTypeLabel[l.depType] || l.depType;
      var dtColor = depTypeBadgeColor[l.depType] || '#30363d';
      var dtBadge = '<span style="font-size:10px;padding:1px 5px;border-radius:3px;background:' + dtColor + ';color:#e6edf3;margin-left:6px;white-space:nowrap">' + dtLabel + '</span>';
      bodyHtml += '<div class="dep-item" data-node-id="' + d.id.replace(/"/g, '&quot;') + '"><span class="dep-dot" style="background:' + d.color + '"></span>' + d.id + dtBadge + depStat + '</div>';
    }
    bodyHtml += '</div>';
  }
  if (usedBy.length) {
    bodyHtml += '<div class="deps-section"><h4>Used by (' + usedBy.length + ')</h4>';
    for (const l of usedByLinks) {
      var d = l.source;
      var depStat = d.isConnected ? '<span style="color:#6e7681;margin-left:auto;font-size:11px">not changed</span>' : '<span style="color:#8b949e;margin-left:auto;font-size:11px">+' + d.add + ' &minus;' + d.del + '</span>';
      var dtLabel = depTypeLabel[l.depType] || l.depType;
      var dtColor = depTypeBadgeColor[l.depType] || '#30363d';
      var dtBadge = '<span style="font-size:10px;padding:1px 5px;border-radius:3px;background:' + dtColor + ';color:#e6edf3;margin-left:6px;white-space:nowrap">' + dtLabel + '</span>';
      bodyHtml += '<div class="dep-item" data-node-id="' + d.id.replace(/"/g, '&quot;') + '"><span class="dep-dot" style="background:' + d.color + '"></span>' + d.id + dtBadge + depStat + '</div>';
    }
    bodyHtml += '</div>';
  }

  // Render diff
  var rawDiff = fileDiffs[n.path];
  if (rawDiff) {
    var isPHP = n.path.endsWith('.php');
    var parsedDiff = parseDiffLines(rawDiff);
    var fullContent = fileContents[n.path];
    var tableRows;
    if (diffViewMode === 'split') tableRows = renderSplitDiff(parsedDiff, isPHP);
    else if (diffViewMode === 'full' && fullContent) tableRows = renderFullFile(fullContent, parsedDiff, isPHP);
    else tableRows = renderUnifiedDiff(parsedDiff, isPHP);
    var activeMode = (diffViewMode === 'full' && !fullContent) ? 'unified' : diffViewMode;
    var fullBtn = fullContent
      ? '<button class="diff-view-btn' + (activeMode === 'full' ? ' active' : '') + '" data-view="full">Full file</button>'
      : '';
    bodyHtml += '<div class="diff-section">' +
      '<h4>Diff<span class="diff-view-controls">' +
      '<button class="diff-view-btn' + (activeMode === 'unified' ? ' active' : '') + '" data-view="unified">Unified</button>' +
      '<button class="diff-view-btn' + (activeMode === 'split' ? ' active' : '') + '" data-view="split">Split</button>' +
      fullBtn +
      '</span></h4>' +
      '<table class="diff-table ' + activeMode + '">' + tableRows + '</table></div>';
  }

  document.getElementById('panel-body').innerHTML = bodyHtml;

  // Inject per-method metric badges into diff rows
  if (m && m.method_metrics && m.method_metrics.length > 0) {
    var methodByLine = {};
    m.method_metrics.forEach(function(mth) { if (mth.line) methodByLine[mth.line] = mth; });
    function mthColor(key, val) {
      var t = methodThresholds[key];
      if (!t) return '#8b949e';
      return val > t.bad ? '#f85149' : val > t.warn ? '#d29922' : '#3fb950';
    }
    document.querySelectorAll('.diff-table tr[data-new-ln]').forEach(function(row) {
      var ln = parseInt(row.getAttribute('data-new-ln'));
      var mth = methodByLine[ln];
      if (!mth) return;
      var cell = row.querySelector('td:last-child');
      if (!cell) return;
      var sep = '<span style="color:#484f58"> &middot; </span>';
      var badge = document.createElement('span');
      badge.title = mth.name + '(): CC=' + mth.cc + ', ' + mth.lloc + ' lines, ' + mth.params + ' params';
      badge.style.cssText = 'margin-left:12px;font-size:10px;font-family:monospace;opacity:0.85;white-space:nowrap;vertical-align:middle';
      badge.innerHTML =
        '<span style="color:' + mthColor('cc', mth.cc) + ';font-weight:700">CC:' + mth.cc + '</span>' +
        sep +
        '<span style="color:' + mthColor('lloc', mth.lloc) + '">' + mth.lloc + 'L</span>' +
        sep +
        '<span style="color:' + mthColor('params', mth.params) + '">' + mth.params + 'P</span>';
      cell.appendChild(badge);
    });
  }

  // Wire up "Methods by Complexity" rows to scroll to method in diff (only if the line is visible)
  document.querySelectorAll('tr[data-method-line]').forEach(function(row) {
    var ln = row.getAttribute('data-method-line');
    var target = document.querySelector('.diff-table tr[data-new-ln="' + ln + '"]');
    if (target) {
      row.addEventListener('click', function() { scrollToDiffRow(target); });
    } else {
      row.style.cursor = 'default';
      row.style.opacity = '0.5';
    }
  });

  // Wire up analysis toggle
  var toggleBtn = document.getElementById('analysisToggle');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', function() {
      var expanded = this.getAttribute('data-expanded') === 'true';
      var rows = document.querySelectorAll('.analysis-row');
      for (var ri = 10; ri < rows.length; ri++) {
        rows[ri].style.display = expanded ? 'none' : 'flex';
      }
      this.setAttribute('data-expanded', expanded ? 'false' : 'true');
      this.textContent = expanded ? 'Show all ' + rows.length + ' changes' : 'Show fewer';
    });
  }

  // Wire up analysis row clicks and collect annotation data for dots
  diffAnnotationsData = [];
  document.querySelectorAll('.analysis-row[data-line], .analysis-row[data-location]').forEach(function(row) {
    var line = row.getAttribute('data-line');
    var location = row.getAttribute('data-location');
    row.classList.add('clickable');
    row.addEventListener('click', function() {
      var l = this.getAttribute('data-line');
      var loc = this.getAttribute('data-location');
      scrollToDiffRow(l ? findDiffRowByLine(l) : findDiffRowByLocation(loc));
    });
    var severity = row.getAttribute('data-severity') || 'info';
    var descSpan = row.querySelectorAll('span')[1];
    var description = descSpan ? descSpan.textContent : '';
    diffAnnotationsData.push({ line: line, location: location, severity: severity, description: description });
  });

  // Inject method metric warnings as diff annotations
  if (m && m.method_metrics && m.method_metrics.length > 0) {
    var t = methodThresholds;
    m.method_metrics.forEach(function(mth) {
      if (!mth.line) return;
      if (mth.cc > t.cc.warn)        diffAnnotationsData.push({ line: mth.line, severity: mth.cc > t.cc.bad         ? 'high' : 'medium', description: mth.name + '(): CC ' + mth.cc + ' \u2013 high cyclomatic complexity' });
      if (mth.lloc > t.lloc.warn)    diffAnnotationsData.push({ line: mth.line, severity: mth.lloc > t.lloc.bad     ? 'high' : 'medium', description: mth.name + '(): ' + mth.lloc + ' lines \u2013 long method' });
      if (mth.params > t.params.warn) diffAnnotationsData.push({ line: mth.line, severity: mth.params > t.params.bad ? 'high' : 'medium', description: mth.name + '(): ' + mth.params + ' params \u2013 too many parameters' });
    });
  }
  placeAnnotationDots();

  // Wire diff view toggle
  document.querySelectorAll('.diff-view-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var mode = this.getAttribute('data-view');
      if (mode === diffViewMode) return;
      diffViewMode = mode;
      localStorage.setItem('diffViewMode', mode);
      document.querySelectorAll('.diff-view-btn').forEach(function(b) {
        b.classList.toggle('active', b.getAttribute('data-view') === mode);
      });
      var rd = fileDiffs[n.path];
      if (!rd) return;
      var isPHP = n.path.endsWith('.php');
      var parsed = parseDiffLines(rd);
      var rows;
      if (mode === 'split') rows = renderSplitDiff(parsed, isPHP);
      else if (mode === 'full' && fileContents[n.path]) rows = renderFullFile(fileContents[n.path], parsed, isPHP);
      else rows = renderUnifiedDiff(parsed, isPHP);
      var table = document.querySelector('.diff-table');
      if (table) { table.className = 'diff-table ' + mode; table.innerHTML = rows; placeAnnotationDots(); }
    });
  });

  document.getElementById('panelBack').classList.toggle('visible', openedFromFiles);
  panel.classList.add('open');
  if (window.parent !== window) window.parent.postMessage({ type: 'panelOpened' }, '*');
}

function closePanel() {
  panel.classList.remove('open'); selectedNode = null;
  if (openedFromFiles && window.parent !== window) {
    openedFromFiles = false;
    window.parent.postMessage({ type: 'panelClosed' }, '*');
  }
}

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

// Handle dep-item clicks via event delegation (avoids inline onclick escaping issues)
document.getElementById('panel-body').addEventListener('click', function(e) {
  var item = e.target.closest('.dep-item');
  if (item && item.dataset.nodeId) {
    var target = nodeMap[item.dataset.nodeId];
    if (target) openPanel(target);
  }
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closePanel(); clearPathfinding(); } });

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
      tooltip.innerHTML =
        '<div class="path">' + n.path + '</div>' +
        '<div class="stat"><span class="added">+' + n.add + '</span> &nbsp;<span class="removed">&minus;' + n.del + '</span> &nbsp;' +
        (function(s) { var m = { added: ['#3fb950','NEW'], deleted: ['#f85149','DELETED'], renamed: ['#a5b4fc','RENAMED'], modified: ['#d29922','MODIFIED'] }; var p = m[s] || m.modified; return '<span style="color:' + p[0] + '">' + p[1] + '</span>'; })(n.status) + '</div>' +
        severityLine +
        watchLine +
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
    if (e.shiftKey) {
      if (pathfindNodes.has(dragNode.id)) pathfindNodes.delete(dragNode.id);
      else pathfindNodes.add(dragNode.id);
      if (canPin) dragNode.pinned = true;
      computePathfinding();
    } else {
      clearPathfinding();
      openPanel(dragNode);
      if (canPin) dragNode.pinned = true;
    }
  } else if (dragNode && didDrag && canPin) { dragNode.pinned = true; }
  else if (isPanning && !didDrag) { closePanel(); clearPathfinding(); }
  dragNode = null; isPanning = false;
});

// ── Draw ──────────────────────────────────────────────────────────────────────
function draw() {
  ctx.clearRect(0, 0, W, H);
  ctx.save();
  ctx.translate(panX, panY);
  ctx.scale(zoom, zoom);
  {!! $frameHookJs !!}

  var activeRef = hoveredNode || selectedNode;
  var pathActive = pathfindNodes.size >= 2;
  for (const l of links) {
    if (!isLinkVisible(l)) continue;
    const isSelected = selectedNode && (l.source === selectedNode || l.target === selectedNode);
    const isHovered = hoveredNode && (l.source === hoveredNode || l.target === hoveredNode);
    const isPath = pathActive && pathResult.edges.has(l);
    const highlight = isSelected || isHovered || isPath;
    const dimmed = pathActive ? (!isPath && !isHovered) : (activeRef && !highlight);
    const isConnEdge = l.source.isConnected || l.target.isConnected;

    // Direction color: outgoing (depends on) = red, incoming (used by) = green
    var dirColor = null;
    if (highlight && !isPath && activeRef) {
      if (l.source === activeRef) dirColor = 'rgba(248,81,73,0.85)';  // red: depends on
      else if (l.target === activeRef) dirColor = 'rgba(63,185,80,0.85)';  // green: used by
    }

    // Line — style varies by dependency type
    // constructor_injection: solid, thicker | method_injection: solid | new_instance: long dash
    // container_resolved: dash-dot | static_call: dotted | type_reference: faint solid
    var depDash = [];
    if (!isConnEdge) {
      if      (l.depType === 'new_instance')       depDash = [8, 4];
      else if (l.depType === 'container_resolved') depDash = [6, 3, 2, 3];
      else if (l.depType === 'static_call')        depDash = [2, 4];
      else if (l.depType === 'type_reference')     depDash = [3, 6];
    }
    ctx.beginPath(); ctx.moveTo(l.source.x, l.source.y); ctx.lineTo(l.target.x, l.target.y);
    if (isConnEdge && !highlight) {
      ctx.setLineDash([4, 4]);
      ctx.strokeStyle = dimmed ? 'rgba(48,54,61,0.1)' : 'rgba(72,79,88,0.35)';
    } else {
      ctx.setLineDash(highlight ? [] : depDash);
      ctx.strokeStyle = isPath ? 'rgba(247,129,102,0.9)' : dirColor || (highlight ? 'rgba(88,166,255,0.85)' : dimmed ? 'rgba(48,54,61,0.2)' : 'rgba(139,148,158,0.3)');
    }
    var depWidth = (l.depType === 'constructor_injection' || l.depType === 'container_resolved') ? 2 : 1.5;
    ctx.lineWidth = isPath ? 3 : highlight ? 2.5 : depWidth; ctx.stroke(); ctx.setLineDash([]);

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
    ctx.fillStyle = isPath ? 'rgba(247,129,102,0.9)' : dirColor || (highlight ? 'rgba(88,166,255,0.85)' : dimmed ? 'rgba(48,54,61,0.2)' : 'rgba(139,148,158,0.35)');
    ctx.fill();
  }

  for (const n of nodes) {
    if (!isVisible(n)) continue;
    const isHov = n === hoveredNode, isSel = n === selectedNode;
    const isConn = (hoveredNode && links.some(l => isLinkVisible(l) && ((l.source === hoveredNode && l.target === n) || (l.target === hoveredNode && l.source === n)))) ||
                   (selectedNode && links.some(l => isLinkVisible(l) && ((l.source === selectedNode && l.target === n) || (l.target === selectedNode && l.source === n))));
    const isPathNode = pathActive && pathResult.nodes.has(n.id);
    const isPathSelected = pathfindNodes.has(n.id);
    const activeRef = hoveredNode || selectedNode;
    const dim = pathActive ? (!isPathNode && !isHov) : (activeRef && !isHov && !isSel && !isConn);

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
      ctx.fillStyle = dim ? '#8b949e15' : (n.isConnected ? '#48405880' : (n.color + '99'));
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

var openedFromFiles = false;

window.addEventListener('message', function(e) {
  if (!e.data) return;
  if (e.data.type === 'openFile') {
    var n = nodeMap[e.data.nodeId];
    if (!n) return;
    openedFromFiles = !!e.data.fromFiles;
    openPanel(n);
  }
  if (e.data.type === 'closePanel') {
    closePanel();
  }
  if (e.data.type === 'markFileReviewed') {
    var n = nodeMap[e.data.nodeId];
    if (n) { reviewedNodes.add(n.id); updateReviewedCount(); clearHidden(); }
  }
  if (e.data.type === 'unmarkFileReviewed') {
    var n = nodeMap[e.data.nodeId];
    if (n) { reviewedNodes.delete(n.id); updateReviewedCount(); clearHidden(); }
  }
});

function toggleLegend() {
  var body = document.getElementById('legendBody');
  var chevron = document.getElementById('legendChevron');
  var collapsed = body.style.display === 'none';
  body.style.display = collapsed ? '' : 'none';
  chevron.classList.toggle('collapsed', !collapsed);
}
</script>
</body>
</html>
HTML;
