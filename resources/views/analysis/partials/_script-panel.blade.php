// ── Method node panel ─────────────────────────────────────────────────────────
function renderMethodPanel(n) {
  selectedNode = n;
  var lineCount = (n.endLine && n.line) ? (n.endLine - n.line + 1) : '?';
  var ccColor = n.cc > 10 ? '#f85149' : n.cc > 5 ? '#d29922' : '#3fb950';
  var visColors = {public: '#3fb950', protected: '#d29922', private: '#8957e5'};
  var visColor = visColors[n.visibility] || '#8b949e';

  // Header
  document.getElementById('panel-header').innerHTML =
    '<div class="file-name">' + escapeHtml(n.displayLabel || n.name || n.id) + '()</div>' +
    '<div class="file-path">' + escapeHtml(n.class || '') + ' &middot; ' + escapeHtml(n.file || n.path || '') + (n.line ? ':' + n.line : '') + '</div>' +
    '<div class="badge-row">' +
      '<span class="badge" style="color:' + visColor + ';background:' + visColor + '22;border-color:' + visColor + '55">' + (n.visibility || 'public') + '</span>' +
      '<span class="badge" style="color:' + ccColor + ';background:' + ccColor + '22;border-color:' + ccColor + '55">CC ' + n.cc + '</span>' +
      '<span class="badge">' + lineCount + ' lines</span>' +
      (n.params > 0 ? '<span class="badge">' + n.params + ' param' + (n.params !== 1 ? 's' : '') + '</span>' : '') +
    '</div>';

  // Actions
  var ghSvg = '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>';
  var fileUrl = REPO && n.file ? 'https://github.com/' + REPO + '/blob/' + HEAD_COMMIT + '/' + n.file + (n.line ? '#L' + n.line : '') : '';
  document.getElementById('panel-actions').innerHTML =
    (fileUrl ? '<a class="btn btn-secondary" href="' + fileUrl + '" target="_blank" rel="noopener">' + ghSvg + ' View on GitHub</a>' : '');

  // Bar: CC indicator
  var ccPct = Math.min(100, Math.max(4, (n.cc / 15) * 100));
  document.getElementById('panel-bar').innerHTML =
    '<div class="change-bar"><div style="width:' + ccPct + '%;height:100%;background:' + ccColor + ';border-radius:2px"></div></div>' +
    '<div class="change-bar-label"><span style="color:' + ccColor + '">Cyclomatic complexity: ' + n.cc + '</span><span>' + lineCount + ' lines</span></div>';

  // Callers / callees (computed first so callLineMap is ready for code rendering)
  var calleesLinks = links.filter(function(l) { return isLinkVisible(l) && l.source === n; });
  var callersLinks = links.filter(function(l) { return isLinkVisible(l) && l.target === n; });

  // line number → [target nodes] for clickable code rows
  var callLineMap = {};
  calleesLinks.forEach(function(l) {
    if (l.callLine) {
      if (!callLineMap[l.callLine]) callLineMap[l.callLine] = [];
      callLineMap[l.callLine].push(l.target);
    }
  });

  // method name → node id for inline method-call-link highlighting
  var methodLinkMap = {};
  calleesLinks.forEach(function(l) {
    var name = l.target.name || (l.target.id ? l.target.id.split('::').pop() : null);
    if (name && !methodLinkMap[name]) methodLinkMap[name] = l.target.id;
  });

  // Body
  var bodyHtml = '';

  // Metric cards
  bodyHtml += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px">';
  function metCard(val, label, color) {
    return '<div style="background:#161b22;border:1px solid #30363d;border-radius:6px;padding:10px;text-align:center">' +
      '<div style="font-size:22px;font-weight:bold;color:' + color + '">' + val + '</div>' +
      '<div style="font-size:11px;color:#8b949e;margin-top:2px">' + label + '</div></div>';
  }
  bodyHtml += metCard(n.cc, 'Cyclomatic Complexity', ccColor);
  bodyHtml += metCard(n.params, 'Parameters', '#c9d1d9');
  bodyHtml += metCard(lineCount, 'Lines', '#c9d1d9');
  bodyHtml += '</div>';

  // Source code with clickable call lines
  if (n.code) {
    var codeLines = n.code.split('\n');
    var codeRows = codeLines.map(function(line, i) {
      var ln = (n.line || 1) + i;
      var targets = callLineMap[ln];
      var rowAttrs = targets
        ? ' class="code-call-row" data-call-targets="' + targets.map(function(t) { return escapeHtml(t.id); }).join(',') + '" style="cursor:pointer;background:#0d3520"'
        : '';
      var indicator = targets
        ? ' <span style="float:right;font-size:10px;color:#58a6ff;font-family:sans-serif">→ ' +
            targets.map(function(t) { return escapeHtml(t.displayLabel || t.name || t.id); }).join(', ') +
          '</span>'
        : '';
      return '<tr' + rowAttrs + '><td class="diff-ln">' + ln + '</td>' +
        '<td class="diff-code diff-ctx">' + highlightPHP(line, methodLinkMap, classNameIndex) + indicator + '</td></tr>';
    }).join('');
    bodyHtml += '<div class="diff-section"><h4>Source</h4>' +
      '<table class="diff-table unified" style="font-size:12px">' + codeRows + '</table></div>';
  }

  function connItem(nd) {
    var col = nd.domainColor || groupColor[nd.group] || '#8b949e';
    return '<div class="dep-item" data-id="' + escapeHtml(nd.id) + '" style="cursor:pointer;display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #21262d">' +
      '<span style="width:8px;height:8px;border-radius:50%;background:' + col + ';flex-shrink:0;display:inline-block"></span>' +
      '<span style="font-size:12px;color:#c9d1d9">' + escapeHtml(nd.displayLabel || nd.id) + '</span>' +
      '<span style="font-size:11px;color:#8b949e;margin-left:auto">' + escapeHtml(nd.folder || nd.class || '') + '</span>' +
    '</div>';
  }

  if (callersLinks.length > 0) {
    bodyHtml += '<div class="dep-section"><h4>Called by (' + callersLinks.length + ')</h4>';
    callersLinks.forEach(function(l) { bodyHtml += connItem(l.source); });
    bodyHtml += '</div>';
  }

  document.getElementById('panel-body').innerHTML = bodyHtml;
  document.getElementById('complexity-scroll-btn').classList.remove('visible');
  document.getElementById('panel-body').onscroll = null;

  // Wire call-line row clicks (navigate to callee panel)
  document.querySelectorAll('.code-call-row').forEach(function(row) {
    row.addEventListener('mouseenter', function() { row.style.background = '#1a4a2e'; });
    row.addEventListener('mouseleave', function() { row.style.background = '#0d3520'; });
    row.addEventListener('click', function() {
      var ids = row.getAttribute('data-call-targets').split(',');
      var nd = nodeMap[ids[0]];
      if (nd) openPanel(nd);
    });
  });

  // Wire dep-item clicks (called-by list)
  document.querySelectorAll('.dep-item[data-id]').forEach(function(el) {
    el.addEventListener('click', function() {
      var nd = nodeMap[el.getAttribute('data-id')];
      if (nd) autoRevealAndOpen(nd);
    });
  });

  document.getElementById('panelBack').classList.toggle('visible', openedFromFiles);
  panel.classList.add('open');
  if (window.parent !== window) window.parent.postMessage({ type: 'panelOpened' }, '*');
}

// ── Panel ─────────────────────────────────────────────────────────────────────
// Returns a method-name → nodeId link map for a given file node.
// Starts from the global methodNameIndex but overrides with methods defined in
// this file, so $this->ownMethod() always resolves to the file itself even when
// another class registered the same name first in the global index.
function buildFileLinkMap(node) {
  var ownMM = metricsData[node.path] && metricsData[node.path].method_metrics;
  if (!ownMM || !ownMM.length) return methodNameIndex;
  var map = Object.assign({}, methodNameIndex);
  ownMM.forEach(function(m) { if (m.name) map[m.name] = node.id; });
  return map;
}

// ── Panel navigation breadcrumbs ──────────────────────────────────────────────
var navStack = [];      // [{node}] — history of panels opened via link-clicks
var navSkipPush = false; // set true when re-opening a node via breadcrumb click

function clearNavStack() {
  navStack = [];
  renderBreadcrumbs();
}

function renderBreadcrumbs() {
  var el = document.getElementById('panel-breadcrumbs');
  if (!el) return;
  if (navStack.length <= 1) { el.style.display = 'none'; el.innerHTML = ''; return; }
  el.style.display = 'flex';
  el.innerHTML = navStack.map(function(entry, i) {
    var label = entry.node.displayLabel || entry.node.name || entry.node.id;
    var isLast = i === navStack.length - 1;
    var sep = i < navStack.length - 1 ? '<span class="bc-sep">&rsaquo;</span>' : '';
    var cls = 'bc-item' + (isLast ? ' bc-current' : '');
    var attr = isLast ? '' : ' data-bc-index="' + i + '"';
    return '<span class="' + cls + '"' + attr + ' title="' + escapeHtml(label) + '">' + escapeHtml(label) + '</span>' + sep;
  }).join('');
}

function openPanel(n) {
  if (!navSkipPush) {
    if (navStack.length === 0 || navStack[navStack.length - 1].node !== n) {
      navStack.push({ node: n });
      renderBreadcrumbs();
    }
  }
  if (n.code !== undefined) { renderMethodPanel(n); return; }
  selectedNode = n;
  const ghFileUrl = PR_URL + '/files#diff-' + n.hash;
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
      (n.cycleId != null ? '<button class="badge" data-open-cycles="' + n.id.replace(/"/g,'&quot;') + '" style="background:' + hexAlpha(n.cycleColor,0.15) + ';color:' + n.cycleColor + ';border:1px solid ' + hexAlpha(n.cycleColor,0.5) + ';cursor:pointer;font-size:12px;font-weight:500;font-family:inherit;line-height:1.5;appearance:none">&#8635; Cycle ' + n.cycleId + '</button>' : '') +
    '</div>';

  var isReviewed = reviewedNodes.has(n.id);
  var reviewBtn = '<button class="btn ' + (isReviewed ? 'btn-reviewed' : 'btn-review') + '" id="reviewBtn">' +
    (isReviewed ? '&#10003; Reviewed' : '&#9744; Mark as reviewed') + '</button>';

  var ghSvg = '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>';
  if (n.isConnected) {
    var fileUrl = REPO ? 'https://github.com/' + REPO + '/blob/' + HEAD_COMMIT + '/' + n.path : '';
    document.getElementById('panel-actions').innerHTML =
      (fileUrl ? '<a class="btn btn-secondary" href="' + fileUrl + '" target="_blank" rel="noopener">' + ghSvg + 'View file on GitHub</a>' : '') +
      reviewBtn;
  } else {
    document.getElementById('panel-actions').innerHTML =
      (PR_URL ? '<a class="btn btn-primary" href="' + ghFileUrl + '" target="_blank" rel="noopener">' + ghSvg + 'View diff on GitHub</a>' : '') +
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
        deltaHtml = '<span style="font-size:11.5px;color:' + deltaColor + '">' + deltaDisplay + '</span>';
      }
      return '<div style="background:#21262d;border-radius:6px;padding:8px 10px">' +
        '<div style="font-size:11px;color:#6e7681;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:4px">' + label + '</div>' +
        '<div style="font-size:20px;font-weight:700;color:' + color + ';line-height:1;display:flex;align-items:baseline;gap:4px">' + display + deltaHtml + '</div>' +
        '</div>';
    }
    var b = m.before || {};
    var maxCc = null, avgCc = null, medianCc = null;
    var bMaxCc = null, bAvgCc = null, bMedianCc = null;
    if (m.method_metrics && m.method_metrics.length > 0) {
      var mmCcVals = m.method_metrics.map(function(mm) { return mm.cc; });
      maxCc = Math.max.apply(null, mmCcVals);
      avgCc = parseFloat((mmCcVals.reduce(function(a, c) { return a + c; }, 0) / mmCcVals.length).toFixed(1));
      var mmSorted = mmCcVals.slice().sort(function(a, c) { return a - c; });
      var mmMid = Math.floor(mmSorted.length / 2);
      medianCc = mmSorted.length % 2 !== 0 ? mmSorted[mmMid] : parseFloat(((mmSorted[mmMid - 1] + mmSorted[mmMid]) / 2).toFixed(1));
    }
    if (m.before_method_metrics && m.before_method_metrics.length > 0) {
      var bmCcVals = m.before_method_metrics.map(function(mm) { return mm.cc; });
      bMaxCc = Math.max.apply(null, bmCcVals);
      bAvgCc = parseFloat((bmCcVals.reduce(function(a, c) { return a + c; }, 0) / bmCcVals.length).toFixed(1));
      var bmSorted = bmCcVals.slice().sort(function(a, c) { return a - c; });
      var bmMid = Math.floor(bmSorted.length / 2);
      bMedianCc = bmSorted.length % 2 !== 0 ? bmSorted[bmMid] : parseFloat(((bmSorted[bmMid - 1] + bmSorted[bmMid]) / 2).toFixed(1));
    }
    var metricsLabel = /\.(js|ts|jsx|tsx|vue|mjs|cjs)$/.test(n.path) ? 'JS Metrics' : 'PHP Metrics';
    bodyHtml += '<div class="deps-section"><h4>' + metricsLabel + '</h4>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px">' +
      metricCard('Cyclomatic Complexity', m.cc, '', 10, 20, false, b.cc) +
      metricCard('Maintainability', m.mi != null ? Math.round(m.mi) : null, '%', 85, 65, true, b.mi != null ? Math.round(b.mi) : null) +
      metricCard('Est. Bugs', m.bugs != null ? m.bugs.toFixed(3) : null, '', 0.1, 0.5, false, b.bugs) +
      metricCard('Coupling (Ce)', m.coupling, '', 10, 20, false, b.coupling) +
      (m.lloc != null ? metricCard('Lines of Code', m.lloc, '', 200, 500, false, b.lloc) : '') +
      (m.methods != null ? metricCard('Methods', m.methods, '', 10, 20, false, b.methods) : '') +
      (maxCc != null ? metricCard('Max CC / Method', maxCc, '', 10, 20, false, bMaxCc) : '') +
      (avgCc != null && medianCc != null ? (function() {
        var avgColor = metricColor(avgCc, 10, 20);
        var medColor = metricColor(medianCc, 10, 20);
        var avgDelta = bAvgCc != null ? (function() {
          var d = avgCc - bAvgCc; if (d === 0) return '';
          return '<span style="color:' + (d > 0 ? '#f85149' : '#3fb950') + ';font-size:11.5px">' + (d > 0 ? '\u2191' : '\u2193') + Math.abs(d).toFixed(1).replace(/\.?0+$/, '') + '</span>';
        })() : '';
        var medDelta = bMedianCc != null ? (function() {
          var d = medianCc - bMedianCc; if (d === 0) return '';
          return '<span style="color:' + (d > 0 ? '#f85149' : '#3fb950') + ';font-size:11.5px">' + (d > 0 ? '\u2191' : '\u2193') + Math.abs(d).toFixed(1).replace(/\.?0+$/, '') + '</span>';
        })() : '';
        return '<div style="background:#21262d;border-radius:6px;padding:8px 10px">' +
          '<div style="font-size:11px;color:#6e7681;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:4px">Avg / Median CC</div>' +
          '<div style="display:flex;gap:10px;align-items:baseline">' +
          '<span style="font-size:20px;font-weight:700;color:' + avgColor + '">' + avgCc + avgDelta + '</span>' +
          '<span style="font-size:13px;color:#484f58">/</span>' +
          '<span style="font-size:20px;font-weight:700;color:' + medColor + '">' + medianCc + medDelta + '</span>' +
          '</div>' +
          '</div>';
      })() : '') +
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
      var beforeMethodMap = {};
      if (m.before_method_metrics) {
        for (var bmi = 0; bmi < m.before_method_metrics.length; bmi++) {
          beforeMethodMap[m.before_method_metrics[bmi].name] = m.before_method_metrics[bmi];
        }
      }
      var hasBefore = Object.keys(beforeMethodMap).length > 0;
      function methodDelta(val, beforeVal, higherIsBad) {
        if (beforeVal == null) return '';
        var diff = val - beforeVal;
        if (diff === 0) return '';
        var sign = diff > 0 ? '+' : '';
        var bad = higherIsBad ? diff > 0 : diff < 0;
        var color = bad ? '#f85149' : '#3fb950';
        return '<span style="color:' + color + ';font-size:10.5px;margin-left:3px">' + sign + diff + '</span>';
      }
      var methodStatuses = methodsSorted.map(function(mth) { return methodDiffStatus(mth); });
      var modifiedMethodCount = methodStatuses.filter(function(s) { return s !== null; }).length;
      var unmodifiedMethodCount = methodsSorted.length - modifiedMethodCount;
      bodyHtml += '<div id="methods-by-complexity" style="margin-top:10px">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">' +
        '<div style="font-size:11.5px;color:#6e7681;text-transform:uppercase;letter-spacing:0.4px">Methods by Complexity</div>' +
        (modifiedMethodCount > 0 ? '<button id="methods-filter-btn" style="font-size:11px;color:#8b949e;background:none;border:1px solid #30363d;border-radius:4px;padding:2px 7px;cursor:pointer;line-height:1.5">Modified only</button>' : '') +
        '</div>' +
        '<table style="width:100%;border-collapse:collapse;font-size:13px">' +
        '<thead><tr>' +
        '<th style="text-align:left;color:#6e7681;font-weight:500;padding:3px 8px 6px 0">Method</th>' +
        '<th style="text-align:right;color:#6e7681;font-weight:500;padding:3px 8px 6px 0">CC</th>' +
        '<th style="text-align:right;color:#6e7681;font-weight:500;padding:3px 8px 6px 0">Lines</th>' +
        '<th style="text-align:right;color:#6e7681;font-weight:500;padding:3px 0 6px 0">Params</th>' +
        '</tr></thead><tbody>';
      for (var mi2 = 0; mi2 < methodsSorted.length; mi2++) {
        var mth = methodsSorted[mi2];
        var bm = beforeMethodMap[mth.name] || null;
        var ccColor = mth.cc > 10 ? '#f85149' : mth.cc > 5 ? '#d29922' : '#3fb950';
        var hasLine = mth.line ? ' data-method-line="' + mth.line + '"' : '';
        var status = methodStatuses[mi2];
        var statusBadge = status === 'new'
          ? '<span style="color:#3fb950;font-size:10.5px;font-weight:500;margin-left:5px">new</span>'
          : status === 'modified'
          ? '<span style="color:#d29922;font-size:10.5px;font-weight:500;margin-left:5px">mod</span>'
          : '';
        var ccDelta = hasBefore ? (bm ? methodDelta(mth.cc, bm.cc, true) : (status === 'new' ? '' : '')) : '';
        var llocDelta = hasBefore ? (bm ? methodDelta(mth.lloc, bm.lloc, true) : '') : '';
        var paramsDelta = hasBefore ? (bm ? methodDelta(mth.params, bm.params, true) : '') : '';
        bodyHtml += '<tr' + hasLine + ' data-method-status="' + (status || 'unmodified') + '"' + (mth.line ? ' style="cursor:pointer"' : '') + '>' +
          '<td style="padding:4px 8px 4px 0;color:#c9d1d9;white-space:nowrap;max-width:180px;overflow:hidden;text-overflow:ellipsis" title="' + mth.name + '">' + mth.name + statusBadge + '</td>' +
          '<td style="padding:4px 8px 4px 0;text-align:right;color:' + ccColor + ';font-weight:600">' + mth.cc + ccDelta + '</td>' +
          '<td style="padding:4px 8px 4px 0;text-align:right;color:#8b949e">' + mth.lloc + llocDelta + '</td>' +
          '<td style="padding:4px 0;text-align:right;color:#8b949e">' + mth.params + paramsDelta + '</td>' +
          '</tr>';
      }
      bodyHtml += '</tbody></table>' +
        (unmodifiedMethodCount > 0 ? '<div id="methods-filter-hint" style="display:none;font-size:11.5px;color:#6e7681;margin-top:6px;cursor:pointer">+' + unmodifiedMethodCount + ' unedited method' + (unmodifiedMethodCount !== 1 ? 's' : '') + '</div>' : '') +
        '</div>';
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

  var depTypeLabel = { constructor_injection: 'injected', method_injection: 'method param', new_instance: 'new', container_resolved: 'app()', static_call: 'static', extends: 'extends', implements: 'implements', property_type: 'property', return_type: 'return type', use: 'use' };
  var depTypeBadgeColor = { constructor_injection: '#1f6feb', method_injection: '#388bfd', new_instance: '#d29922', container_resolved: '#8957e5', static_call: '#6e7681', extends: '#f78166', implements: '#ffa657', property_type: '#3fb950', return_type: '#79c0ff', use: '#30363d' };

  if (n.cycleId != null) {
    var cycleMembers = nodes.filter(function(m) { return m.cycleId === n.cycleId && m !== n; });
    bodyHtml += '<div class="deps-section" style="border-left:2px solid ' + hexAlpha(n.cycleColor,0.5) + ';margin-left:0;padding-left:22px">' +
      '<h4 style="color:' + n.cycleColor + ';display:flex;align-items:center;gap:6px">' +
        '<svg width="12" height="12" viewBox="0 0 16 16" fill="' + n.cycleColor + '"><path d="M8 2a6 6 0 1 0 5.659 8.006.75.75 0 0 1 1.414.494A7.5 7.5 0 1 1 14.5 6.32V5.25a.75.75 0 0 1 1.5 0v3a.75.75 0 0 1-.75.75h-3a.75.75 0 0 1 0-1.5h1.313A6.011 6.011 0 0 0 8 2Z"/></svg>' +
        'Cycle ' + n.cycleId +
      '</h4>' +
      '<p style="font-size:12px;color:#6e7681;margin-bottom:10px;line-height:1.5">This file is part of a dependency cycle with ' + cycleMembers.length + ' other file' + (cycleMembers.length !== 1 ? 's' : '') + '. Circular dependencies make code harder to test and reason about.</p>';
    for (var ci = 0; ci < cycleMembers.length; ci++) {
      var cm = cycleMembers[ci];
      // Find the edge(s) between n and cm to show direction
      var outLink = links.find(function(l) { return l.source === n && l.target === cm; });
      var inLink  = links.find(function(l) { return l.source === cm && l.target === n; });
      var dirLabel = outLink && inLink ? '&#8646; mutual'
                   : outLink ? '&#8594; depends on'
                   : '&#8592; used by';
      var dtBadge = '';
      var relevantLink = outLink || inLink;
      if (relevantLink) {
        var dtLabel = depTypeLabel[relevantLink.depType] || relevantLink.depType;
        dtBadge = '<span style="font-size:10px;padding:1px 5px;border-radius:3px;background:' + (depTypeBadgeColor[relevantLink.depType] || '#30363d') + ';color:#e6edf3;margin-left:6px;white-space:nowrap">' + dtLabel + '</span>';
      }
      bodyHtml += '<div class="dep-item" data-node-id="' + cm.id.replace(/"/g, '&quot;') + '">' +
        '<span class="dep-dot" style="background:' + cm.color + ';border:1.5px dashed ' + cm.cycleColor + ';box-sizing:content-box"></span>' +
        cm.id + dtBadge +
        '<span style="color:#6e7681;margin-left:auto;font-size:11px;white-space:nowrap">' + dirLabel + '</span>' +
        '</div>';
    }
    bodyHtml += '</div>';
  }

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
  var parsedDiff = parsedDiffs[n.path];
  if (parsedDiff) {
    var isPHP = n.path.endsWith('.php');
    var fullContent = fileContents[n.path];
    // Build a link map that lets $this->ownMethod() resolve to the current file.
    // The global methodNameIndex uses first-match, so a private/protected method
    // in this file may have lost to another class. Override with this file's own methods.
    var diffLinkMap = isPHP ? buildFileLinkMap(n) : null;
    var diffClassMap = isPHP ? classNameIndex : null;
    var tableRows;
    if (diffViewMode === 'split') tableRows = renderSplitDiff(parsedDiff, isPHP, diffLinkMap, diffClassMap, implementorsIndex);
    else if (diffViewMode === 'full' && fullContent) tableRows = renderFullFile(fullContent, parsedDiff, isPHP, diffLinkMap, diffClassMap, implementorsIndex);
    else tableRows = renderUnifiedDiff(parsedDiff, isPHP, diffLinkMap, diffClassMap, implementorsIndex);
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
  } else if (n.isConnected && fileContents[n.path]) {
    // Connected (non-diff) node: show full source so the user can read the bridging code.
    var connIsPHP = n.path.endsWith('.php');
    var connLinkMap = connIsPHP ? buildFileLinkMap(n) : null;
    var connClassMap = connIsPHP ? classNameIndex : null;
    var connRows = renderFullFile(fileContents[n.path], [], connIsPHP, connLinkMap, connClassMap, implementorsIndex);
    bodyHtml += '<div class="diff-section">' +
      '<h4>Source</h4>' +
      '<table class="diff-table full">' + connRows + '</table></div>';
  }

  document.getElementById('panel-body').innerHTML = bodyHtml;

  // Floating "Methods by Complexity" scroll button
  var complexityScrollBtn = document.getElementById('complexity-scroll-btn');
  var panelBodyEl = document.getElementById('panel-body');
  var complexitySection = document.getElementById('methods-by-complexity');
  if (complexitySection && complexityScrollBtn) {
    complexityScrollBtn.classList.remove('visible');
    panelBodyEl.onscroll = function() {
      var sectionBottom = complexitySection.getBoundingClientRect().bottom;
      var panelTop = panelBodyEl.getBoundingClientRect().top;
      complexityScrollBtn.classList.toggle('visible', sectionBottom < panelTop);
    };
  } else if (complexityScrollBtn) {
    complexityScrollBtn.classList.remove('visible');
    panelBodyEl.onscroll = null;
  }

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

  // Wire up "Methods by Complexity" rows to scroll to method in diff
  document.querySelectorAll('tr[data-method-line]').forEach(function(row) {
    var ln = row.getAttribute('data-method-line');
    var inDiff = !!document.querySelector('.diff-table tr[data-new-ln="' + ln + '"]');
    if (inDiff || fileContents[n.path]) {
      row.addEventListener('click', function() {
        if (!document.querySelector('.diff-table tr[data-new-ln="' + ln + '"]')) {
          // Line not visible in current view — switch to Full file first
          var fullBtn = document.querySelector('.diff-view-btn[data-view="full"]');
          if (fullBtn) fullBtn.click();
        }
        scrollToDiffRow(document.querySelector('.diff-table tr[data-new-ln="' + ln + '"]'));
      });
    } else {
      row.style.cursor = 'default';
      row.style.opacity = '0.5';
    }
  });

  // Wire up methods filter button
  var methodsFilterBtn = document.getElementById('methods-filter-btn');
  if (methodsFilterBtn) {
    function applyMethodFilter(active) {
      var hint = document.getElementById('methods-filter-hint');
      document.querySelectorAll('tr[data-method-status="unmodified"]').forEach(function(r) {
        r.style.display = active ? 'none' : '';
      });
      if (hint) hint.style.display = active ? 'block' : 'none';
      methodsFilterBtn.textContent = active ? 'All methods' : 'Modified only';
      methodsFilterBtn.style.color = active ? '#58a6ff' : '#8b949e';
      methodsFilterBtn.style.borderColor = active ? '#58a6ff' : '#30363d';
      methodsFilterBtn.dataset.active = active ? '1' : '0';
    }
    methodsFilterBtn.addEventListener('click', function() {
      applyMethodFilter(this.dataset.active !== '1');
    });
    var methodsFilterHint = document.getElementById('methods-filter-hint');
    if (methodsFilterHint) {
      methodsFilterHint.addEventListener('click', function() { applyMethodFilter(false); });
    }
  }

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

  // Inject "← caller" indicators at method definition lines.
  // Defined as a closure so the mode-switch handler can call it too.
  var placeCallerBadges = function() {
    if (!m || !m.method_metrics) return;
    m.method_metrics.forEach(function(mth) {
      if (!mth.line || !mth.name) return;
      var callers = callersIndex[n.id + ':' + mth.name];
      if (!callers || !callers.length) return;
      var row = document.querySelector('.diff-table tr[data-new-ln="' + mth.line + '"]');
      if (!row) return;
      var cell = row.querySelector('td:last-child');
      if (!cell || cell.querySelector('.caller-badge')) return;
      var badge = document.createElement('span');
      badge.className = 'caller-badge';
      var MAX_INLINE = 2;
      var shown = callers.slice(0, MAX_INLINE);
      var rest = callers.slice(MAX_INLINE);
      var linksHtml = shown.map(function(entry) {
        var nd = nodeMap[entry.nodeId];
        var lineAttr = entry.line ? ' data-call-line="' + entry.line + '"' : '';
        return '<span class="caller-link" data-node-id="' + escapeHtml(entry.nodeId) + '"' + lineAttr + '>' + escapeHtml((nd && nd.displayLabel) || entry.nodeId) + '</span>';
      }).join(' <span style="color:#484f58">·</span> ');
      var moreHtml = rest.length
        ? ' <span style="color:#484f58">·</span> <span class="callers-more-btn" style="color:#8b949e">+' + rest.length + ' more</span>'
        : '';
      badge.innerHTML = '<span style="color:#8b949e">← </span>' + linksHtml + moreHtml;
      badge.dataset.allCallers = JSON.stringify(callers.map(function(entry) { var nd = nodeMap[entry.nodeId]; return { id: entry.nodeId, label: (nd && nd.displayLabel) || entry.nodeId, line: entry.line }; }));
      cell.appendChild(badge);
    });
  };
  placeCallerBadges();

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
      var parsed = parsedDiffs[n.path];
      if (!parsed) return;
      var isPHP = n.path.endsWith('.php');
      var rows;
      var reLinkMap = isPHP ? buildFileLinkMap(n) : null;
      var reClassMap = isPHP ? classNameIndex : null;
      if (mode === 'split') rows = renderSplitDiff(parsed, isPHP, reLinkMap, reClassMap, implementorsIndex);
      else if (mode === 'full' && fileContents[n.path]) rows = renderFullFile(fileContents[n.path], parsed, isPHP, reLinkMap, reClassMap, implementorsIndex);
      else rows = renderUnifiedDiff(parsed, isPHP, reLinkMap, reClassMap, implementorsIndex);
      var table = document.querySelector('.diff-table');
      if (table) { table.className = 'diff-table ' + mode; table.innerHTML = rows; placeAnnotationDots(); placeCallerBadges(); }
    });
  });

  document.getElementById('panelBack').classList.toggle('visible', openedFromFiles);
  panel.classList.add('open');
  if (window.parent !== window) window.parent.postMessage({ type: 'panelOpened' }, '*');
}

function closePanel() {
  panel.classList.remove('open'); selectedNode = null; clearNavStack();
  if (openedFromFiles && window.parent !== window) {
    openedFromFiles = false;
    window.parent.postMessage({ type: 'panelClosed' }, '*');
  }
}
