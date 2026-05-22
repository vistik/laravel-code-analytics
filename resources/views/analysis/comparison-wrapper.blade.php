<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Compare PR #{{ $prANumber }} ↔ PR #{{ $prBNumber }}</title>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&family=jetbrains-mono:400,500,600&display=swap" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
  background: #0d1117; color: #e6edf3;
  font-family: 'Instrument Sans', system-ui, -apple-system, sans-serif;
  font-feature-settings: 'cv02', 'cv03', 'cv04', 'cv11';
  -webkit-font-smoothing: antialiased;
  display: flex; flex-direction: column; height: 100dvh; overflow: hidden;
}

/* ── Topbar ── */
.topbar {
  display: flex; align-items: center; gap: 0;
  padding: 0; background: #161b22;
  border-bottom: 1px solid #21262d; flex-shrink: 0; min-height: 52px;
  box-shadow: 0 1px 0 rgba(255,255,255,.04);
}
.pr-segment {
  display: flex; align-items: center; gap: 8px;
  padding: 0 14px; flex: 1; min-width: 0; align-self: stretch;
  border-right: 1px solid #21262d; overflow: hidden;
}
.pr-segment-right { border-right: none; border-left: 1px solid #21262d; }
.pr-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.pr-label-wrap { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.pr-title { font-size: 12px; font-weight: 500; color: #e6edf3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pr-title a { color: inherit; text-decoration: none; }
.pr-title a:hover { text-decoration: underline; }
.pr-meta-row { display: flex; align-items: center; gap: 8px; }
.pr-meta { font-size: 10.5px; color: #6e7681; white-space: nowrap; }
.pr-risk { display: flex; align-items: center; gap: 5px; flex-shrink: 0; }
.tabs-center {
  display: flex; align-items: center; gap: 4px;
  padding: 0 14px; flex-shrink: 0; border-left: 1px solid #21262d; border-right: 1px solid #21262d;
}
.tab {
  padding: 4px 12px; border-radius: 7px; font-size: 11.5px; font-weight: 500;
  cursor: pointer; border: 1px solid #30363d; color: #8b949e; background: #21262d;
  transition: all 0.15s; font-family: inherit; display: inline-flex; align-items: center;
}
.tab:hover { background: #2d333b; color: #c9d1d9; border-color: #484f58; }
.tab.active { background: #1f6feb; color: #fff; border-color: #388bfd; cursor: default; }

/* ── Bridge bar ── */
.bridge-bar-wrap {
  display: flex; align-items: center; gap: 0;
  background: #161b22; border-bottom: 1px solid #21262d; flex-shrink: 0;
  min-height: 28px; overflow-x: auto;
}
.bridge-bar-wrap::-webkit-scrollbar { height: 3px; }
.bridge-bar-wrap::-webkit-scrollbar-thumb { background: #30363d; border-radius: 4px; }
.bridge-bar-label {
  font-size: 9.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;
  color: #484f58; padding: 0 10px; flex-shrink: 0; border-right: 1px solid #21262d; align-self: stretch;
  display: flex; align-items: center;
}
.bridge-items { display: flex; align-items: center; gap: 0; padding: 0 8px; flex: 1; }
.bridge-item {
  display: flex; align-items: center; gap: 5px; padding: 4px 8px;
  font-size: 10.5px; color: #6e7681; cursor: pointer; border-radius: 5px;
  transition: background 0.1s; white-space: nowrap;
}
.bridge-item:hover { background: #1c2128; color: #c9d1d9; }
.bridge-item-dot { width: 6px; height: 6px; border-radius: 50%; background: #34d399; flex-shrink: 0; }
.bridge-item-arrow { color: #34d399; font-size: 11px; }
.bridge-empty {
  font-size: 10.5px; color: #484f58; padding: 0 12px;
  display: flex; align-items: center;
}

/* ── Content area ── */
.comparison-area {
  flex: 1; display: grid; grid-template-columns: 1fr 4px 1fr; overflow: hidden;
}
.comp-divider {
  background: #21262d; cursor: col-resize; position: relative; flex-shrink: 0;
  transition: background 0.15s;
}
.comp-divider:hover, .comp-divider.active { background: #388bfd; }
iframe { border: none; width: 100%; height: 100%; }

/* ── Global scrollbars ── */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #30363d; border-radius: 10px; }

/* ── Overlay panels (files + findings) ── */
.comparison-area { position: relative; }
.overlay-panel {
  position: absolute; top: 0; left: -100%; height: 100%;
  background: #161b22; border-right: 1px solid #21262d; z-index: 30;
  display: flex; flex-direction: column;
  box-shadow: 8px 0 40px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.03) inset;
  transition: left 0.28s cubic-bezier(0.22,1,0.36,1);
  width: 540px;
}
.overlay-panel.open { left: 0; }
.overlay-panel-resize {
  position: absolute; top: 0; right: -4px; width: 8px; height: 100%;
  cursor: col-resize; z-index: 35;
}
.overlay-panel-resize::after {
  content: ''; position: absolute; top: 0; right: 3px; width: 2px; height: 100%;
  background: transparent; transition: background 0.2s;
}
.overlay-panel-resize:hover::after, .overlay-panel-resize.active::after { background: #388bfd; }
.panel-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 18px 10px; border-bottom: 1px solid #21262d; flex-shrink: 0;
  background: linear-gradient(180deg, rgba(28,33,40,0.7) 0%, rgba(22,27,34,0) 100%);
}
.panel-header h3 { font-size: 13.5px; font-weight: 600; color: #e6edf3; letter-spacing: -0.01em; display:flex; align-items:center; gap:8px; }
.panel-close {
  background: none; border: none; color: #6e7681; font-size: 18px;
  cursor: pointer; line-height: 1; padding: 4px; border-radius: 6px;
  transition: color 0.15s, background 0.15s;
}
.panel-close:hover { color: #e6edf3; background: #21262d; }
.panel-scroll { flex: 1 1 0%; overflow-y: auto; min-height: 0; }
.panel-scroll::-webkit-scrollbar { width: 5px; }
.panel-scroll::-webkit-scrollbar-track { background: transparent; }
.panel-scroll::-webkit-scrollbar-thumb { background: #2d333b; border-radius: 10px; }

/* ── Files toolbar / rows ── */
.files-toolbar {
  display: flex; align-items: center; gap: 8px; padding: 8px 16px;
  border-bottom: 1px solid #21262d; flex-shrink: 0;
}
.files-search {
  background: #21262d; border: 1px solid #30363d; color: #e6edf3;
  font-size: 12px; padding: 5px 10px; border-radius: 7px; flex: 1; min-width: 0;
  font-family: inherit; transition: border-color 0.15s;
}
.files-search:focus { outline: none; border-color: #388bfd; background: #1c2128; }
.files-search::placeholder { color: #484f58; }
.files-toolbar select {
  background: #21262d; border: 1px solid #30363d; color: #c9d1d9;
  font-size: 11.5px; padding: 4px 8px; border-radius: 6px; cursor: pointer; font-family: inherit;
}
.files-toolbar select:focus { outline: none; border-color: #388bfd; }
.files-header-row {
  display: flex; align-items: center; padding: 4px 8px;
  border-bottom: 1px solid #21262d; font-size: 9px; color: #484f58;
  text-transform: uppercase; letter-spacing: 0.4px; flex-shrink: 0; font-weight: 600;
}
.file-row {
  display: flex; align-items: stretch; gap: 0; padding: 9px 14px;
  border-bottom: 1px solid #21262d; cursor: pointer; transition: background 0.1s;
}
.file-row:hover { background: #1c2128; }
.file-col { display: flex; flex-direction: column; justify-content: center; padding: 0 6px; min-width: 0; }
.file-col-pr { width: 20px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
.file-col-signal { width: 44px; flex-shrink: 0; text-align: center; }
.file-col-signal .fval { font-weight: 700; font-size: 16px; line-height: 1; }
.file-col-name { flex: 1; }
.file-name-main { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; color: #e6edf3; overflow: hidden; }
.file-name-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.file-name-path { font-size: 11px; color: #484f58; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
.file-domain-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.file-ext-badge { font-size: 10px; font-family: 'JetBrains Mono', monospace; padding: 1px 5px; border-radius: 4px; margin-left: 5px; background: #21262d; border: 1px solid #30363d; color: #6e7681; }
.file-col-status { width: 52px; flex-shrink: 0; text-align: center; }
.file-col-changes { width: 64px; flex-shrink: 0; text-align: center; }
.file-changes { font-size: 12px; white-space: nowrap; font-variant-numeric: tabular-nums; }
.file-changes .add { color: #3fb950; }
.file-changes .del { color: #f85149; }
.file-col-findings { width: 70px; flex-shrink: 0; text-align: center; }
.file-sev { display: flex; align-items: center; justify-content: center; gap: 3px; }
.file-sev-dot { display: inline-flex; align-items: center; gap: 2px; font-size: 10px; color: #8b949e; }
.file-sev-dot span { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
.fval { font-size: 12px; font-variant-numeric: tabular-nums; color: #c9d1d9; }
.pr-pill {
  font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 4px; border: 1px solid; flex-shrink: 0;
}
.pr-pill-a { color: #58a6ff; background: rgba(88,166,255,0.1); border-color: rgba(88,166,255,0.35); }
.pr-pill-b { color: #d29922; background: rgba(210,153,34,0.1); border-color: rgba(210,153,34,0.35); }

/* ── Findings rows ── */
.finding-row {
  display: flex; align-items: flex-start; gap: 10px; padding: 9px 16px;
  border-bottom: 1px solid rgba(33,38,45,0.7); cursor: pointer; transition: background 0.1s;
}
.finding-row:hover { background: #1c2128; }
.sev-header {
  display: flex; align-items: center; gap: 7px; padding: 5px 16px;
  font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;
  border-bottom: 1px solid #21262d; position: sticky; top: 0; background: #161b22; z-index: 2;
}
.sev-header + .sev-header { border-top: 1px solid #21262d; }
.findings-toolbar {
  display: flex; align-items: center; gap: 8px; padding: 8px 16px;
  border-bottom: 1px solid #21262d; flex-shrink: 0;
}
.findings-toolbar select {
  background: #21262d; border: 1px solid #30363d; color: #c9d1d9;
  font-size: 11.5px; padding: 4px 8px; border-radius: 6px; cursor: pointer; font-family: inherit; flex-shrink: 0;
}
.findings-toolbar select:focus { outline: none; border-color: #388bfd; }
</style>
</head>
<body>

<div class="topbar">
  <div class="pr-segment">
    <div class="pr-dot" style="background:#58a6ff"></div>
    <div class="pr-label-wrap">
      <div class="pr-title">{!! $headlineHtmlA !!}</div>
      <div class="pr-meta-row">
        <span class="pr-meta">{{ $repoA }}</span>
        <span class="pr-meta">{{ $fileCountA }} files</span>
        <span class="pr-meta" style="color:#3fb950">+{{ $prAdditionsA }}</span>
        <span class="pr-meta" style="color:#f85149">−{{ $prDeletionsA }}</span>
      </div>
    </div>
    @if($riskBadgeHtmlA)
    <div class="pr-risk" style="margin-left:auto">{!! $riskBadgeHtmlA !!}</div>
    @endif
  </div>

  <div class="tabs-center">
    <button class="tab" id="filesTab" onclick="toggleFilesPanel()">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M2 1.75C2 .784 2.784 0 3.75 0h6.586c.464 0 .909.184 1.237.513l2.914 2.914c.329.328.513.773.513 1.237v9.586A1.75 1.75 0 0 1 13.25 16h-9.5A1.75 1.75 0 0 1 2 14.25Zm1.75-.25a.25.25 0 0 0-.25.25v12.5c0 .138.112.25.25.25h9.5a.25.25 0 0 0 .25-.25V6h-2.75A1.75 1.75 0 0 1 9 4.25V1.5Zm6.75.062V4.25c0 .138.112.25.25.25h2.688l-.011-.013-2.914-2.914-.013-.011Z"/></svg>Files
    </button>
    <button class="tab" id="findingsTab" onclick="toggleFindingsPanel()">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm9 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-.25-6.25a.75.75 0 0 0-1.5 0v3.5a.75.75 0 0 0 1.5 0v-3.5z"/></svg>Findings
    </button>
    <span style="width:1px;height:20px;background:#30363d;flex-shrink:0;margin:0 4px"></span>
    {!! $tabButtons !!}
  </div>

  <div class="pr-segment pr-segment-right" style="justify-content:flex-end">
    @if($riskBadgeHtmlB)
    <div class="pr-risk" style="margin-right:auto">{!! $riskBadgeHtmlB !!}</div>
    @endif
    <div class="pr-label-wrap" style="text-align:right">
      <div class="pr-title">{!! $headlineHtmlB !!}</div>
      <div class="pr-meta-row" style="justify-content:flex-end">
        <span class="pr-meta" style="color:#3fb950">+{{ $prAdditionsB }}</span>
        <span class="pr-meta" style="color:#f85149">−{{ $prDeletionsB }}</span>
        <span class="pr-meta">{{ $fileCountB }} files</span>
        <span class="pr-meta">{{ $repoB }}</span>
      </div>
    </div>
    <div class="pr-dot" style="background:#d29922;margin-left:8px"></div>
  </div>
</div>

<div class="bridge-bar-wrap" id="bridgeBarWrap" style="{{ $bridgeCount === 0 ? 'display:none' : '' }}">
  <div class="bridge-bar-label">
    <svg width="10" height="10" viewBox="0 0 16 16" fill="#34d399" style="margin-right:4px"><path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Zm4.879-2.773 4.264 2.559a.25.25 0 0 1 0 .428l-4.264 2.559A.25.25 0 0 1 6 10.559V5.442a.25.25 0 0 1 .379-.215Z"/></svg>
    Bridges ({{ $bridgeCount }})
  </div>
  <div class="bridge-items" id="bridgeItems"></div>
</div>

<div class="comparison-area">
  <!-- Files panel -->
  <div class="overlay-panel" id="filesPanel">
    <div class="overlay-panel-resize" id="filesPanelResize"></div>
    <div class="panel-header">
      <h3>
        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;opacity:0.7"><path d="M2 1.75C2 .784 2.784 0 3.75 0h6.586c.464 0 .909.184 1.237.513l2.914 2.914c.329.328.513.773.513 1.237v9.586A1.75 1.75 0 0 1 13.25 16h-9.5A1.75 1.75 0 0 1 2 14.25Zm1.75-.25a.25.25 0 0 0-.25.25v12.5c0 .138.112.25.25.25h9.5a.25.25 0 0 0 .25-.25V6h-2.75A1.75 1.75 0 0 1 9 4.25V1.5Zm6.75.062V4.25c0 .138.112.25.25.25h2.688l-.011-.013-2.914-2.914-.013-.011Z"/></svg>
        Files
      </h3>
      <button class="panel-close" onclick="toggleFilesPanel()">&times;</button>
    </div>
    <div class="files-toolbar">
      <input type="text" class="files-search" id="filesSearch" placeholder="Filter files...">
      <select id="filesSort">
        <option value="signal">Signal</option>
        <option value="severity">Severity</option>
        <option value="changes">Changes</option>
        <option value="name">Name</option>
      </select>
    </div>
    <div class="files-header-row">
      <div class="file-col" style="width:20px;flex-shrink:0"></div>
      <div class="file-col file-col-signal">Signal</div>
      <div class="file-col file-col-name"></div>
      <div class="file-col file-col-status">Status</div>
      <div class="file-col file-col-changes">Changes</div>
      <div class="file-col file-col-findings">Findings</div>
    </div>
    <div class="panel-scroll" id="filesScroll">
      <div id="filesRows"></div>
    </div>
  </div>

  <!-- Findings panel -->
  <div class="overlay-panel" id="findingsPanel">
    <div class="overlay-panel-resize" id="findingsPanelResize"></div>
    <div class="panel-header">
      <h3>
        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;opacity:0.7"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm9 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-.25-6.25a.75.75 0 0 0-1.5 0v3.5a.75.75 0 0 0 1.5 0v-3.5z"/></svg>
        Findings <span id="findingsCountBadge" style="font-size:10.5px;font-weight:500;background:#21262d;border:1px solid #30363d;border-radius:10px;padding:1px 7px;color:#8b949e"></span>
      </h3>
      <button class="panel-close" onclick="toggleFindingsPanel()">&times;</button>
    </div>
    <div class="findings-toolbar">
      <input type="text" class="files-search" id="findingsSearch" placeholder="Filter findings...">
      <select id="findingsSevFilter">
        <option value="">All severities</option>
        <option value="very_high">Very High+</option>
        <option value="high">High+</option>
        <option value="medium">Medium+</option>
        <option value="low">Low+</option>
        <option value="info">Info+</option>
      </select>
    </div>
    <div class="panel-scroll" id="findingsScroll">
      <div id="findingsRows"></div>
    </div>
  </div>

  <iframe id="view-left" srcdoc=""></iframe>
  <div class="comp-divider" id="compDivider"></div>
  <iframe id="view-right" srcdoc=""></iframe>
</div>

<script>
const layoutsLeft = {
  {!! $jsLayoutDataLeft !!}
};
const layoutsRight = {
  {!! $jsLayoutDataRight !!}
};

const bridges = {!! $bridgesJson !!};
const bridgeBarItems = {!! $bridgeBarJson !!};

// Reverse map: rightId => leftId
const bridgesReverse = {};
for (var k in bridges) { bridgesReverse[bridges[k]] = k; }

function show(name) {
  document.getElementById('view-left').srcdoc = atob(layoutsLeft[name]);
  document.getElementById('view-right').srcdoc = atob(layoutsRight[name]);
}

document.querySelectorAll('.tab[data-layout]').forEach(function(t) {
  t.addEventListener('click', function() {
    document.querySelectorAll('.tab[data-layout]').forEach(function(x) { x.classList.remove('active'); });
    t.classList.add('active');
    show(t.dataset.layout);
  });
});

show('{{ $defaultView }}');

// ── Divider resize ────────────────────────────────────────────────────────────
(function() {
  var divider = document.getElementById('compDivider');
  var area = document.querySelector('.comparison-area');
  var isDragging = false;
  var startX, startCols;

  divider.addEventListener('mousedown', function(e) {
    e.preventDefault();
    isDragging = true;
    startX = e.clientX;
    divider.classList.add('active');
    document.body.style.cursor = 'col-resize';
    document.body.style.userSelect = 'none';
    // Pointer events off on iframes during resize
    document.getElementById('view-left').style.pointerEvents = 'none';
    document.getElementById('view-right').style.pointerEvents = 'none';
  });

  document.addEventListener('mousemove', function(e) {
    if (!isDragging) return;
    var total = area.offsetWidth - 4;
    var leftPx = Math.max(200, Math.min(total - 200, e.clientX));
    var rightPx = total - leftPx;
    area.style.gridTemplateColumns = leftPx + 'px 4px ' + rightPx + 'px';
  });

  document.addEventListener('mouseup', function() {
    if (!isDragging) return;
    isDragging = false;
    divider.classList.remove('active');
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    document.getElementById('view-left').style.pointerEvents = '';
    document.getElementById('view-right').style.pointerEvents = '';
  });
})();

// ── Bridge hover relay ────────────────────────────────────────────────────────
window.addEventListener('message', function(e) {
  if (!e.data) return;
  var leftWin = document.getElementById('view-left').contentWindow;
  var rightWin = document.getElementById('view-right').contentWindow;

  if (e.data.type === 'bridge-node-hover') {
    var nodeId = e.data.nodeId;
    if (e.source === leftWin) {
      var partnerId = bridges[nodeId];
      if (partnerId) rightWin.postMessage({ type: 'bridge-highlight-partner', nodeId: partnerId }, '*');
    } else if (e.source === rightWin) {
      var partnerId = bridgesReverse[nodeId];
      if (partnerId) leftWin.postMessage({ type: 'bridge-highlight-partner', nodeId: partnerId }, '*');
    }
  }

  if (e.data.type === 'bridge-node-out') {
    leftWin.postMessage({ type: 'bridge-clear' }, '*');
    rightWin.postMessage({ type: 'bridge-clear' }, '*');
  }
});

// ── Files & Findings panels ───────────────────────────────────────────────────
(function() {
  {!! $wrapperSeverityJs !!}
  const nodesA = {!! $wrapperNodesJsonA !!};
  const nodesB = {!! $wrapperNodesJsonB !!};
  const analysisA = {!! $wrapperAnalysisJsonA !!};
  const analysisB = {!! $wrapperAnalysisJsonB !!};
  const metricsA = {!! $wrapperMetricsJsonA !!};
  const metricsB = {!! $wrapperMetricsJsonB !!};

  var changedFilesA = nodesA.filter(function(n) { return !n.isConnected; }).map(function(n) { return Object.assign({}, n, {_prSide: 'left'}); });
  var changedFilesB = nodesB.filter(function(n) { return !n.isConnected; }).map(function(n) { return Object.assign({}, n, {_prSide: 'right'}); });
  var changedFiles = changedFilesA.concat(changedFilesB);

  var currentFilesSort = 'signal';
  var currentFilesDir = -1;

  function signalColor(s) {
    if (s >= 60) return '#f85149';
    if (s >= 30) return '#d29922';
    if (s >= 10) return '#58a6ff';
    return '#3fb950';
  }

  function getFilesSort(n) {
    switch (currentFilesSort) {
      case 'signal': return n._signal || 0;
      case 'severity': return (n.veryHighCount * 10000) + (n.highCount * 1000) + (n.mediumCount * 100) + (n.lowCount * 10) + (n.infoCount || 0);
      case 'changes': return (n.add || 0) + (n.del || 0);
      case 'name': return n.id.toLowerCase();
      default: return 0;
    }
  }

  var statusStyles = {
    added: ['#0d3520', '#3fb950', '#238636', 'New'],
    deleted: ['#3d1214', '#f85149', '#da3633', 'Deleted'],
    renamed: ['#1c1d4e', '#a5b4fc', '#6366f1', 'Renamed'],
    modified: ['#2d1c00', '#d29922', '#9e6a03', 'Modified'],
  };

  function renderFileList() {
    var filter = (document.getElementById('filesSearch').value || '').toLowerCase();
    var list = changedFiles.filter(function(n) {
      if (!filter) return true;
      return n.id.toLowerCase().indexOf(filter) !== -1 || (n.path || '').toLowerCase().indexOf(filter) !== -1;
    });
    list.sort(function(a, b) {
      var va = getFilesSort(a), vb = getFilesSort(b);
      var result = typeof va === 'string' ? va.localeCompare(vb) : va - vb;
      if (result === 0 && a._prSide !== b._prSide) return a._prSide === 'left' ? -1 : 1;
      return currentFilesDir * result;
    });
    document.getElementById('filesSort').value = currentFilesSort;
    var html = '';
    list.forEach(function(n) {
      var m = (n._prSide === 'left' ? metricsA : metricsB)[n.path] || {};
      var st = statusStyles[n.status] || statusStyles.modified;
      var rc = signalColor(n._signal || 0);
      var sevHtml = '';
      if (n.veryHighCount > 0) sevHtml += '<span class="file-sev-dot"><span style="background:' + sevColors.very_high + '"></span>' + n.veryHighCount + '</span>';
      if (n.highCount > 0) sevHtml += '<span class="file-sev-dot"><span style="background:' + sevColors.high + '"></span>' + n.highCount + '</span>';
      if (n.mediumCount > 0) sevHtml += '<span class="file-sev-dot"><span style="background:' + sevColors.medium + '"></span>' + n.mediumCount + '</span>';
      if (n.lowCount > 0) sevHtml += '<span class="file-sev-dot"><span style="background:' + sevColors.low + '"></span>' + n.lowCount + '</span>';
      if (!sevHtml) sevHtml = '<span style="color:#484f58">&mdash;</span>';
      var prPillClass = n._prSide === 'left' ? 'pr-pill-a' : 'pr-pill-b';
      var prPillLabel = n._prSide === 'left' ? 'A' : 'B';
      var chips = [];
      if (m.cc != null) { var cc = m.cc >= 20 ? '#f85149' : m.cc >= 10 ? '#d29922' : '#484f58'; chips.push('<span style="font-size:10px;color:' + cc + ';font-family:\'JetBrains Mono\',monospace">cc ' + m.cc + '</span>'); }
      if (m.mi != null) { var mi = m.mi < 65 ? '#f85149' : m.mi < 85 ? '#d29922' : '#484f58'; chips.push('<span style="font-size:10px;color:' + mi + ';font-family:\'JetBrains Mono\',monospace">mi ' + Math.round(m.mi) + '%</span>'); }
      var chipsHtml = chips.length ? '<div style="display:flex;gap:5px;margin-top:2px">' + chips.join('<span style="color:#30363d">|</span>') + '</div>' : '';
      html += '<div class="file-row" data-node-id="' + n.id.replace(/"/g, '&quot;') + '" data-pr-side="' + n._prSide + '">'
        + '<div class="file-col file-col-pr"><span class="pr-pill ' + prPillClass + '">' + prPillLabel + '</span></div>'
        + '<div class="file-col file-col-signal"><span class="fval" style="color:' + rc + '">' + (n._signal || 0) + '</span></div>'
        + '<div class="file-col file-col-name">'
          + '<div class="file-name-main"><span class="file-domain-dot" style="background:' + (n.domainColor || '#8b949e') + '"></span>'
            + '<span class="file-name-text">' + n.id.replace(/</g, '&lt;') + '</span>'
            + (n.ext ? '<span class="file-ext-badge">.' + n.ext + '</span>' : '')
          + '</div>'
          + '<div class="file-name-path">' + (n.path || '').replace(/</g, '&lt;') + '</div>'
          + chipsHtml
        + '</div>'
        + '<div class="file-col file-col-status"><span class="fval" style="color:' + st[1] + '">' + st[3] + '</span></div>'
        + '<div class="file-col file-col-changes"><span class="file-changes"><span class="add">+' + (n.add || 0) + '</span> <span class="del">&minus;' + (n.del || 0) + '</span></span></div>'
        + '<div class="file-col file-col-findings"><span class="file-sev">' + sevHtml + '</span></div>'
        + '</div>';
    });
    if (!html) html = '<div style="padding:24px;text-align:center;font-size:13px;color:#6e7681">No files match your filter.</div>';
    document.getElementById('filesRows').innerHTML = html;
  }

  document.getElementById('filesSearch').addEventListener('input', renderFileList);
  document.getElementById('filesSort').addEventListener('change', function() {
    currentFilesSort = this.value;
    currentFilesDir = currentFilesSort === 'name' ? 1 : -1;
    renderFileList();
  });
  document.getElementById('filesRows').addEventListener('click', function(e) {
    var row = e.target.closest('.file-row');
    if (!row) return;
    var iframeId = row.dataset.prSide === 'left' ? 'view-left' : 'view-right';
    document.getElementById(iframeId).contentWindow.postMessage({ type: 'openFile', nodeId: row.dataset.nodeId, fromFiles: true }, '*');
  });

  // ── Findings panel ────────────────────────────────────────────────────────
  var filePathToNodeA = {};
  nodesA.forEach(function(n) { if (n.path) filePathToNodeA[n.path] = Object.assign({}, n, {_prSide: 'left'}); });
  var filePathToNodeB = {};
  nodesB.forEach(function(n) { if (n.path) filePathToNodeB[n.path] = Object.assign({}, n, {_prSide: 'right'}); });

  var allFindings = [];

  [['left', analysisA, filePathToNodeA], ['right', analysisB, filePathToNodeB]].forEach(function(tuple) {
    var side = tuple[0], analysis = tuple[1], pathToNode = tuple[2];
    Object.keys(analysis).forEach(function(path) {
      var findings = analysis[path];
      if (!findings || !findings.length) return;
      var node = pathToNode[path] || null;
      findings.forEach(function(f) {
        allFindings.push({ severity: f.severity || 'info', category: f.category || '', description: f.description || '', location: f.location || null, line: f.line || null, filePath: path, node: node, _prSide: side });
      });
    });
  });

  allFindings.sort(function(a, b) {
    var ai = sevOrder[a.severity] != null ? sevOrder[a.severity] : 99;
    var bi = sevOrder[b.severity] != null ? sevOrder[b.severity] : 99;
    return ai - bi;
  });

  function escF(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function catLabel(cat) { return (cat || '').replace(/_/g, ' '); }
  function basename(p) { return (p || '').split('/').pop(); }
  function dirname(p) { var parts = (p || '').split('/'); parts.pop(); return parts.join('/'); }

  function renderFindingsList() {
    var filter = (document.getElementById('findingsSearch').value || '').toLowerCase();
    var sevFilter = document.getElementById('findingsSevFilter').value;
    var list = allFindings.filter(function(f) {
      if (sevFilter && sevScores[f.severity] < sevScores[sevFilter]) return false;
      if (!filter) return true;
      return f.description.toLowerCase().indexOf(filter) !== -1
          || f.filePath.toLowerCase().indexOf(filter) !== -1
          || f.category.toLowerCase().indexOf(filter) !== -1;
    });
    document.getElementById('findingsCountBadge').textContent = list.length;
    var html = '';
    var lastSev = null;
    list.forEach(function(f) {
      var sev = f.severity;
      var color = sevColors[sev] || '#8b949e';
      if (sev !== lastSev) {
        lastSev = sev;
        var sevCount = list.filter(function(x) { return x.severity === sev; }).length;
        html += '<div class="sev-header" style="color:' + color + '">'
          + '<span style="width:7px;height:7px;border-radius:50%;background:' + color + ';display:inline-block;flex-shrink:0"></span>'
          + escF(sevLabels[sev] || sev)
          + '<span style="background:' + color + '22;border:1px solid ' + color + '44;border-radius:10px;padding:1px 7px;font-size:9px;color:' + color + ';margin-left:2px">' + sevCount + '</span>'
          + '</div>';
      }
      var fileName = basename(f.filePath);
      var fileDir = dirname(f.filePath);
      var loc = f.location || '';
      if (f.line) loc += (f.location ? ':' : 'line ') + f.line;
      var nodeId = f.node ? escF(f.node.id) : '';
      var domainColor = f.node ? (f.node.domainColor || '#484f58') : '#484f58';
      var lineAttr = f.line ? ' data-target-line="' + f.line + '"' : '';
      var locAttr = (!f.line && f.location) ? ' data-target-location="' + escF(f.location) + '"' : '';
      var prPillClass = f._prSide === 'left' ? 'pr-pill-a' : 'pr-pill-b';
      var prPillLabel = f._prSide === 'left' ? 'A' : 'B';
      html += '<div class="finding-row" data-node-id="' + nodeId + '" data-pr-side="' + f._prSide + '"' + lineAttr + locAttr + '>'
        + '<div style="width:8px;height:8px;border-radius:50%;background:' + color + ';flex-shrink:0;margin-top:4px"></div>'
        + '<div style="flex:1;min-width:0">'
          + '<div style="font-size:12.5px;color:#c9d1d9;line-height:1.45;margin-bottom:4px">' + escF(f.description) + '</div>'
          + '<div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap">'
            + '<span style="background:#1c2128;border:1px solid #30363d;border-radius:4px;padding:1px 5px;color:#6e7681;font-family:\'JetBrains Mono\',monospace;font-size:10px;white-space:nowrap">' + escF(catLabel(f.category)) + '</span>'
            + '<span class="pr-pill ' + prPillClass + '">' + prPillLabel + '</span>'
          + '</div>'
        + '</div>'
        + '<div style="text-align:right;flex-shrink:0;max-width:38%;min-width:0">'
          + '<div style="font-size:11.5px;font-weight:500;color:#8b949e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;justify-content:flex-end;gap:4px">'
            + '<span style="width:6px;height:6px;border-radius:50%;background:' + domainColor + ';flex-shrink:0;display:inline-block"></span>'
            + escF(fileName)
          + '</div>'
          + (fileDir ? '<div style="font-size:10px;color:#484f58;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px">' + escF(fileDir) + '</div>' : '')
          + (loc ? '<div style="font-size:10px;color:#6e7681;white-space:nowrap;margin-top:1px;font-family:\'JetBrains Mono\',monospace">' + escF(loc) + '</div>' : '')
        + '</div>'
        + '</div>';
    });
    if (!html) html = '<div style="padding:32px;text-align:center;font-size:13px;color:#6e7681">No findings match your filter.</div>';
    document.getElementById('findingsRows').innerHTML = html;
  }

  document.getElementById('findingsSearch').addEventListener('input', renderFindingsList);
  document.getElementById('findingsSevFilter').addEventListener('change', renderFindingsList);
  document.getElementById('findingsRows').addEventListener('click', function(e) {
    var row = e.target.closest('.finding-row');
    if (!row || !row.dataset.nodeId) return;
    var line = row.dataset.targetLine ? parseInt(row.dataset.targetLine, 10) : null;
    var location = row.dataset.targetLocation || null;
    var iframeId = row.dataset.prSide === 'left' ? 'view-left' : 'view-right';
    document.getElementById(iframeId).contentWindow.postMessage({ type: 'openFile', nodeId: row.dataset.nodeId, fromFiles: false, fromFindings: true, targetLine: line, targetLocation: location }, '*');
  });

  // Resize — findings panel
  (function() {
    var el = document.getElementById('findingsPanel');
    var handle = document.getElementById('findingsPanelResize');
    var w = 580; var resizing = false;
    el.style.width = w + 'px';
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;cursor:col-resize;display:none';
    document.body.appendChild(overlay);
    handle.addEventListener('mousedown', function(e) { e.preventDefault(); resizing = true; handle.classList.add('active'); el.style.transition = 'none'; overlay.style.display = 'block'; });
    document.addEventListener('mousemove', function(e) { if (!resizing) return; var rect = document.querySelector('.comparison-area').getBoundingClientRect(); el.style.width = Math.max(360, Math.min(e.clientX - rect.left, rect.width * 0.9)) + 'px'; });
    document.addEventListener('mouseup', function() { if (!resizing) return; resizing = false; handle.classList.remove('active'); el.style.transition = ''; overlay.style.display = 'none'; });
  })();

  // Expose toggles
  window.toggleFilesPanel = function() {
    var panel = document.getElementById('filesPanel');
    var other = document.getElementById('findingsPanel');
    var isOpen = panel.classList.contains('open');
    if (isOpen) { panel.classList.remove('open'); document.getElementById('filesTab').classList.remove('active'); }
    else {
      if (other.classList.contains('open')) { other.classList.remove('open'); document.getElementById('findingsTab').classList.remove('active'); }
      panel.classList.add('open');
      document.getElementById('filesTab').classList.add('active');
      renderFileList();
    }
  };
  window.toggleFindingsPanel = function() {
    var panel = document.getElementById('findingsPanel');
    var other = document.getElementById('filesPanel');
    var isOpen = panel.classList.contains('open');
    if (isOpen) { panel.classList.remove('open'); document.getElementById('findingsTab').classList.remove('active'); }
    else {
      if (other.classList.contains('open')) { other.classList.remove('open'); document.getElementById('filesTab').classList.remove('active'); }
      panel.classList.add('open');
      document.getElementById('findingsTab').classList.add('active');
      renderFindingsList();
    }
  };

  // Resize — files panel
  (function() {
    var el = document.getElementById('filesPanel');
    var handle = document.getElementById('filesPanelResize');
    var w = 540; var resizing = false;
    el.style.width = w + 'px';
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;cursor:col-resize;display:none';
    document.body.appendChild(overlay);
    handle.addEventListener('mousedown', function(e) { e.preventDefault(); resizing = true; handle.classList.add('active'); el.style.transition = 'none'; overlay.style.display = 'block'; });
    document.addEventListener('mousemove', function(e) { if (!resizing) return; var rect = document.querySelector('.comparison-area').getBoundingClientRect(); el.style.width = Math.max(360, Math.min(e.clientX - rect.left, rect.width * 0.9)) + 'px'; });
    document.addEventListener('mouseup', function() { if (!resizing) return; resizing = false; handle.classList.remove('active'); el.style.transition = ''; overlay.style.display = 'none'; });
  })();
})();

// ── Bridge bar ────────────────────────────────────────────────────────────────
(function() {
  if (!bridgeBarItems.length) return;
  var container = document.getElementById('bridgeItems');
  var html = '';
  bridgeBarItems.forEach(function(item) {
    var ll = item.leftLabel.replace(/&/g,'&amp;').replace(/</g,'&lt;');
    var rl = item.rightLabel.replace(/&/g,'&amp;').replace(/</g,'&lt;');
    html += '<div class="bridge-item" data-left-id="' + item.leftId.replace(/"/g,'&quot;') + '" data-right-id="' + item.rightId.replace(/"/g,'&quot;') + '">'
      + '<div class="bridge-item-dot"></div>'
      + '<span>' + ll + '</span>'
      + '<span class="bridge-item-arrow">↔</span>'
      + '<span>' + rl + '</span>'
      + '</div>';
  });
  container.innerHTML = html;

  container.addEventListener('click', function(e) {
    var item = e.target.closest('.bridge-item');
    if (!item) return;
    var leftId = item.dataset.leftId;
    var rightId = item.dataset.rightId;
    if (leftId) document.getElementById('view-left').contentWindow.postMessage({ type: 'openFile', nodeId: leftId }, '*');
    if (rightId) document.getElementById('view-right').contentWindow.postMessage({ type: 'openFile', nodeId: rightId }, '*');
  });

  container.addEventListener('mouseover', function(e) {
    var item = e.target.closest('.bridge-item');
    if (!item) return;
    var leftId = item.dataset.leftId;
    var rightId = item.dataset.rightId;
    if (leftId) document.getElementById('view-left').contentWindow.postMessage({ type: 'highlightCycle', nodeIds: [leftId] }, '*');
    if (rightId) document.getElementById('view-right').contentWindow.postMessage({ type: 'highlightCycle', nodeIds: [rightId] }, '*');
  });

  container.addEventListener('mouseout', function(e) {
    if (!e.target.closest('.bridge-item') || !e.relatedTarget?.closest('.bridge-item')) {
      document.getElementById('view-left').contentWindow.postMessage({ type: 'clearHighlight' }, '*');
      document.getElementById('view-right').contentWindow.postMessage({ type: 'clearHighlight' }, '*');
    }
  });
})();
</script>
</body>
</html>
