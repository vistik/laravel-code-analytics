<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PR #{{ $prNumber }} — {!! $escapedTitle !!}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { background: #0d1117; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
  .topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 8px 16px; background: #161b22; border-bottom: 1px solid #30363d; flex-shrink: 0; min-height: 44px; }
  .headline { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
  .pr-link { font-size: 14px; font-weight: 600; color: #58a6ff; text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
  .pr-link:hover { text-decoration: underline; }
  .pr-meta { font-size: 11px; color: #6e7681; white-space: nowrap; }
  .risk-badge, .metrics-badge { display: flex; align-items: center; gap: 8px; flex-shrink: 0; position: relative; cursor: default; }
  .risk-score-num { font-size: 20px; font-weight: 700; line-height: 1; }
  .risk-score-denom { font-size: 11px; color: #6e7681; }
  .risk-label { font-size: 11px; padding: 2px 8px; border-radius: 10px; border: 1px solid; font-weight: 500; }
  .risk-tooltip, .metrics-tooltip { display: none; position: absolute; top: calc(100% + 10px); right: 0; background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 12px 14px; box-shadow: 0 8px 24px rgba(0,0,0,0.5); z-index: 100; }
  .risk-tooltip { min-width: 210px; }
  .metrics-tooltip { white-space: nowrap; }
  .risk-badge:hover .risk-tooltip, .metrics-badge:hover .metrics-tooltip { display: block; }
  .topbar-badges { display: flex; align-items: center; gap: 16px; flex-shrink: 0; }
  .badge-divider { width: 1px; height: 24px; background: #30363d; }
  .tabs { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
  .tab { padding: 5px 14px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; border: 1px solid #30363d; color: #8b949e; background: #21262d; transition: all 0.15s; }
  .tab:hover { background: #30363d; color: #c9d1d9; }
  .tab.active { background: #58a6ff; color: #fff; border-color: #58a6ff; cursor: default; }
  .content-area { flex: 1; position: relative; overflow: hidden; }
  iframe { border: none; width: 100%; height: 100%; }

  /* ── File overview panel (right slide-over) ── */
  .files-panel {
    position: absolute; top: 0; right: 0; height: 100%;
    background: #161b22; border-left: 1px solid #30363d; z-index: 30;
    display: flex; flex-direction: column;
    box-shadow: -4px 0 24px rgba(0,0,0,.5);
    right: -100%; transition: right 0.25s ease;
  }
  .files-panel.open { right: 0; }
  .files-panel-resize {
    position: absolute; top: 0; left: -4px; width: 8px; height: 100%;
    cursor: col-resize; z-index: 35;
  }
  .files-panel-resize::after {
    content: ''; position: absolute; top: 0; left: 3px; width: 2px; height: 100%;
    background: transparent; transition: background 0.15s;
  }
  .files-panel-resize:hover::after, .files-panel-resize.active::after { background: #58a6ff; }
  .files-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px 12px; border-bottom: 1px solid #30363d; flex-shrink: 0;
  }
  .files-panel-header h3 { font-size: 14px; font-weight: 600; color: #e6edf3; }
  .files-panel-close {
    background: none; border: none; color: #8b949e; font-size: 20px;
    cursor: pointer; line-height: 1; padding: 4px;
  }
  .files-panel-close:hover { color: #e6edf3; }
  .files-toolbar {
    display: flex; align-items: center; gap: 10px; padding: 10px 20px;
    border-bottom: 1px solid #30363d; flex-shrink: 0;
  }
  .files-toolbar .sort-label { font-size: 11px; color: #6e7681; text-transform: uppercase; letter-spacing: 0.5px; }
  .files-toolbar select {
    background: #21262d; border: 1px solid #30363d; color: #c9d1d9;
    font-size: 12px; padding: 4px 8px; border-radius: 6px; cursor: pointer;
  }
  .files-toolbar select:focus { outline: none; border-color: #58a6ff; }
  .files-search {
    background: #21262d; border: 1px solid #30363d; color: #c9d1d9;
    font-size: 12px; padding: 5px 10px; border-radius: 6px; flex: 1; min-width: 0;
  }
  .files-search:focus { outline: none; border-color: #58a6ff; }
  .files-search::placeholder { color: #484f58; }
  .files-scroll { flex: 1 1 0%; overflow-y: auto; min-height: 0; }
  .files-scroll::-webkit-scrollbar { width: 6px; }
  .files-scroll::-webkit-scrollbar-track { background: transparent; }
  .files-scroll::-webkit-scrollbar-thumb { background: #30363d; border-radius: 3px; }
  .file-row {
    display: flex; align-items: stretch; gap: 0; padding: 10px 16px;
    border-bottom: 1px solid #21262d; cursor: pointer; transition: background 0.1s;
  }
  .file-row:hover { background: #21262d; }
  .file-col {
    display: flex; flex-direction: column; justify-content: center;
    padding: 0 8px; min-width: 0;
  }
  .file-col-label { display: none; }
  .files-header-row { display: flex; align-items: center; padding: 4px 8px; border-bottom: 1px solid #21262d; font-size: 9px; color: #484f58; text-transform: uppercase; letter-spacing: 0.3px; flex-shrink: 0; }
  .file-col-signal { width: 44px; flex-shrink: 0; text-align: center; position: relative; }
  .file-col-signal .file-col-val { font-weight: 700; font-size: 16px; line-height: 1; cursor: default; }
  .signal-tooltip { display: none; position: absolute; top: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 10px 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.5); z-index: 200; min-width: 180px; white-space: nowrap; }
  .file-col-signal:hover .signal-tooltip { display: block; }
  .signal-tooltip-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 11px; color: #8b949e; line-height: 1.8; }
  .signal-tooltip-row .label { display: flex; align-items: center; gap: 5px; }
  .signal-tooltip-row .val { color: #e6edf3; font-weight: 600; }
  .file-col-name { flex: 1; }
  .file-name-main {
    display: flex; align-items: center; gap: 6px;
    font-size: 13px; font-weight: 500; color: #e6edf3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .file-name-path { font-size: 11px; color: #484f58; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
  .file-domain-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .file-col-status { width: 52px; flex-shrink: 0; text-align: center; }
  .file-col-status .file-col-val { font-size: 11px; font-weight: 500; }
  .file-col-changes { width: 64px; flex-shrink: 0; text-align: center; }
  .file-changes { font-size: 12px; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .file-changes .add { color: #3fb950; }
  .file-changes .del { color: #f85149; }
  .file-col-findings { width: 70px; flex-shrink: 0; text-align: center; }
  .file-sev { display: flex; align-items: center; justify-content: center; gap: 3px; }
  .file-sev-dot { display: inline-flex; align-items: center; gap: 2px; font-size: 10px; color: #8b949e; }
  .file-sev-dot span { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
  .file-col-metric { width: 36px; flex-shrink: 0; text-align: center; }
  .file-col-val { font-size: 12px; font-variant-numeric: tabular-nums; color: #c9d1d9; }
  .file-col-review { width: 28px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
  .file-review-btn {
    width: 22px; height: 22px; border-radius: 4px; border: 1px solid transparent;
    background: transparent; cursor: pointer; color: #484f58;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; line-height: 1; transition: all 0.15s; padding: 0;
  }
  .file-review-btn:hover { background: #1a3a2a; color: #3fb950; border-color: #238636; }
  .file-review-btn.reviewed { background: #0d3520; color: #3fb950; border-color: #238636; }

  /* ── Narrow panel (< 520px) ── */
  .files-panel.narrow .file-col-status,
  .files-panel.narrow .file-col-metric { display: none; }
  .files-panel.narrow .file-col-review { width: 24px; }
  .files-panel.narrow .file-col-signal { width: 34px; }
  .files-panel.narrow .file-col-signal .file-col-val { font-size: 13px; }
  .files-panel.narrow .file-col-changes { width: 54px; }
  .files-panel.narrow .file-changes { font-size: 11px; }
  .files-panel.narrow .file-col-findings { width: 50px; }

  /* ── Very narrow panel (< 400px) ── */
  .files-panel.very-narrow .file-col-status,
  .files-panel.very-narrow .file-col-metric,
  .files-panel.very-narrow .file-col-findings,
  .files-panel.very-narrow .file-col-changes { display: none; }
  .files-panel.very-narrow .file-col-signal { width: 30px; }
  .files-panel.very-narrow .file-col-signal .file-col-val { font-size: 12px; }
  .files-panel.very-narrow .files-header-row .file-col-signal { display: none; }
  .files-panel.very-narrow .file-name-main { font-size: 12px; }
</style>
</head>
<body>
<div class="topbar">
  <div class="headline">
    {!! $headlineHtml !!}
    <span class="pr-meta">{{ $fileCount }} files &middot; +{{ $prAdditions }} &minus;{{ $prDeletions }}</span>
  </div>
  <div class="topbar-badges">
    {!! $metricsBadgeHtml !!}
    {!! $riskBadgeHtml !!}
  </div>
  <div class="tabs">
    <button class="tab" id="filesTab" onclick="toggleFilesPanel()">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M1.75 1h8.5c.966 0 1.75.784 1.75 1.75v5.5A1.75 1.75 0 0110.25 10H7.061l-2.574 2.573A.25.25 0 014 12.354V10h-.25A1.75 1.75 0 012 8.25v-5.5C2 1.784 2.784 1 3.75 1zM1.75 2.5a.25.25 0 00-.25.25v5.5c0 .138.112.25.25.25h2.5a.75.75 0 01.75.75v1.19l2.06-2.06a.75.75 0 01.53-.22h3.41a.25.25 0 00.25-.25v-5.5a.25.25 0 00-.25-.25h-8.5z"/></svg>Files
    </button>
    {!! $tabButtons !!}
  </div>
</div>
<div class="content-area">
  <iframe id="view" srcdoc=""></iframe>
  <div class="files-panel" id="filesPanel">
    <div class="files-panel-resize" id="filesPanelResize"></div>
    <div class="files-panel-header">
      <h3>Files</h3>
      <div style="display:flex;align-items:center;gap:8px">
        <button id="showReviewedBtn" onclick="toggleShowReviewed()" title="Show reviewed files" style="background:none;border:1px solid #30363d;border-radius:6px;color:#8b949e;font-size:11px;padding:3px 8px;cursor:pointer;white-space:nowrap;transition:all 0.15s"><span id="showReviewedLabel">Show reviewed</span> <span id="reviewedBadge" style="display:none;background:#0d3520;color:#3fb950;border-radius:8px;padding:1px 5px;margin-left:2px"></span></button>
        <button class="files-panel-close" onclick="toggleFilesPanel()">&times;</button>
      </div>
    </div>
    <div class="files-toolbar">
      <input type="text" class="files-search" id="filesSearch" placeholder="Filter files...">
      <select id="filesSort">
        <option value="signal">Signal</option>
        <option value="severity">Severity</option>
        <option value="changes">Changes</option>
        <option value="name">Name</option>
        <option value="cc">CC</option>
        <option value="mi">MI</option>
      </select>
    </div>
    <div class="files-header-row">
      <div class="file-col file-col-review"></div>
      <div class="file-col file-col-risk">Risk</div>
      <div class="file-col file-col-name"></div>
      <div class="file-col file-col-status">Status</div>
      <div class="file-col file-col-changes">Changes</div>
      <div class="file-col file-col-findings">Findings</div>
      <div class="file-col file-col-metric">CC</div>
      <div class="file-col file-col-metric">MI</div>
    </div>
    <div class="files-scroll" id="filesScroll">
      <div id="filesRows"></div>
    </div>
  </div>
</div>
<script>
  {!! $wrapperSeverityJs !!}
  const filesNodes = {!! $wrapperNodesJson !!};
  const filesAnalysis = {!! $wrapperAnalysisJson !!};
  const filesMetrics = {!! $wrapperMetricsJson !!};

  const layouts = {
    {!! $jsLayoutData !!}
  };

  function show(name) {
    document.querySelectorAll('.tab[data-layout]').forEach(t => t.classList.toggle('active', t.dataset.layout === name));
    document.getElementById('view').srcdoc = atob(layouts[name]);
  }

  document.querySelectorAll('.tab[data-layout]').forEach(t => t.addEventListener('click', () => show(t.dataset.layout)));

  function signalColor(score) {
    if (score >= 60) return '#f85149';
    if (score >= 30) return '#d29922';
    if (score >= 10) return '#58a6ff';
    return '#3fb950';
  }

  var statusStyles = {
    added: ['#0d3520', '#3fb950', '#238636', 'New'],
    deleted: ['#3d1214', '#f85149', '#da3633', 'Deleted'],
    renamed: ['#1c1d4e', '#a5b4fc', '#6366f1', 'Renamed'],
    modified: ['#2d1c00', '#d29922', '#9e6a03', 'Modified'],
  };

  // ── Build file list ──
  var reviewedFiles = new Set();
  var showReviewed = false;
  var changedFiles = filesNodes.filter(function(n) { return !n.isConnected; });
  changedFiles.forEach(function(n) { n._signal = n._signal || 0; });

  var currentSort = 'signal';
  var currentDir = -1; // descending

  function sortFiles(col) {
    if (col === currentSort) { currentDir *= -1; }
    else { currentSort = col; currentDir = (col === 'name' || col === 'mi') ? 1 : -1; }
    renderFileList();
  }

  function getSort(n) {
    switch (currentSort) {
      case 'signal': return n._signal;
      case 'severity': return (n.veryHighCount * 10000) + (n.highCount * 1000) + (n.mediumCount * 100) + (n.lowCount * 10) + n.infoCount;
      case 'changes': return n.add + n.del;
      case 'name': return n.id.toLowerCase();
      case 'cc': var m = filesMetrics[n.path]; return m && m.cc != null ? m.cc : -1;
      case 'mi': var m = filesMetrics[n.path]; return m && m.mi != null ? m.mi : 999;
      case 'status': return n.status;
      default: return 0;
    }
  }

  function buildSignalTooltip(n, m) {
    var rows = '';
    var sevs = [['very_high', n.veryHighCount], ['high', n.highCount], ['medium', n.mediumCount], ['low', n.lowCount], ['info', n.infoCount]];
    for (var i = 0; i < sevs.length; i++) {
      var sev = sevs[i][0], count = sevs[i][1];
      if (!count) continue;
      var pts = count * (sevScores[sev] || 0);
      rows += '<div class="signal-tooltip-row">'
        + '<span class="label"><span style="width:6px;height:6px;border-radius:50%;background:' + sevColors[sev] + ';display:inline-block;flex-shrink:0"></span>' + sevLabels[sev] + ' &times; ' + count + '</span>'
        + '<span class="val">+' + pts + '</span>'
        + '</div>';
    }
    var changePts = Math.round(Math.sqrt(n.add + n.del) * 2);
    if (changePts) {
      rows += '<div class="signal-tooltip-row"><span class="label">Changes</span><span class="val">+' + changePts + '</span></div>';
    }
    if (m) {
      if ((m.cc || 0) > 10) {
        rows += '<div class="signal-tooltip-row"><span class="label">Complexity (CC)</span><span class="val">+' + Math.round((m.cc - 10) * 2) + '</span></div>';
      }
      if (m.mi != null && m.mi < 65) {
        rows += '<div class="signal-tooltip-row"><span class="label">Maintainability (MI)</span><span class="val">+' + Math.round((65 - m.mi) * 0.5) + '</span></div>';
      }
    }
    if (!rows) return '';
    return '<div class="signal-tooltip">' + rows + '</div>';
  }

  function renderFileList() {
    var filter = (document.getElementById('filesSearch').value || '').toLowerCase();
    var list = changedFiles.filter(function(n) {
      if (!showReviewed && reviewedFiles.has(n.id)) return false;
      if (!filter) return true;
      return n.id.toLowerCase().indexOf(filter) !== -1 || n.path.toLowerCase().indexOf(filter) !== -1;
    });

    list.sort(function(a, b) {
      var va = getSort(a), vb = getSort(b);
      if (typeof va === 'string') return currentDir * va.localeCompare(vb);
      return currentDir * (va - vb);
    });

    document.getElementById('filesSort').value = currentSort;

    var html = '';
    for (var i = 0; i < list.length; i++) {
      var n = list[i];
      var m = filesMetrics[n.path] || {};
      var st = statusStyles[n.status] || statusStyles.modified;
      var rc = signalColor(n._signal);

      var sevHtml = '';
      if (n.veryHighCount > 0) sevHtml += '<span class="file-sev-dot"><span style="background:' + sevColors.very_high + '"></span>' + n.veryHighCount + '</span>';
      if (n.highCount > 0) sevHtml += '<span class="file-sev-dot"><span style="background:' + sevColors.high + '"></span>' + n.highCount + '</span>';
      if (n.mediumCount > 0) sevHtml += '<span class="file-sev-dot"><span style="background:' + sevColors.medium + '"></span>' + n.mediumCount + '</span>';
      if (n.lowCount > 0) sevHtml += '<span class="file-sev-dot"><span style="background:' + sevColors.low + '"></span>' + n.lowCount + '</span>';
      if (!sevHtml) sevHtml = '<span style="color:#484f58">&mdash;</span>';

      var ccColor = m.cc != null ? (m.cc >= 20 ? '#f85149' : m.cc >= 10 ? '#d29922' : '#c9d1d9') : '#484f58';
      var miColor = m.mi != null ? (m.mi < 65 ? '#f85149' : m.mi < 85 ? '#d29922' : '#c9d1d9') : '#484f58';

      var isReviewed = reviewedFiles.has(n.id);
      var b = m.before || null;
      var ccArrow = '', miArrow = '';
      if (b != null) {
        if (b.cc != null && m.cc != null) {
          var ccDelta = m.cc - b.cc;
          ccArrow = ccDelta > 0 ? '<span style="color:#f85149;font-size:10px;margin-left:3px">\u2191</span>'
                  : ccDelta < 0 ? '<span style="color:#3fb950;font-size:10px;margin-left:3px">\u2193</span>'
                  : '<span style="color:#484f58;font-size:10px;margin-left:3px">\u2192</span>';
        }
        if (b.mi != null && m.mi != null) {
          var miDelta = m.mi - b.mi;
          miArrow = miDelta > 0 ? '<span style="color:#3fb950;font-size:10px;margin-left:3px">\u2191</span>'
                  : miDelta < 0 ? '<span style="color:#f85149;font-size:10px;margin-left:3px">\u2193</span>'
                  : '<span style="color:#484f58;font-size:10px;margin-left:3px">\u2192</span>';
        }
      }
      html += '<div class="file-row" data-node-id="' + n.id.replace(/"/g, '&quot;') + '">' +
        '<div class="file-col file-col-review"><button class="file-review-btn' + (isReviewed ? ' reviewed' : '') + '" data-node-id="' + n.id.replace(/"/g, '&quot;') + '" title="' + (isReviewed ? 'Unmark reviewed' : 'Mark as reviewed') + '">' + (isReviewed ? '&#10003;' : '&#9744;') + '</button></div>' +
        '<div class="file-col file-col-signal"><span class="file-col-label">Signal</span><span class="file-col-val" style="color:' + rc + '">' + n._signal + '</span>' + buildSignalTooltip(n, filesMetrics[n.path] || null) + '</div>' +
        '<div class="file-col file-col-name">' +
          '<div class="file-name-main"><span class="file-domain-dot" style="background:' + (n.domainColor || '#8b949e') + '"></span>' + n.id.replace(/</g, '&lt;') + '</div>' +
          '<div class="file-name-path">' + n.path.replace(/</g, '&lt;') + '</div>' +
        '</div>' +
        '<div class="file-col file-col-status"><span class="file-col-label">Status</span><span class="file-col-val" style="color:' + st[1] + '">' + st[3] + '</span></div>' +
        '<div class="file-col file-col-changes"><span class="file-col-label">Changes</span><span class="file-changes"><span class="add">+' + n.add + '</span> <span class="del">&minus;' + n.del + '</span></span></div>' +
        '<div class="file-col file-col-findings"><span class="file-col-label">Findings</span><span class="file-sev">' + sevHtml + '</span></div>' +
        '<div class="file-col file-col-metric"><span class="file-col-label">CC</span><span class="file-col-val" style="color:' + ccColor + '">' + (m.cc != null ? m.cc : '&mdash;') + ccArrow + '</span></div>' +
        '<div class="file-col file-col-metric"><span class="file-col-label">MI</span><span class="file-col-val" style="color:' + miColor + '">' + (m.mi != null ? Math.round(m.mi) + '%' : '&mdash;') + miArrow + '</span></div>' +
        '</div>';
    }
    document.getElementById('filesRows').innerHTML = html;
  }

  // ── Show/hide reviewed ──
  function updateReviewedBadge() {
    var count = reviewedFiles.size;
    var badge = document.getElementById('reviewedBadge');
    var btn = document.getElementById('showReviewedBtn');
    badge.textContent = count;
    badge.style.display = count > 0 ? 'inline' : 'none';
    document.getElementById('showReviewedLabel').textContent = showReviewed ? 'Hide reviewed' : 'Show reviewed';
    btn.style.color = showReviewed ? '#3fb950' : '#8b949e';
    btn.style.borderColor = showReviewed ? '#238636' : '#30363d';
    btn.style.background = showReviewed ? '#0d3520' : 'none';
  }

  function toggleShowReviewed() {
    showReviewed = !showReviewed;
    updateReviewedBadge();
    renderFileList();
  }

  // ── Review from file list ──
  function toggleReviewFromRow(nodeId) {
    var wasReviewed = reviewedFiles.has(nodeId);
    if (wasReviewed) {
      reviewedFiles.delete(nodeId);
      document.getElementById('view').contentWindow.postMessage({ type: 'unmarkFileReviewed', nodeId: nodeId }, '*');
    } else {
      reviewedFiles.add(nodeId);
      document.getElementById('view').contentWindow.postMessage({ type: 'markFileReviewed', nodeId: nodeId }, '*');
    }
    updateReviewedBadge();
    renderFileList();
  }

  // ── Toggle panel ──
  function toggleFilesPanel() {
    var panel = document.getElementById('filesPanel');
    var isOpen = panel.classList.contains('open');
    if (isOpen) {
      panel.classList.remove('open');
      document.getElementById('filesTab').classList.remove('active');
    } else {
      openedFromFiles = false;
      var iframe = document.getElementById('view');
      iframe.contentWindow.postMessage({ type: 'closePanel' }, '*');
      panel.classList.add('open');
      document.getElementById('filesTab').classList.add('active');
      renderFileList();
    }
  }

  // ── Wire events ──
  document.getElementById('filesSearch').addEventListener('input', renderFileList);
  document.getElementById('filesSort').addEventListener('change', function() {
    currentSort = this.value;
    currentDir = (currentSort === 'name' || currentSort === 'mi') ? 1 : -1;
    renderFileList();
  });
  var openedFromFiles = false;

  document.getElementById('filesRows').addEventListener('click', function(e) {
    var btn = e.target.closest('.file-review-btn');
    if (btn) {
      e.stopPropagation();
      toggleReviewFromRow(btn.dataset.nodeId);
      return;
    }
    var row = e.target.closest('.file-row');
    if (!row) return;
    var nodeId = row.dataset.nodeId;
    openedFromFiles = true;
    toggleFilesPanel();
    var iframe = document.getElementById('view');
    iframe.contentWindow.postMessage({ type: 'openFile', nodeId: nodeId, fromFiles: true }, '*');
  });

  window.addEventListener('message', function(e) {
    if (!e.data) return;
    if (e.data.type === 'panelClosed' && openedFromFiles) {
      openedFromFiles = false;
      if (!filesPanelEl.classList.contains('open')) toggleFilesPanel();
    }
    if (e.data.type === 'backToFiles') {
      openedFromFiles = false;
      var iframe = document.getElementById('view');
      iframe.contentWindow.postMessage({ type: 'closePanel' }, '*');
      if (!filesPanelEl.classList.contains('open')) toggleFilesPanel();
    }
    if (e.data.type === 'panelOpened' && filesPanelEl.classList.contains('open')) {
      filesPanelEl.classList.remove('open');
      document.getElementById('filesTab').classList.remove('active');
    }
    if (e.data.type === 'showReviewedChanged') {
      showReviewed = e.data.show;
      if (filesPanelEl.classList.contains('open')) renderFileList();
    }
    if (e.data.type === 'fileReviewed') {
      reviewedFiles.add(e.data.nodeId);
      updateReviewedBadge();
      if (filesPanelEl.classList.contains('open')) renderFileList();
    }
    if (e.data.type === 'fileUnreviewed') {
      reviewedFiles.delete(e.data.nodeId);
      updateReviewedBadge();
      if (filesPanelEl.classList.contains('open')) renderFileList();
    }
  });

  // ── Panel resize ──
  var filesPanelEl = document.getElementById('filesPanel');
  var filesPanelHandle = document.getElementById('filesPanelResize');
  var filesPanelResizing = false;
  var filesPanelWidth = 530;
  filesPanelEl.style.width = filesPanelWidth + 'px';

  function updatePanelBreakpoints() {
    filesPanelEl.classList.toggle('very-narrow', filesPanelWidth < 400);
    filesPanelEl.classList.toggle('narrow', filesPanelWidth >= 400 && filesPanelWidth < 520);
  }
  updatePanelBreakpoints();

  var iframeEl = document.getElementById('view');

  // Overlay to capture all mouse events during resize
  var resizeOverlay = document.createElement('div');
  resizeOverlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;cursor:col-resize;display:none';
  document.body.appendChild(resizeOverlay);

  filesPanelHandle.addEventListener('mousedown', function(e) {
    e.preventDefault();
    filesPanelResizing = true;
    filesPanelHandle.classList.add('active');
    filesPanelEl.style.transition = 'none';
    resizeOverlay.style.display = 'block';
  });
  document.addEventListener('mousemove', function(e) {
    if (!filesPanelResizing) return;
    var contentArea = document.querySelector('.content-area');
    var rect = contentArea.getBoundingClientRect();
    var newW = rect.right - e.clientX;
    newW = Math.max(340, Math.min(newW, rect.width * 0.9));
    filesPanelWidth = newW;
    filesPanelEl.style.width = filesPanelWidth + 'px';
    updatePanelBreakpoints();
  });
  document.addEventListener('mouseup', function() {
    if (!filesPanelResizing) return;
    filesPanelResizing = false;
    filesPanelHandle.classList.remove('active');
    filesPanelEl.style.transition = '';
    resizeOverlay.style.display = 'none';
  });

  show('force');
</script>
</body>
</html>
