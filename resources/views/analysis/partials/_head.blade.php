<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PR #{{ $prNumber }} — {{ $prTitle }}</title>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&family=jetbrains-mono:400,500,600&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
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

  /* ── Diff prev/next navigation buttons ── */
  #diff-nav {
    position: absolute; bottom: 20px; right: 20px; z-index: 30;
    display: none; align-items: center; gap: 4px;
    box-shadow: 0 4px 16px rgba(0,0,0,.5); border-radius: 8px;
  }
  #diff-nav.visible { display: inline-flex; }
  #diff-nav button {
    background: #1c2128; color: #8b949e; border: 1px solid #30363d;
    padding: 6px 12px; font-size: 12px; cursor: pointer;
    font-family: inherit; transition: background 0.15s, color 0.15s, border-color 0.15s;
    white-space: nowrap; border-radius: 0;
  }
  #diff-nav button:first-child { border-radius: 8px 0 0 8px; border-right: none; }
  #diff-nav button:last-child  { border-radius: 0 8px 8px 0; }
  #diff-nav button:hover { background: #2d333b; color: #c9d1d9; border-color: #484f58; }

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
  /* Stack complexity button above diff-nav when both are visible */
  #diff-nav.visible + #complexity-scroll-btn.visible { bottom: 60px; }

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
