<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PR #{{ $prNumber }} — {{ $prTitle }}</title>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&family=jetbrains-mono:400,500,600&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      colors: {
        canvas: '#0d1117',
        surface: '#161b22',
        overlay: '#1c2128',
        'border-default': '#30363d',
        'border-subtle': '#21262d',
        fg: '#e6edf3',
        'fg-muted': '#8b949e',
        'fg-subtle': '#6e7681',
        accent: '#58a6ff',
        success: '#3fb950',
        danger: '#f85149',
        attention: '#d29922',
        severe: '#f0883e',
      },
      fontFamily: {
        sans: ['"Instrument Sans"', 'system-ui', '-apple-system', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
      },
    },
  },
}
</script>
<style>
  /* ── Reset & base ── */
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    background: #0d1117; color: #e6edf3;
    font-family: 'Instrument Sans', system-ui, -apple-system, sans-serif;
    font-feature-settings: 'cv02', 'cv03', 'cv04', 'cv11';
    -webkit-font-smoothing: antialiased;
    overflow: hidden;
  }
  #canvas { width: 100vw; height: 100vh; cursor: grab; }
  #canvas.grabbing { cursor: grabbing; }

  /* ── Scrollbars ── */
  ::-webkit-scrollbar { width: 5px; height: 5px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: #30363d; border-radius: 10px; }
  ::-webkit-scrollbar-thumb:hover { background: #484f58; }

  /* ── Hover tooltip (JS-positioned) ── */
  .tooltip {
    position: absolute; pointer-events: none;
    background: #1c2128; border: 1px solid rgba(48,54,61,0.8);
    border-radius: 10px; padding: 12px 16px;
    font-size: 13px; line-height: 1.6;
    box-shadow: 0 8px 32px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.04) inset;
    max-width: 380px; display: none; z-index: 10;
  }
  .tooltip .path { color: #58a6ff; font-weight: 600; }
  .tooltip .stat { color: #8b949e; }
  .tooltip .added { color: #3fb950; }
  .tooltip .removed { color: #f85149; }
  .tooltip .hint { color: #6e7681; font-size: 11px; margin-top: 6px; }

  /* ── Toggle switches ── */
  .toggle { position: relative; width: 34px; height: 18px; flex-shrink: 0; cursor: pointer; }
  .toggle input { opacity: 0; width: 0; height: 0; }
  .toggle .slider {
    position: absolute; inset: 0;
    background: #2d333b; border-radius: 9px;
    border: 1px solid #3d444d;
    transition: background 0.2s ease, border-color 0.2s ease;
  }
  .toggle .slider::before {
    content: ''; position: absolute;
    width: 12px; height: 12px; left: 2px; top: 2px;
    background: #8b949e; border-radius: 50%;
    transition: transform 0.2s ease, background 0.2s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,.4);
  }
  .toggle input:checked + .slider { background: #1f6feb; border-color: #388bfd; }
  .toggle input:checked + .slider::before { background: #fff; transform: translateX(16px); }

  /* ── Legend chevron ── */
  .legend-chevron svg { transition: transform 0.25s ease; }
  .legend-chevron.collapsed svg { transform: rotate(-90deg); }

  /* ── Layout switcher buttons (PHP-generated) ── */
  .layout-btn {
    padding: 3px 11px; border-radius: 6px;
    font-size: 11px; font-weight: 500; letter-spacing: 0.02em;
    text-decoration: none; cursor: pointer; font-family: inherit;
    border: 1px solid #30363d; color: #8b949e; background: #21262d;
    transition: all 0.15s ease; display: inline-block;
  }
  .layout-btn:hover { background: #2d333b; color: #c9d1d9; border-color: #484f58; }
  .layout-btn.active { background: #1f6feb; color: #fff; border-color: #388bfd; cursor: default; }

  /* ── Toggle rows (PHP-generated HTML) ── */
  .toggle-row { display: flex; align-items: center; gap: 9px; margin-top: 3px; }
  .toggle-label { font-size: 12px; color: #8b949e; cursor: pointer; user-select: none; line-height: 1.4; }
  .legend-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }

  /* ── Badges (PHP-generated) ── */
  .badge {
    display: inline-flex; align-items: center;
    padding: 2px 9px; border-radius: 20px;
    font-size: 11.5px; font-weight: 500;
  }
  .badge-new { background: rgba(13,53,32,0.85); color: #3fb950; border: 1px solid rgba(35,134,54,0.5); }
  .badge-mod { background: rgba(45,28,0,0.85); color: #d29922; border: 1px solid rgba(158,106,3,0.5); }
  .badge-deleted { background: rgba(61,18,20,0.85); color: #f85149; border: 1px solid rgba(218,54,51,0.5); }
  .badge-renamed { background: rgba(28,29,78,0.85); color: #a5b4fc; border: 1px solid rgba(99,102,241,0.5); }
  .badge-conn { background: rgba(22,27,34,0.85); color: #484f58; border: 1px dashed #30363d; }
  .badge-add { color: #3fb950; }
  .badge-del { color: #f85149; }

  /* ── Buttons (JS-generated) ── */
  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 14px; border-radius: 8px;
    font-size: 12.5px; font-weight: 500; font-family: inherit;
    text-decoration: none; cursor: pointer;
    border: 1px solid transparent; transition: all 0.15s ease;
    letter-spacing: 0.01em;
  }
  .btn-primary { background: #238636; color: #fff; border-color: #2ea043; }
  .btn-primary:hover { background: #2ea043; border-color: #3fb950; }
  .btn-secondary { background: #21262d; color: #c9d1d9; border-color: #30363d; }
  .btn-secondary:hover { background: #2d333b; border-color: #484f58; }
  .btn-review { background: transparent; color: #8b949e; border-color: #30363d; }
  .btn-review:hover { background: rgba(13,53,32,0.4); color: #3fb950; border-color: rgba(35,134,54,0.6); }
  .btn-reviewed { background: rgba(13,53,32,0.6); color: #3fb950; border-color: rgba(35,134,54,0.7); }
  .btn-reviewed:hover { background: transparent; color: #8b949e; border-color: #30363d; }

  /* ── Panel structure ── */
  #panel {
    position: fixed; top: 0; right: 0; height: 100dvh;
    background: #161b22; border-left: 1px solid #21262d; z-index: 20;
    display: flex; flex-direction: column;
    box-shadow: -8px 0 40px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.03) inset;
    transform: translateX(100%); transition: transform 0.28s cubic-bezier(0.22,1,0.36,1);
  }
  #panel.open { transform: translateX(0); }

  #panel-resize {
    position: absolute; top: 0; left: -4px; width: 8px; height: 100%;
    cursor: col-resize; z-index: 25;
  }
  #panel-resize::after {
    content: ''; position: absolute; top: 0; left: 3px; width: 2px; height: 100%;
    background: transparent; transition: background 0.2s;
  }
  #panel-resize:hover::after, #panel-resize.active::after { background: #388bfd; }

  /* ── Panel header (JS-generated innerHTML) ── */
  .panel-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid #21262d;
    flex-shrink: 0;
    background: linear-gradient(180deg, rgba(28,33,40,0.8) 0%, rgba(22,27,34,0) 100%);
  }
  .panel-header .file-name {
    font-size: 14.5px; font-weight: 600; color: #e6edf3;
    word-break: break-all; line-height: 1.4; letter-spacing: -0.01em;
  }
  .panel-header .file-path { font-size: 11.5px; color: #6e7681; margin-top: 4px; word-break: break-all; }
  .panel-header .badge-row { display: flex; gap: 8px; margin-top: 10px; align-items: center; flex-wrap: wrap; }

  /* ── Panel actions (JS-generated) ── */
  .panel-actions {
    padding: 10px 24px; border-bottom: 1px solid #21262d;
    flex-shrink: 0; display: flex; gap: 8px; flex-wrap: wrap;
  }

  /* ── Panel body ── */
  .panel-body { flex: 1; overflow-y: auto; padding: 0; }
  .panel-body::-webkit-scrollbar { width: 5px; }
  .panel-body::-webkit-scrollbar-track { background: transparent; }
  .panel-body::-webkit-scrollbar-thumb { background: #2d333b; border-radius: 10px; }

  /* ── Panel close/back ── */
  .panel-close {
    position: absolute; top: 14px; right: 14px; background: none; border: none;
    color: #6e7681; font-size: 18px; cursor: pointer; line-height: 1; padding: 4px;
    border-radius: 6px; transition: color 0.15s, background 0.15s;
  }
  .panel-close:hover { color: #e6edf3; background: #21262d; }
  .panel-back {
    position: absolute; top: 14px; right: 44px; background: none; border: none;
    color: #6e7681; font-size: 12px; cursor: pointer; padding: 4px 8px;
    display: none; align-items: center; gap: 4px; border-radius: 6px;
    transition: color 0.15s, background 0.15s; font-family: inherit;
  }
  .panel-back:hover { color: #58a6ff; background: #21262d; }
  .panel-back.visible { display: inline-flex; }

  #panel-breadcrumbs {
    display: none; padding: 6px 24px 4px; border-bottom: 1px solid #21262d;
    flex-shrink: 0; align-items: center; flex-wrap: wrap; gap: 2px; font-size: 12px;
  }
  .bc-item {
    color: #58a6ff; cursor: pointer; padding: 2px 4px; border-radius: 4px;
    white-space: nowrap; max-width: 180px; overflow: hidden; text-overflow: ellipsis;
  }
  .bc-item:hover { background: #21262d; }
  .bc-current { color: #8b949e; cursor: default; }
  .bc-current:hover { background: none; }
  .bc-sep { color: #484f58; padding: 0 1px; font-size: 11px; }

  .panel-body::-webkit-scrollbar { width: 6px; }
  .panel-body::-webkit-scrollbar-track { background: transparent; }
  .panel-body::-webkit-scrollbar-thumb { background: #30363d; border-radius: 3px; }

  /* ── Complexity scroll button ── */
  #complexity-scroll-btn {
    position: absolute; bottom: 20px; right: 20px; z-index: 30;
    background: #1c2128; color: #8b949e; border: 1px solid #30363d;
    border-radius: 8px; padding: 6px 12px; font-size: 12px; cursor: pointer;
    display: none; align-items: center; gap: 6px;
    box-shadow: 0 4px 16px rgba(0,0,0,.5);
    transition: background 0.15s, color 0.15s, border-color 0.15s;
    white-space: nowrap; font-family: inherit;
  }
  #complexity-scroll-btn:hover { background: #2d333b; color: #c9d1d9; border-color: #484f58; }
  #complexity-scroll-btn.visible { display: inline-flex; }

  /* ── Change bar (JS-generated) ── */
  .change-bar-wrap { padding: 12px 24px; border-bottom: 1px solid #21262d; }
  .change-bar { display: flex; height: 4px; border-radius: 2px; overflow: hidden; background: #21262d; }
  .change-bar .add-seg { background: #3fb950; }
  .change-bar .del-seg { background: #f85149; }
  .change-bar-label { display: flex; justify-content: space-between; font-size: 12.5px; color: #6e7681; margin-top: 5px; }

  /* ── Dependency section (JS-generated) ── */
  .deps-section { padding: 16px 24px; }
  .deps-section h4 {
    font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.7px; font-weight: 600;
    color: #6e7681; margin-bottom: 10px;
  }
  .dep-item {
    display: flex; align-items: center; gap: 8px;
    padding: 5px 10px; border-radius: 7px;
    font-size: 13px; color: #c9d1d9; cursor: pointer;
    transition: background 0.15s;
  }
  .dep-item:hover { background: #1c2128; }
  .dep-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }

  /* ── Analysis rows (JS-generated) ── */
  .analysis-row {
    display: flex; align-items: flex-start; gap: 8px;
    padding: 5px 6px; font-size: 13px; color: #c9d1d9;
    border-radius: 6px; margin: 0 -6px;
  }
  .analysis-row.clickable { cursor: pointer; }
  .analysis-row.clickable:hover { background: #1c2128; }
  .analysis-row.clickable .analysis-location { color: #58a6ff; }
  .analysis-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; margin-top: 5px; }
  .analysis-label { flex: 1; min-width: 0; }
  .analysis-desc { display: block; font-size: 13px; color: #6e7681; line-height: 1.5; margin-top: 2px; }
  .analysis-location { color: #6e7681; font-size: 12.5px; margin-left: auto; white-space: nowrap; flex-shrink: 0; }
  .analysis-toggle {
    background: transparent; border: 1px solid #30363d; color: #58a6ff;
    padding: 3px 11px; border-radius: 6px; font-size: 13px; cursor: pointer;
    margin-top: 8px; font-family: inherit; transition: background 0.15s;
  }
  .analysis-toggle:hover { background: #1c2128; }

  /* ── Diff viewer (JS-generated) ── */
  .diff-section { border-top: 1px solid #21262d; }
  .diff-section h4 {
    font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.7px; font-weight: 600;
    color: #6e7681; padding: 12px 24px 8px;
    position: sticky; top: 0; background: #161b22; z-index: 1;
    display: flex; align-items: center;
  }
  .diff-view-controls { margin-left: auto; display: flex; gap: 4px; }
  .diff-view-btn {
    background: none; border: 1px solid #30363d; color: #6e7681;
    padding: 2px 8px; border-radius: 5px; font-size: 12px; cursor: pointer;
    font-family: inherit; transition: all 0.15s; letter-spacing: normal; text-transform: none;
  }
  .diff-view-btn:hover { background: #21262d; color: #c9d1d9; }
  .diff-view-btn.active { border-color: #388bfd; color: #58a6ff; background: rgba(31,111,235,0.08); }
  .diff-table {
    width: 100%; border-collapse: collapse;
    font-family: 'JetBrains Mono', 'SF Mono', 'Fira Code', Menlo, Consolas, monospace;
    font-size: 12px; line-height: 1.6;
  }
  .diff-table td { padding: 0 12px; white-space: pre-wrap; word-break: break-all; vertical-align: top; }
  .diff-table .diff-ln {
    position: relative; width: 1px; min-width: 52px;
    color: #484f58; text-align: right; padding-right: 8px;
    user-select: none; font-size: 11px;
  }
  .diff-table .diff-add { background: rgba(13,53,32,0.5); color: #7ee787; }
  .diff-table .diff-del { background: rgba(61,17,23,0.5); color: #ffa198; }
  .diff-table .diff-hunk { background: rgba(22,27,34,0.85); color: #58a6ff; font-style: italic; padding: 4px 12px; }
  .diff-table .diff-ln-add { background: rgba(10,46,26,0.6); }
  .diff-table .diff-ln-del { background: rgba(48,17,26,0.6); }
  .diff-table .diff-ctx { color: #c9d1d9; }
  .diff-table tr.diff-highlight td { outline: 2px solid #388bfd; outline-offset: -2px; }
  .diff-table tr.diff-highlight td.diff-ln { background: rgba(31,111,235,0.18); }
  .diff-table tr.diff-highlight td:not(.diff-ln) { background: rgba(31,111,235,0.14); color: #e6edf3; }
  /* Split view */
  .diff-table.split .diff-code { width: 45%; }
  .diff-table.split .diff-empty { opacity: 0.4; }

  /* ── Method call links (JS-generated) ── */
  .method-call-link { color: #58a6ff; cursor: pointer; text-decoration: underline; text-decoration-style: dotted; text-underline-offset: 2px; }
  .method-call-link:hover { color: #79c0ff; text-decoration-style: solid; }
  .caller-badge { margin-left: 12px; font-size: 10px; font-family: monospace; white-space: nowrap; vertical-align: middle; }
  .caller-link { color: #58a6ff; cursor: pointer; }
  .caller-link:hover { color: #79c0ff; text-decoration: underline; }
  .callers-more-btn { cursor: pointer; }
  .callers-more-btn:hover { color: #c9d1d9 !important; }

  /* ── Caller popup (JS-generated) ── */
  #caller-popup {
    display: none; position: fixed; z-index: 60;
    background: #1c2128; border: 1px solid #30363d; border-radius: 10px;
    padding: 8px 12px; font-size: 12px;
    min-width: 160px; max-width: 280px; max-height: 240px; overflow-y: auto;
    box-shadow: 0 8px 32px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.04) inset;
  }
  #caller-popup .popup-title { color: #6e7681; font-size: 10px; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
  #caller-popup .caller-link { display: block; padding: 4px 0; border-bottom: 1px solid #21262d; color: #58a6ff; }
  #caller-popup .caller-link:last-child { border-bottom: none; }

  /* ── Diff annotation (JS-generated) ── */
  .diff-annotation {
    position: absolute; left: 4px; top: 50%; transform: translateY(-50%);
    width: 7px; height: 7px; border-radius: 50%; cursor: pointer; z-index: 2;
  }
  .diff-annotation-tip {
    display: none; position: fixed;
    background: #1c2128; border: 1px solid #30363d; border-radius: 10px;
    padding: 10px 14px; font-size: 12px; color: #c9d1d9; line-height: 1.5;
    white-space: normal; width: 300px; z-index: 50;
    box-shadow: 0 8px 32px rgba(0,0,0,.55);
  }
  .diff-annotation-tip .tip-entry { display: flex; align-items: flex-start; gap: 6px; padding: 4px 0; }
  .diff-annotation-tip .tip-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; margin-top: 5px; }
  .diff-annotation-tip .tip-sev { font-size: 10px; text-transform: uppercase; font-weight: 600; flex-shrink: 0; margin-top: 1px; }
  .diff-annotation-tip .tip-entry + .tip-entry { border-top: 1px solid #21262d; }

  /* ── Metrics badge/tooltip (PHP-generated) ── */
  .metrics-badge { display: flex; align-items: center; gap: 8px; cursor: pointer; position: relative; padding: 8px 0; }
  .metrics-tooltip {
    display: none; position: absolute; top: 100%; left: 0; z-index: 30;
    background: #1c2128; border: 1px solid #30363d; border-radius: 10px;
    padding: 14px 16px; margin-top: 6px; min-width: 380px;
    box-shadow: 0 8px 32px rgba(0,0,0,.6);
  }
  .metrics-badge:hover .metrics-tooltip { display: block; }

  /* ── Risk badge (PHP-generated) ── */
  .risk-badge {
    display: flex; align-items: baseline; gap: 6px;
    padding: 8px 0; margin-bottom: 8px; position: relative; cursor: default;
  }
  .risk-score-num { font-size: 28px; font-weight: 700; line-height: 1; letter-spacing: -0.03em; }
  .risk-score-denom { font-size: 12px; color: #6e7681; }
  .risk-label {
    font-size: 10.5px; padding: 2px 8px; border-radius: 20px;
    border: 1px solid; margin-left: 2px; font-weight: 500;
  }
  .risk-tooltip {
    display: none; position: absolute; top: 100%; left: 0; z-index: 30;
    background: #1c2128; border: 1px solid #30363d; border-radius: 10px;
    padding: 14px 16px; margin-top: 4px; min-width: 220px;
    box-shadow: 0 8px 32px rgba(0,0,0,.6);
  }
  .risk-badge:hover .risk-tooltip { display: block; }

  /* ── Pathfind bar (active state toggled by JS) ── */
  #pathfindBar.active { display: flex !important; }
</style>
</head>
<body>
<canvas id="canvas"></canvas>
<div class="diff-annotation-tip" id="diffTip"></div>
<div id="caller-popup"></div>
<div class="tooltip" id="tooltip"></div>

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
        <span class="border-b-2 border-dashed border-success">Dashed border</span> = new &middot;
        <span class="border-b-2 border-dashed border-fg-subtle">Gray dashed</span> = connected<br>
        <span class="border-b-2 border-solid border-severe">Orange ring</span> = circular dep<br>
        Size = lines changed &middot; Scroll to zoom
      </p>

      <div class="flex flex-col gap-0.5">
        <div class="toggle-row">
          <label class="toggle"><input type="checkbox" id="toggleReviewed"><span class="slider"></span></label>
          <label class="toggle-label" for="toggleReviewed">Show reviewed <span id="reviewedToggleCount" style="color:#484f58">(0)</span></label>
        </div>
        <div class="toggle-row">
          <label class="toggle"><input type="checkbox" id="toggleConnected"><span class="slider"></span></label>
          <label class="toggle-label" for="toggleConnected">Show connected <span style="color:#484f58">({{ $connectedCount }})</span></label>
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

<!-- ── Path-finding bar ── -->
<div class="pathfind-bar fixed bottom-5 z-[15] bg-surface border rounded-xl px-5 py-2.5 text-sm items-center gap-3 shadow-[0_4px_20px_rgba(247,129,102,.2)]" style="left:50%;transform:translateX(-50%);border-color:rgba(247,129,102,0.5);display:none" id="pathfindBar">
  <span style="color:#f78166">&#9670;</span>
  <span id="pathfindInfo" class="text-fg"></span>
  <button class="bg-overlay border border-border-default text-fg rounded-lg px-3 py-1 cursor-pointer transition-colors hover:bg-[#2d333b] font-sans" style="font-size:12px" onclick="clearPathfinding()">Clear</button>
</div>

<!-- ── Detail panel ── -->
<div id="panel">
  <div id="panel-resize"></div>
  <button class="panel-back" id="panelBack" onclick="if(window.parent!==window)window.parent.postMessage({type:'backToFiles'},'*')">&#8592; Files</button>
  <button class="panel-close" onclick="closePanel()">&times;</button>
  <div id="panel-breadcrumbs"></div>
  <div class="panel-header" id="panel-header"></div>
  <div class="panel-actions" id="panel-actions"></div>
  <div class="change-bar-wrap" id="panel-bar"></div>
  <div class="panel-body" id="panel-body"></div>
  <button id="complexity-scroll-btn" onclick="scrollToComplexity()">&#8593; Back</button>
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

// Build method name index for inline call linking – first match wins on ambiguity.
// Source 1: method nodes (code:file – nodes have a .code property and .name).
// Source 2: metricsData per-file method lists (code:analyze – links to the file node).
var methodNameIndex = {};
nodes.forEach(function(n) {
  if (n.code !== undefined && n.name && !methodNameIndex[n.name]) methodNameIndex[n.name] = n.id;
});
(function() {
  var pathToNodeId = {};
  nodes.forEach(function(n) { if (n.path) pathToNodeId[n.path] = n.id; });
  Object.keys(metricsData).forEach(function(path) {
    var nodeId = pathToNodeId[path];
    if (!nodeId) return;
    var mm = metricsData[path] && metricsData[path].method_metrics;
    if (!mm) return;
    mm.forEach(function(m) { if (m.name && !methodNameIndex[m.name]) methodNameIndex[m.name] = nodeId; });
  });
}());

// Build class name index: PHP UpperCamelCase file basename → node id.
var classNameIndex = {};
(function() {
  nodes.forEach(function(n) {
    if (!n.path || !n.path.endsWith('.php')) return;
    var parts = n.path.split('/');
    var basename = parts[parts.length - 1].replace(/\.php$/, '');
    if (basename && /^[A-Z]/.test(basename) && !classNameIndex[basename]) {
      classNameIndex[basename] = n.id;
    }
  });
}());

// Build reverse caller index: callersIndex["targetNodeId:methodName"] = [{node, line}, ...]
// Uses type-hint resolution to map $this->prop->method() to the correct target class,
// avoiding false positives when multiple classes share the same method name.
var callersIndex = {};
(function() {
  // Matches "TypeName $varName" — captures type hints from constructor/param signatures
  var typePat   = /([A-Z][a-zA-Z0-9_]*)\s+\$(\w+)/g;
  // Matches "$this->prop->method(" or "$prop->method("
  var chainPat  = /\$(?:this->)?(\w+)->([a-zA-Z_]\w*)\s*\(/g;
  // Matches "ClassName::method(" for static calls
  var staticPat = /([A-Z][a-zA-Z0-9_]*)::([a-zA-Z_]\w*)\s*\(/g;

  nodes.forEach(function(n) {
    // Only scan focal nodes (files present in the diff) — skip connected dependencies.
    if (!n.path || !n.path.endsWith('.php') || !fileDiffs[n.path]) return;
    var text = fileContents[n.path] || '';
    var hasFullContent = !!text;
    if (!hasFullContent) {
      var rd = fileDiffs[n.path];
      text = rd.split('\n').filter(function(l) { return l.length > 0 && l[0] !== '-' && l[0] !== '@'; }).map(function(l) { return l.substring(1); }).join('\n');
    }

    // Build property-name → class-node-id map from type hints in this file.
    // e.g. "private readonly OrderRepository $orderRepository" → propToClass['orderRepository'] = nodeId
    var propToClass = {};
    typePat.lastIndex = 0;
    var tm;
    while ((tm = typePat.exec(text)) !== null) {
      var typeName = tm[1], propName = tm[2];
      if (classNameIndex[typeName] && !propToClass[propName]) {
        propToClass[propName] = classNameIndex[typeName];
      }
    }

    function addCaller(targetNodeId, methodName, lineNum) {
      if (!targetNodeId || targetNodeId === n.id) return;
      var key = targetNodeId + ':' + methodName;
      if (!callersIndex[key]) callersIndex[key] = [];
      if (!callersIndex[key].some(function(c) { return c.node.id === n.id; })) {
        callersIndex[key].push({ node: n, line: lineNum });
      }
    }

    // Scan line-by-line (O(n))
    var lines = text.split('\n');
    for (var li = 0; li < lines.length; li++) {
      var lineText = lines[li];
      var lineNum  = hasFullContent ? li + 1 : null;

      // Static calls: always unambiguous
      staticPat.lastIndex = 0;
      var sm;
      while ((sm = staticPat.exec(lineText)) !== null) {
        addCaller(classNameIndex[sm[1]], sm[2], lineNum);
      }

      // Instance calls: only record when the receiver type is known
      chainPat.lastIndex = 0;
      var cm;
      while ((cm = chainPat.exec(lineText)) !== null) {
        var targetId = propToClass[cm[1]];
        if (targetId) addCaller(targetId, cm[2], lineNum);
      }
    }
  });
}());

// Build implementors index: implementorsIndex[interfaceNodeId] = [{node}, ...]
// Build implementee index: implementeeIndex[concreteNodeId] = [interfaceNode, ...]
// Together these power the implementation-picker popup shown when clicking a method link
// whose target is either an interface or a concrete class that implements one.
var implementorsIndex = {};
var implementeeIndex = {};
(function() {
  links.forEach(function(l) {
    if (l.depType !== 'implements' || !l.source || !l.target) return;
    if (!implementorsIndex[l.target.id]) implementorsIndex[l.target.id] = [];
    if (!implementorsIndex[l.target.id].some(function(e) { return e.node.id === l.source.id; })) {
      implementorsIndex[l.target.id].push({ node: l.source });
    }
    if (!implementeeIndex[l.source.id]) implementeeIndex[l.source.id] = [];
    if (!implementeeIndex[l.source.id].some(function(iface) { return iface.id === l.target.id; })) {
      implementeeIndex[l.source.id].push(l.target);
    }
  });
}());

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
var hiddenExts = _toHiddenSet(_fd.hidden_extensions);
var hiddenDomains = _toHiddenSet(_fd.hidden_domains);
var hiddenSeverities = _toHiddenSet(_fd.hidden_severities);
var hiddenChangeTypes = _toHiddenSet(_fd.hidden_change_types);
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
  closePanel();
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

function highlightPHP(code, linkMap, classMap, ifaceIndex) {
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
      // Detect method call link: plain identifier followed by '(' that exists in linkMap,
      // but NOT a method definition (i.e. not preceded by the 'function' keyword).
      if (kwType === 'plain' && linkMap && linkMap[word]) {
        var k = j;
        while (k < len && (code[k] === ' ' || code[k] === '\t')) k++;
        if (code[k] === '(') {
          var pi = tokens.length - 1;
          while (pi >= 0 && tokens[pi].type === 'plain') pi--;
          var prevKeyword = pi >= 0 ? tokens[pi] : null;
          var isDefinition = prevKeyword && prevKeyword.type === 'keyword' && prevKeyword.text === 'function';
          if (!isDefinition) { tokens.push({type: 'method_link', text: word, targetId: linkMap[word]}); i = j; continue; }
        }
      }
      // Detect class reference link: UpperCamelCase word in classMap.
      // Covers type hints, `new`/`extends`/`implements`/`instanceof`, `::`, and
      // the class/interface declaration itself (so `interface LoggerInterface` is
      // clickable and can show the implementations picker).
      if (kwType === 'plain' && classMap && classMap[word]) {
        var pi = tokens.length - 1;
        while (pi >= 0 && tokens[pi].type === 'plain') pi--;
        var prevKw = pi >= 0 ? tokens[pi] : null;
        var prevKwText = prevKw && prevKw.type === 'keyword' ? prevKw.text : '';
        var isDeclaration = /^(class|interface|trait|enum)$/.test(prevKwText);
        // Allow declaration names to be links only when they're an interface that has
        // known implementations — so `interface LoggerInterface` is clickable but
        // `class OrderService` in its own definition is not.
        var declClickable = isDeclaration && ifaceIndex && ifaceIndex[classMap[word]];
        var isClassContext = declClickable || /^(new|extends|implements|instanceof)$/.test(prevKwText);
        if (!isClassContext) {
          // Type hint: word followed by optional whitespace then '$'
          var k = j;
          while (k < len && (code[k] === ' ' || code[k] === '\t')) k++;
          isClassContext = (code[k] === '$');
          // Static access: word followed by '::'
          if (!isClassContext) isClassContext = (code[j] === ':' && code[j+1] === ':');
        }
        if (isClassContext) { tokens.push({type: 'class_link', text: word, targetId: classMap[word]}); i = j; continue; }
      }
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
      case 'comment':     out += '<span style="color:#6e7681;font-style:italic">' + esc + '</span>'; break;
      case 'string':      out += '<span style="color:#a5d6ff">' + esc + '</span>'; break;
      case 'variable':    out += '<span style="color:#ffa657">' + esc + '</span>'; break;
      case 'keyword':     out += '<span style="color:#ff7b72">' + esc + '</span>'; break;
      case 'type':        out += '<span style="color:#79c0ff">' + esc + '</span>'; break;
      case 'number':      out += '<span style="color:#79c0ff">' + esc + '</span>'; break;
      case 'method_link': out += '<span class="method-call-link" data-node-id="' + escapeHtml(tok.targetId) + '" data-method-name="' + esc + '" title="Go to ' + esc + '">' + esc + '</span>'; break;
      case 'class_link':  out += '<span class="method-call-link" data-node-id="' + escapeHtml(tok.targetId) + '" title="Go to ' + esc + '">' + esc + '</span>'; break;
      default:            out += esc; break;
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

function renderUnifiedDiff(parsed, isPHP, linkMap, classMap, ifaceIndex) {
  var html = '';
  var oldLn = 0, newLn = 0;
  for (var r = 0; r < parsed.length; r++) {
    var row = parsed[r];
    if (row.type === 'hunk') {
      oldLn = row.oldStart; newLn = row.newStart;
      var esc = row.raw.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      html += '<tr><td class="diff-ln"></td><td class="diff-ln"></td><td class="diff-hunk">' + esc + '</td></tr>';
    } else if (row.type === 'ctx') {
      var h = isPHP ? highlightPHP(row.text, linkMap, classMap, ifaceIndex) : escapeHtml(row.text);
      html += '<tr data-new-ln="' + newLn + '" data-old-ln="' + oldLn + '"><td class="diff-ln">' + oldLn + '</td><td class="diff-ln">' + newLn + '</td><td class="diff-ctx"> ' + h + '</td></tr>';
      oldLn++; newLn++;
    } else {
      for (var j = 0; j < row.dels.length; j++) {
        var h = isPHP ? highlightPHP(row.dels[j], linkMap, classMap, ifaceIndex) : escapeHtml(row.dels[j]);
        html += '<tr data-old-ln="' + oldLn + '"><td class="diff-ln diff-ln-del">' + oldLn + '</td><td class="diff-ln diff-ln-del"></td><td class="diff-del">-' + h + '</td></tr>';
        oldLn++;
      }
      for (var j = 0; j < row.adds.length; j++) {
        var h = isPHP ? highlightPHP(row.adds[j], linkMap, classMap, ifaceIndex) : escapeHtml(row.adds[j]);
        html += '<tr data-new-ln="' + newLn + '"><td class="diff-ln diff-ln-add"></td><td class="diff-ln diff-ln-add">' + newLn + '</td><td class="diff-add">+' + h + '</td></tr>';
        newLn++;
      }
    }
  }
  return html;
}

function renderSplitDiff(parsed, isPHP, linkMap, classMap, ifaceIndex) {
  var html = '';
  var oldLn = 0, newLn = 0;
  for (var r = 0; r < parsed.length; r++) {
    var row = parsed[r];
    if (row.type === 'hunk') {
      oldLn = row.oldStart; newLn = row.newStart;
      var esc = row.raw.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      html += '<tr><td colspan="4" class="diff-hunk">' + esc + '</td></tr>';
    } else if (row.type === 'ctx') {
      var h = isPHP ? highlightPHP(row.text, linkMap, classMap, ifaceIndex) : escapeHtml(row.text);
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
          var dh = isPHP ? highlightPHP(dels[j], linkMap, classMap, ifaceIndex) : escapeHtml(dels[j]);
          html += '<td class="diff-ln diff-ln-del">' + (bOld + j) + '</td><td class="diff-del diff-code">-' + dh + '</td>';
        } else {
          html += '<td class="diff-ln diff-ln-del"></td><td class="diff-del diff-code diff-empty"></td>';
        }
        if (hasAdd) {
          var ah = isPHP ? highlightPHP(adds[j], linkMap, classMap, ifaceIndex) : escapeHtml(adds[j]);
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

function renderFullFile(fileContent, parsedDiff, isPHP, linkMap, classMap, ifaceIndex) {
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
      var dh = isPHP ? highlightPHP(dels[d], linkMap, classMap, ifaceIndex) : escapeHtml(dels[d]);
      html += '<tr><td class="diff-ln diff-ln-del"></td><td class="diff-del">-' + dh + '</td></tr>';
    }
    var h = isPHP ? highlightPHP(lines[i], linkMap, classMap, ifaceIndex) : escapeHtml(lines[i]);
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
      var dh = isPHP ? highlightPHP(eds[d], linkMap, classMap, ifaceIndex) : escapeHtml(eds[d]);
      html += '<tr><td class="diff-ln diff-ln-del"></td><td class="diff-del">-' + dh + '</td></tr>';
    }
  }
  return html;
}

function scrollToComplexity() {
  var el = document.getElementById('methods-by-complexity');
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
      if (nd) openPanel(nd);
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
      bodyHtml += '<div id="methods-by-complexity" style="margin-top:10px">' +
        '<div style="font-size:11.5px;color:#6e7681;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px">Methods by Complexity</div>' +
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
        var status = methodDiffStatus(mth);
        var statusBadge = status === 'new'
          ? '<span style="color:#3fb950;font-size:10.5px;font-weight:500;margin-left:5px">new</span>'
          : status === 'modified'
          ? '<span style="color:#d29922;font-size:10.5px;font-weight:500;margin-left:5px">mod</span>'
          : '';
        var ccDelta = hasBefore ? (bm ? methodDelta(mth.cc, bm.cc, true) : (status === 'new' ? '' : '')) : '';
        var llocDelta = hasBefore ? (bm ? methodDelta(mth.lloc, bm.lloc, true) : '') : '';
        var paramsDelta = hasBefore ? (bm ? methodDelta(mth.params, bm.params, true) : '') : '';
        bodyHtml += '<tr' + hasLine + (mth.line ? ' style="cursor:pointer"' : '') + '>' +
          '<td style="padding:4px 8px 4px 0;color:#c9d1d9;white-space:nowrap;max-width:180px;overflow:hidden;text-overflow:ellipsis" title="' + mth.name + '">' + mth.name + statusBadge + '</td>' +
          '<td style="padding:4px 8px 4px 0;text-align:right;color:' + ccColor + ';font-weight:600">' + mth.cc + ccDelta + '</td>' +
          '<td style="padding:4px 8px 4px 0;text-align:right;color:#8b949e">' + mth.lloc + llocDelta + '</td>' +
          '<td style="padding:4px 0;text-align:right;color:#8b949e">' + mth.params + paramsDelta + '</td>' +
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
  var rawDiff = fileDiffs[n.path];
  if (rawDiff) {
    var isPHP = n.path.endsWith('.php');
    var parsedDiff = parseDiffLines(rawDiff);
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
        var lineAttr = entry.line ? ' data-call-line="' + entry.line + '"' : '';
        return '<span class="caller-link" data-node-id="' + escapeHtml(entry.node.id) + '"' + lineAttr + '>' + escapeHtml(entry.node.displayLabel || entry.node.id) + '</span>';
      }).join(' <span style="color:#484f58">·</span> ');
      var moreHtml = rest.length
        ? ' <span style="color:#484f58">·</span> <span class="callers-more-btn" style="color:#8b949e">+' + rest.length + ' more</span>'
        : '';
      badge.innerHTML = '<span style="color:#8b949e">← </span>' + linksHtml + moreHtml;
      badge.dataset.allCallers = JSON.stringify(callers.map(function(entry) { return { id: entry.node.id, label: entry.node.displayLabel || entry.node.id, line: entry.line }; }));
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
      var rd = fileDiffs[n.path];
      if (!rd) return;
      var isPHP = n.path.endsWith('.php');
      var parsed = parseDiffLines(rd);
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
        return '<span class="caller-link" data-node-id="' + escapeHtml(impl.node.id) + '">' + escapeHtml(impl.node.displayLabel || impl.node.id) + '</span>';
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
  if (impls && impls.length === 1) { openPanel(impls[0].node); return; }

  // Link points to a concrete class via a method call: if that class implements an
  // interface that has multiple implementations, show the picker instead of jumping straight in.
  // Scoped to method calls only (data-method-name present) — class references like type hints
  // and `new Foo()` should navigate directly to the clicked class, not open a picker.
  if (link.classList.contains('method-call-link') && link.hasAttribute('data-method-name')) {
    var implementedIfaces = implementeeIndex[nodeId];
    if (implementedIfaces) {
      for (var ii = 0; ii < implementedIfaces.length; ii++) {
        var ifaceImpls = implementorsIndex[implementedIfaces[ii].id];
        if (ifaceImpls && ifaceImpls.length > 1) { showImplsPopup(ifaceImpls, link); return; }
      }
    }
  }

  // Cross-file link → open the target panel, then scroll to the call line if known
  var callLine = link.getAttribute('data-call-line');
  openPanel(nd);
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
</script>
</body>
</html>
HTML;
