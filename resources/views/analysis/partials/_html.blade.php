<body>
<canvas id="canvas"></canvas>
<div class="diff-annotation-tip" id="diffTip"></div>
<div id="caller-popup"></div>
<div class="tooltip" id="tooltip"></div>
<div id="metric-tooltip"></div>

<!-- ── Title bar ── -->
<div id="titleCard" class="fixed top-4 left-4 z-[5] max-w-[580px] bg-surface/95 border border-border-default/60 rounded-xl shadow-[0_8px_32px_rgba(0,0,0,.5),0_0_0_1px_rgba(255,255,255,.04)_inset] backdrop-blur-sm" style="font-size:14px">
  <div class="px-5 pt-4 pb-3">
    <div class="flex items-start gap-2.5 mb-1.5">
      @if($prUrl)
      <a href="{{ $prUrl }}" target="_blank" rel="noopener" class="flex-shrink-0 inline-flex items-center gap-1 bg-accent/10 text-accent border border-accent/25 rounded-lg px-2.5 py-0.5 text-xs font-semibold hover:bg-accent/15 transition-colors no-underline" style="letter-spacing:0.02em">
        <svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor" style="opacity:0.8"><path d="M7.177 3.073L9.573.677A.25.25 0 0110 .854v4.792a.25.25 0 01-.427.177L7.177 3.427a.25.25 0 010-.354zM3.75 2.5a.75.75 0 100 1.5.75.75 0 000-1.5zm-2.25.75a2.25 2.25 0 113 2.122v5.256a2.251 2.251 0 11-1.5 0V5.372A2.25 2.25 0 011.5 3.25zM11 2.5h-1V4h1a1 1 0 011 1v5.628a2.251 2.251 0 101.5 0V5A2.5 2.5 0 0011 2.5zm1 10.25a.75.75 0 111.5 0 .75.75 0 01-1.5 0zM3.75 12a.75.75 0 100 1.5.75.75 0 000-1.5z"/></svg>
        PR #{{ $prNumber }}
      </a>
      @endif
      <h2 class="text-fg font-semibold leading-snug" style="font-size:14.5px;letter-spacing:-0.01em">{{ $prTitle }}</h2>
    </div>
    <p class="text-fg-muted flex items-center flex-wrap gap-x-3 gap-y-0.5" style="font-size:12px">
      <span class="flex items-center gap-1">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor" style="opacity:0.6"><path d="M1.5 2.75a.75.75 0 01.75-.75h11.5a.75.75 0 010 1.5H2.25a.75.75 0 01-.75-.75zM1.5 8a.75.75 0 01.75-.75h11.5a.75.75 0 010 1.5H2.25A.75.75 0 011.5 8zm0 5.25a.75.75 0 01.75-.75h11.5a.75.75 0 010 1.5H2.25a.75.75 0 01-.75-.75z"/></svg>
        {{ $fileCount }} files
      </span>
      <span class="text-success">+{{ $prAdditions }}</span>
      <span class="text-danger">&minus;{{ $prDeletions }}</span>
      <span id="reviewedCount" class="text-success font-medium"></span>
      <span id="cycleCount" class="text-severe"></span>
    </p>
  </div>
  @if($layoutSwitcher)
  <div class="px-5 pb-3 flex gap-1.5 layout-switcher">{!! $layoutSwitcher !!}</div>
  @endif
</div>

<!-- ── Filters legend ── -->
<div class="legend fixed bottom-4 left-4 z-[5] bg-surface/95 border border-border-default/60 rounded-xl shadow-[0_8px_32px_rgba(0,0,0,.45),0_0_0_1px_rgba(255,255,255,.04)_inset] backdrop-blur-sm overflow-hidden" style="max-height:calc(100dvh - 120px);max-width:228px;font-size:12px">
  <div class="flex items-center justify-between px-4 py-2.5 cursor-pointer select-none" onclick="toggleLegend()">
    <span class="text-fg-subtle font-semibold tracking-widest" style="font-size:10px;text-transform:uppercase">Filters</span>
    <button class="legend-chevron collapsed bg-transparent border-0 p-0.5 cursor-pointer text-fg-subtle flex items-center transition-colors hover:text-fg" id="legendChevron">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
        <path d="M3 5L7 9L11 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </button>
  </div>
  <div class="legend-body overflow-y-auto" id="legendBody" style="display:none;max-height:calc(100dvh - 178px)">
    <div class="px-4 pb-4 flex flex-col gap-0">
      {!! $riskPanel !!}
      <p class="text-fg-muted leading-relaxed mb-3" style="font-size:11px">
        <span class="border-b-2 border-solid border-fg-subtle">Gray line</span> = dependency<br>
        <span class="border-b-2 border-solid border-severe">Orange ring</span> = circular dep<br>
        Size = lines changed &middot; Scroll to zoom
      </p>
      @if($kindTogglesHtml)
      <div class="mb-3 pt-1 border-t border-border-subtle mt-3">
        <p class="text-fg-subtle font-semibold tracking-widest mb-2 mt-3" style="font-size:10px;text-transform:uppercase">Constructs</p>
        <div class="flex flex-col gap-0.5">{!! $kindTogglesHtml !!}</div>
      </div>
      @endif

      <div class="flex flex-col gap-0.5">
        <div class="toggle-row">
          <label class="toggle"><input type="checkbox" id="toggleReviewed"><span class="slider"></span></label>
          <label class="toggle-label" for="toggleReviewed">Show reviewed <span id="reviewedToggleCount" style="color:#484f58">(0)</span></label>
        </div>
        @if(count(json_decode($affectedEndpointsJson, true)) > 0 || count(json_decode($affectedScheduledJobsJson, true)) > 0)
        <div class="toggle-row" id="onlyHighlightedRow">
          <label class="toggle"><input type="checkbox" id="toggleOnlyHighlighted"><span class="slider"></span></label>
          <label class="toggle-label" for="toggleOnlyHighlighted">Only related files</label>
        </div>
        @endif
        <div class="toggle-row">
          <label class="toggle"><input type="checkbox" id="toggleConnected"><span class="slider"></span></label>
          <label class="toggle-label" for="toggleConnected">Dependencies <span style="color:#484f58">({{ $connectedCount }})</span></label>
        </div>
        <div class="toggle-row" id="bridgesToggleRow" style="display:none">
          <label class="toggle"><input type="checkbox" id="toggleBridges"><span class="slider"></span></label>
          <label class="toggle-label" for="toggleBridges">Shared dependencies <span id="bridgeCount" style="color:#484f58"></span></label>
        </div>
      </div>

      <div class="mt-3 pt-3 border-t border-border-subtle">
        <p class="text-fg-subtle font-semibold tracking-widest mb-2" style="font-size:10px;text-transform:uppercase">Severity</p>
        <div class="flex flex-col gap-0.5">{!! $severityTogglesHtml !!}</div>
      </div>

      <div class="mt-3 pt-3 border-t border-border-subtle">
        <p class="text-fg-subtle font-semibold tracking-widest mb-2" style="font-size:10px;text-transform:uppercase">File types</p>
        <div class="flex flex-col gap-0.5">{!! $extTogglesHtml !!}</div>
      </div>

      <div class="mt-3 pt-3 border-t border-border-subtle">
        <p class="text-fg-subtle font-semibold tracking-widest mb-2" style="font-size:10px;text-transform:uppercase">Domains</p>
        <div class="flex flex-col gap-0.5">{!! $folderTogglesHtml !!}</div>
      </div>

      <div class="mt-3 pt-3 border-t border-border-subtle">
        <p class="text-fg-subtle font-semibold tracking-widest mb-2" style="font-size:10px;text-transform:uppercase">Changes</p>
        <div class="flex flex-col gap-0.5">
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
    </div>
  </div>
</div>

<!-- ── Affected endpoints panel ── -->
<div id="endpointsPanel" class="fixed top-4 right-4 z-[5] bg-surface/95 border border-border-default/60 rounded-xl shadow-[0_8px_32px_rgba(0,0,0,.5),0_0_0_1px_rgba(255,255,255,.04)_inset] backdrop-blur-sm" style="font-size:13px;max-width:340px;display:none">
  <div class="flex items-center justify-between px-4 py-3 cursor-pointer select-none" onclick="toggleEndpointsPanel()">
    <div class="flex items-center gap-2">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor" style="color:#ffa657;flex-shrink:0"><path d="M1.75 1A1.75 1.75 0 000 2.75v10.5C0 14.216.784 15 1.75 15h12.5A1.75 1.75 0 0016 13.25v-8.5A1.75 1.75 0 0014.25 3H7.5a.25.25 0 01-.2-.1l-.9-1.2A1.75 1.75 0 005 1H1.75z"/></svg>
      <span class="text-fg font-semibold" style="letter-spacing:-0.01em">Affected Endpoints</span>
      <span id="endpointsBadge" class="inline-flex items-center justify-center rounded-full font-semibold" style="background:#ffa657;color:#0d1117;font-size:10px;min-width:18px;height:18px;padding:0 5px"></span>
    </div>
    <button id="endpointsChevron" class="bg-transparent border-0 p-0.5 cursor-pointer text-fg-subtle flex items-center transition-colors hover:text-fg">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3 5L7 9L11 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
  </div>
  <div id="endpointsBody" style="display:none;max-height:calc(100dvh - 120px);overflow-y:auto">
    <div class="px-4 pb-4" id="endpointsList"></div>
  </div>
</div>

<script>
(function() {
  var methodColors = { GET:'#3fb950', POST:'#58a6ff', PUT:'#d29922', PATCH:'#d29922', DELETE:'#f85149', OPTIONS:'#8b949e', ANY:'#8b949e' };
  function renderEndpoints() {
    if (!window.affectedEndpoints || !affectedEndpoints.length) return;
    var panel = document.getElementById('endpointsPanel');
    var badge = document.getElementById('endpointsBadge');
    var list = document.getElementById('endpointsList');
    badge.textContent = affectedEndpoints.length;
    panel.style.display = '';
    var html = '';
    affectedEndpoints.forEach(function(ep, i) {
      var color = methodColors[ep.method] || '#8b949e';
      var mw = ep.middleware && ep.middleware.length ? ep.middleware.join(', ') : '';
      var trigger = ep.triggeredByPath.replace(/^.*\//, '');
      var chain = ep.dependencyChain && ep.dependencyChain.length > 1
        ? ep.dependencyChain.map(function(p){ return p.replace(/^.*\//, ''); }).join(' → ')
        : trigger;
      html += '<div style="padding:8px 0;' + (i > 0 ? 'border-top:1px solid rgba(255,255,255,.06);' : '') + '">';
      html += '<div class="flex items-center gap-2 mb-1">';
      html += '<span class="font-semibold rounded" style="font-size:10px;padding:1px 5px;background:' + color + '1a;color:' + color + ';border:1px solid ' + color + '40;letter-spacing:0.04em">' + ep.method + '</span>';
      html += '<code style="font-size:12px;color:#c9d1d9;word-break:break-all">' + ep.uri + '</code>';
      html += '</div>';
      if (ep.name) {
        html += '<div style="font-size:11px;color:#6e7681;margin-bottom:2px">&#x1f516; ' + ep.name + '</div>';
      }
      if (mw) {
        html += '<div style="font-size:11px;color:#6e7681;margin-bottom:2px">&#x1f512; ' + mw + '</div>';
      }
      html += '<div style="font-size:11px;color:#484f58" title="' + ep.dependencyChain.join(' → ') + '">via ' + chain + '</div>';
      html += '</div>';
    });
    list.innerHTML = html;
  }
  document.addEventListener('DOMContentLoaded', renderEndpoints);
})();
function toggleEndpointsPanel() {
  var body = document.getElementById('endpointsBody');
  var chevron = document.getElementById('endpointsChevron');
  var open = body.style.display !== 'none';
  body.style.display = open ? 'none' : '';
  chevron.style.transform = open ? '' : 'rotate(180deg)';
}
</script>

<!-- ── Path-finding bar ── -->
<div class="pathfind-bar fixed bottom-5 z-[15] bg-surface border rounded-xl px-5 py-2.5 text-sm items-center gap-3 shadow-[0_4px_20px_rgba(247,129,102,.2)]" style="left:50%;transform:translateX(-50%);border-color:rgba(247,129,102,0.5);display:none" id="pathfindBar">
  <span style="color:#f78166">&#9670;</span>
  <span id="pathfindInfo" class="text-fg"></span>
  <button class="bg-overlay border border-border-default text-fg rounded-lg px-3 py-1 cursor-pointer transition-colors hover:bg-[#2d333b] font-sans" style="font-size:12px" onclick="clearPathfinding()">Clear</button>
</div>

<!-- ── Detail panel ── -->
<div id="panel">
  <div id="panel-resize"></div>
  <div id="panel-topbar">
    <button class="panel-back" id="panelBack" onclick="if(window.parent!==window)window.parent.postMessage({type:'backToFiles'},'*')">&#8592; Files</button>
    <div id="panel-breadcrumbs"></div>
    <button class="panel-close" onclick="closePanel()">&times;</button>
  </div>
  <div class="panel-header" id="panel-header"></div>
  <div class="panel-actions" id="panel-actions"></div>
  <div class="change-bar-wrap" id="panel-bar"></div>
  <div class="panel-body" id="panel-body"></div>
  <div id="diff-nav">
    <button onclick="scrollToPrevDiff()">&#8593; Prev change</button>
    <button onclick="scrollToNextDiff()">&#8595; Next change</button>
  </div>
  <button id="complexity-scroll-btn" onclick="scrollToComplexity()">&#8593; Back</button>
</div>
