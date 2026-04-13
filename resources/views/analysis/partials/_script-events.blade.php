// Helper: scroll to a line in the diff, switching to full-file view first if needed.
function scrollToDiffLine(line) {
  if (!document.querySelector('.diff-table tr[data-new-ln="' + line + '"]')) {
    var fullBtn = document.querySelector('.diff-view-btn[data-view="full"]');
    if (fullBtn) fullBtn.click();
  }
  scrollToDiffRow(findDiffRowByLine(line));
}

// Breadcrumb click: navigate back to a prior node in the stack.
document.getElementById('panel-breadcrumbs').addEventListener('click', function(e) {
  var item = e.target.closest('.bc-item:not(.bc-current)');
  if (!item) return;
  var idx = parseInt(item.getAttribute('data-bc-index'), 10);
  if (isNaN(idx) || idx < 0 || idx >= navStack.length) return;
  navStack = navStack.slice(0, idx + 1);
  navSkipPush = true;
  openPanel(navStack[idx].node);
  navSkipPush = false;
  renderBreadcrumbs();
});

// Global click delegation: inline method-call links, caller badges, and caller popup.
document.addEventListener('click', function(e) {
  var popup = document.getElementById('caller-popup');

  // "+N more" button: show popup listing all callers
  var moreBtn = e.target.closest('.callers-more-btn');
  if (moreBtn) {
    var badge = moreBtn.closest('.caller-badge');
    var all = badge ? JSON.parse(badge.dataset.allCallers || '[]') : [];
    if (popup && all.length) {
      popup.innerHTML = '<div class="popup-title">Called by</div>' +
        all.map(function(c) {
          var lineAttr = c.line ? ' data-call-line="' + c.line + '"' : '';
          return '<span class="caller-link" data-node-id="' + escapeHtml(c.id) + '"' + lineAttr + '>' + escapeHtml(c.label) + '</span>';
        }).join('');
      var rect = moreBtn.getBoundingClientRect();
      popup.style.top = (rect.bottom + 4) + 'px';
      popup.style.left = rect.left + 'px';
      popup.style.display = 'block';
    }
    e.stopPropagation();
    return;
  }

  // Close popup when clicking outside it
  if (popup && popup.style.display === 'block' && !e.target.closest('#caller-popup')) {
    popup.style.display = 'none';
  }

  var link = e.target.closest('.method-call-link, .caller-link');
  if (!link) return;
  e.stopPropagation();
  if (popup) popup.style.display = 'none';

  var nodeId = link.getAttribute('data-node-id');
  var nd = nodeMap[nodeId];
  if (!nd) return;

  // Helper: show the implementations picker popup anchored to a clicked element.
  function showImplsPopup(impls, anchor) {
    if (!popup) return;
    popup.innerHTML = '<div class="popup-title">Implementations</div>' +
      impls.map(function(impl) {
        var implNode = nodeMap[impl.nodeId];
        return '<span class="caller-link" data-node-id="' + escapeHtml(impl.nodeId) + '">' + escapeHtml((implNode && implNode.displayLabel) || impl.nodeId) + '</span>';
      }).join('');
    var rect = anchor.getBoundingClientRect();
    popup.style.top = (rect.bottom + 4) + 'px';
    popup.style.left = rect.left + 'px';
    popup.style.display = 'block';
  }

  // Same-file click → either scroll to a method definition or (for an interface
  // declaration) fall through to the implementorsIndex picker below.
  // Must come before the interface picker so $this->ownMethod() never opens it.
  if (link.classList.contains('method-call-link') && selectedNode && nodeId === selectedNode.id) {
    var methodName = link.getAttribute('data-method-name') || link.textContent.trim();
    var mm = metricsData[selectedNode.path] && metricsData[selectedNode.path].method_metrics;
    if (mm) {
      var mth = mm.find(function(x) { return x.name === methodName; });
      if (mth && mth.line) { scrollToDiffLine(mth.line); return; }
    }
    // Not a scrollable method — only continue if it's an interface with implementations
    // (handled by implementorsIndex below). Otherwise bail to avoid a pointless re-render.
    if (!implementorsIndex[nodeId]) return;
  }

  // Link points directly to an interface → show picker (or navigate if only 1 impl).
  var impls = implementorsIndex[nodeId];
  if (impls && impls.length > 1) { showImplsPopup(impls, link); return; }
  if (impls && impls.length === 1) { autoRevealAndOpen(nodeMap[impls[0].nodeId]); return; }

  // Link points to a concrete class via a method call: if that class implements an
  // interface that has multiple implementations, show the picker instead of jumping straight in.
  // Scoped to method calls only (data-method-name present) — class references like type hints
  // and `new Foo()` should navigate directly to the clicked class, not open a picker.
  if (link.classList.contains('method-call-link') && link.hasAttribute('data-method-name')) {
    var implementedIfaces = implementeeIndex[nodeId];
    if (implementedIfaces) {
      for (var ii = 0; ii < implementedIfaces.length; ii++) {
        var ifaceImpls = implementorsIndex[implementedIfaces[ii]];
        if (ifaceImpls && ifaceImpls.length > 1) { showImplsPopup(ifaceImpls, link); return; }
      }
    }
  }

  // Cross-file link → open the target panel, then scroll to the call line if known
  var callLine = link.getAttribute('data-call-line');
  autoRevealAndOpen(nd);
  if (callLine) scrollToDiffLine(parseInt(callLine, 10));
});

var openedFromFiles = false;

window.addEventListener('message', function(e) {
  if (!e.data) return;
  if (e.data.type === 'openFile') {
    var n = nodeMap[e.data.nodeId];
    if (!n) return;
    openedFromFiles = !!e.data.fromFiles;
    clearNavStack();
    openPanel(n);
    if (e.data.fromFindings) {
      // Switch to full-file view first (if content is available), then scroll to the finding
      var fullBtn = document.querySelector('.diff-view-btn[data-view="full"]');
      if (fullBtn && !fullBtn.classList.contains('active')) fullBtn.click();
      if (e.data.targetLine) {
        scrollToDiffRow(findDiffRowByLine(e.data.targetLine));
      } else if (e.data.targetLocation) {
        scrollToDiffRow(findDiffRowByLocation(e.data.targetLocation));
      }
    }
  }
  if (e.data.type === 'closePanel') {
    closePanel();
  }
  if (e.data.type === 'highlightCycle') {
    externalHighlightNodes = new Set((e.data.nodeIds || []).map(function(id) { return nodeMap[id]; }).filter(Boolean));
  }
  if (e.data.type === 'clearHighlight') {
    externalHighlightNodes = new Set();
  }
  if (e.data.type === 'markFileReviewed') {
    var n = nodeMap[e.data.nodeId];
    if (n) { reviewedNodes.add(n.id); updateReviewedCount(); clearHidden(); }
  }
  if (e.data.type === 'unmarkFileReviewed') {
    var n = nodeMap[e.data.nodeId];
    if (n) { reviewedNodes.delete(n.id); updateReviewedCount(); clearHidden(); }
  }
  if (e.data.type === 'applyFilters') {
    var s = e.data.state;
    hideConnected = s.hideConnected;
    showBridges = s.showBridges || false;
    hiddenExts = s.hiddenExts;
    hiddenDomains = s.hiddenDomains;
    hiddenSeverities = s.hiddenSeverities;
    hiddenChangeTypes = s.hiddenChangeTypes;
    hideReviewed = s.hideReviewed;
    if (s.reviewedNodes) {
      reviewedNodes = new Set(s.reviewedNodes);
      updateReviewedCount();
    }
    var toggleConnectedEl = document.getElementById('toggleConnected');
    if (toggleConnectedEl) toggleConnectedEl.checked = !hideConnected;
    var toggleBridgesEl2 = document.getElementById('toggleBridges');
    if (toggleBridgesEl2) toggleBridgesEl2.checked = showBridges;
    var toggleReviewedEl = document.getElementById('toggleReviewed');
    if (toggleReviewedEl) toggleReviewedEl.checked = !hideReviewed;
    document.querySelectorAll('.ext-toggle').forEach(function(cb) {
      cb.checked = !hiddenExts[cb.dataset.ext];
    });
    document.querySelectorAll('.domain-toggle').forEach(function(cb) {
      cb.checked = !hiddenDomains[cb.dataset.domain];
    });
    document.querySelectorAll('.change-type-toggle').forEach(function(cb) {
      cb.checked = !hiddenChangeTypes[cb.dataset.changeType];
    });
    document.querySelectorAll('.severity-toggle').forEach(function(cb) {
      cb.checked = !hiddenSeverities[cb.dataset.severity];
    });
    clearHidden();
  }
});

document.getElementById('panel-header').addEventListener('click', function(e) {
  var btn = e.target.closest('[data-open-cycles]');
  if (btn && window.parent !== window) {
    window.parent.postMessage({ type: 'openCyclesPanel', nodeId: btn.dataset.openCycles }, '*');
  }
});

function closeLegend() {
  var body = document.getElementById('legendBody');
  var chevron = document.getElementById('legendChevron');
  if (body.style.display !== 'none') {
    body.style.display = 'none';
    chevron.classList.add('collapsed');
  }
}
function toggleLegend() {
  var body = document.getElementById('legendBody');
  var chevron = document.getElementById('legendChevron');
  var collapsed = body.style.display === 'none';
  body.style.display = collapsed ? '' : 'none';
  chevron.classList.toggle('collapsed', !collapsed);
}
