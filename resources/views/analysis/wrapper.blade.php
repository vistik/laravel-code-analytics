<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PR #{{ $prNumber }} — {!! $escapedTitle !!}</title>
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
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    background: #0d1117; color: #e6edf3;
    font-family: 'Instrument Sans', system-ui, -apple-system, sans-serif;
    font-feature-settings: 'cv02', 'cv03', 'cv04', 'cv11';
    -webkit-font-smoothing: antialiased;
    display: flex; flex-direction: column; height: 100dvh; overflow: hidden;
  }

  /* ── Top bar ── */
  .topbar {
    display: flex; align-items: center; justify-content: space-between; gap: 0;
    padding: 0; background: #161b22;
    border-bottom: 1px solid #21262d; flex-shrink: 0; min-height: 56px;
    box-shadow: 0 1px 0 rgba(255,255,255,.04);
  }
  /* File count lives in the inner card — hide headline from topbar */
  .headline { display: none; }

  /* ── Risk & metrics badges ── */
  .risk-badge, .metrics-badge {
    display: flex; align-items: center; gap: 8px;
    flex-shrink: 0; position: relative; cursor: default;
  }
  .risk-score-num { font-size: 26px; font-weight: 700; line-height: 1; letter-spacing: -0.03em; }
  .risk-score-denom { font-size: 11px; color: #6e7681; }
  .risk-label { font-size: 10.5px; padding: 2px 8px; border-radius: 20px; border: 1px solid; font-weight: 500; }
  .risk-tooltip, .metrics-tooltip {
    display: none; position: absolute; top: calc(100% + 8px); left: 50%; transform: translateX(-50%);
    background: #1c2128; border: 1px solid #30363d; border-radius: 10px;
    padding: 12px 14px; box-shadow: 0 8px 32px rgba(0,0,0,.6); z-index: 100;
  }
  .risk-tooltip::before, .metrics-tooltip::before {
    content: ''; position: absolute; top: -8px; left: 0; width: 100%; height: 8px;
  }
  .risk-tooltip { min-width: 210px; }
  .metrics-tooltip { white-space: nowrap; }
  .metrics-tooltip tr[data-path] { cursor: pointer; }
  .metrics-tooltip tr[data-path]:hover td { background: #21262d; }
  .risk-badge:hover .risk-tooltip, .metrics-badge:hover .metrics-tooltip { display: block; }
  .metrics-badge.tooltip-hidden .metrics-tooltip { display: none !important; }

  /* ── Top bar badges & tabs ── */
  .topbar-badges {
    display: flex; flex-direction: column; align-items: flex-start; justify-content: center;
    gap: 3px; padding: 10px 18px; flex-shrink: 0;
    border-right: 1px solid #21262d; align-self: stretch;
  }
  .badge-divider { display: none; }
  .tabs { display: flex; align-items: center; gap: 4px; flex-shrink: 0; padding: 0 12px; }
  .tab {
    padding: 4px 13px; border-radius: 7px; font-size: 11.5px; font-weight: 500;
    cursor: pointer; border: 1px solid #30363d; color: #8b949e; background: #21262d;
    transition: all 0.15s; font-family: inherit; display: inline-flex; align-items: center;
  }
  .tab:hover { background: #2d333b; color: #c9d1d9; border-color: #484f58; }
  .tab.active { background: #1f6feb; color: #fff; border-color: #388bfd; cursor: default; }
  .tab.tab-orange { color: #f85149; border-color: rgba(248,81,73,0.35); }
  .tab.tab-orange:hover { color: #ff7b72; border-color: rgba(248,81,73,0.6); background: rgba(61,17,23,0.4); }
  .tab.tab-orange.active { background: #da3633; border-color: #f85149; color: #fff; }

  /* ── Content area ── */
  .content-area { flex: 1; position: relative; overflow: hidden; }
  iframe { border: none; width: 100%; height: 100%; }

  /* ── File overview panel (left slide-over) ── */
  .files-panel {
    position: absolute; top: 0; left: -100%; height: 100%;
    background: #161b22; border-right: 1px solid #21262d; z-index: 30;
    display: flex; flex-direction: column;
    box-shadow: 8px 0 40px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.03) inset;
    transition: left 0.28s cubic-bezier(0.22,1,0.36,1);
  }
  .files-panel.open { left: 0; }
  .files-panel-resize {
    position: absolute; top: 0; right: -4px; width: 8px; height: 100%;
    cursor: col-resize; z-index: 35;
  }
  .files-panel-resize::after {
    content: ''; position: absolute; top: 0; right: 3px; width: 2px; height: 100%;
    background: transparent; transition: background 0.2s;
  }
  .files-panel-resize:hover::after, .files-panel-resize.active::after { background: #388bfd; }
  .files-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px 10px; border-bottom: 1px solid #21262d; flex-shrink: 0;
    background: linear-gradient(180deg, rgba(28,33,40,0.7) 0%, rgba(22,27,34,0) 100%);
  }
  .files-panel-header h3 { font-size: 13.5px; font-weight: 600; color: #e6edf3; letter-spacing: -0.01em; }
  .files-panel-close {
    background: none; border: none; color: #6e7681; font-size: 18px;
    cursor: pointer; line-height: 1; padding: 4px; border-radius: 6px;
    transition: color 0.15s, background 0.15s;
  }
  .files-panel-close:hover { color: #e6edf3; background: #21262d; }

  /* ── Files toolbar ── */
  .files-toolbar {
    display: flex; align-items: center; gap: 8px; padding: 8px 16px;
    border-bottom: 1px solid #21262d; flex-shrink: 0;
  }
  .files-toolbar .sort-label { font-size: 10px; color: #6e7681; text-transform: uppercase; letter-spacing: 0.5px; }
  .files-toolbar select {
    background: #21262d; border: 1px solid #30363d; color: #c9d1d9;
    font-size: 11.5px; padding: 4px 8px; border-radius: 6px; cursor: pointer;
    font-family: inherit; -webkit-font-smoothing: antialiased;
  }
  .files-toolbar select:focus { outline: none; border-color: #388bfd; }
  .file-type-btn {
    background: #21262d; border: 1px solid #30363d; color: #8b949e;
    font-size: 11px; padding: 3px 8px; border-radius: 6px; cursor: pointer;
    white-space: nowrap; transition: all 0.15s; font-family: 'JetBrains Mono', monospace;
  }
  .file-type-btn:hover { border-color: #388bfd; color: #c9d1d9; }
  .file-type-btn.active { background: rgba(31,111,235,0.12); border-color: #388bfd; color: #58a6ff; }
  .file-ext-badge {
    font-size: 10px; font-family: 'JetBrains Mono', monospace; padding: 1px 5px;
    border-radius: 4px; margin-left: 5px; vertical-align: middle; flex-shrink: 0;
    background: #21262d; border: 1px solid #30363d; color: #6e7681;
  }
  .files-search {
    background: #21262d; border: 1px solid #30363d; color: #e6edf3;
    font-size: 12px; padding: 5px 10px; border-radius: 7px; flex: 1; min-width: 0;
    font-family: inherit; transition: border-color 0.15s;
  }
  .files-search:focus { outline: none; border-color: #388bfd; background: #1c2128; }
  .files-search::placeholder { color: #484f58; }

  /* ── Review progress bar ── */
  .files-review-progress {
    display: flex; align-items: center; gap: 8px;
    padding: 0 16px; border-right: 1px solid #21262d; align-self: stretch; flex-shrink: 0;
    width: 200px;
  }
  .files-review-progress-bar-wrap {
    height: 4px; flex-shrink: 0; display: flex; gap: 2px; align-items: stretch;
  }
  .files-review-progress-segment {
    flex-shrink: 0; border-radius: 3px; overflow: hidden; min-width: 3px; transition: flex 0.3s ease;
  }
  .files-review-progress-segment-fill {
    height: 100%; transition: width 0.3s ease;
  }
  .files-review-progress-label {
    font-size: 11px; font-weight: 600; color: #6e7681; min-width: 28px; text-align: right;
    font-family: 'JetBrains Mono', monospace; transition: color 0.3s;
  }
  .files-review-progress-label.done { color: #3fb950; }

  /* ── Files list ── */
  .files-scroll { flex: 1 1 0%; overflow-y: auto; min-height: 0; }
  .files-scroll::-webkit-scrollbar { width: 5px; }
  .files-scroll::-webkit-scrollbar-track { background: transparent; }
  .files-scroll::-webkit-scrollbar-thumb { background: #2d333b; border-radius: 10px; }
  .file-row {
    display: flex; align-items: stretch; gap: 0; padding: 9px 14px;
    border-bottom: 1px solid #21262d; cursor: pointer; transition: background 0.1s;
    position: relative; z-index: 0;
  }
  .file-row:hover { background: #1c2128; z-index: 10; }
  .file-col {
    display: flex; flex-direction: column; justify-content: center;
    padding: 0 6px; min-width: 0;
  }
  .file-col-label { display: none; }
  .files-header-row {
    display: flex; align-items: center; padding: 4px 8px;
    border-bottom: 1px solid #21262d; font-size: 9px; color: #484f58;
    text-transform: uppercase; letter-spacing: 0.4px; flex-shrink: 0;
    font-weight: 600;
  }
  .file-col-signal { width: 44px; flex-shrink: 0; text-align: center; position: relative; z-index: 1; }
  .file-col-signal .file-col-val { font-weight: 700; font-size: 16px; line-height: 1; cursor: default; }
  .signal-tooltip-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 11px; color: #8b949e; line-height: 1.8; }
  .signal-tooltip-row .label { display: flex; align-items: center; gap: 5px; }
  .signal-tooltip-row .val { color: #e6edf3; font-weight: 600; }
  .file-col-name { flex: 1; }
  .file-name-main {
    display: flex; align-items: center; gap: 6px;
    font-size: 13px; font-weight: 500; color: #e6edf3; overflow: hidden;
  }
  .file-name-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
  .file-name-path { font-size: 11px; color: #484f58; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
  .file-domain-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
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
  .file-col-review { width: 52px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; gap: 4px; }
  .file-metrics-chips { display: flex; gap: 6px; margin-top: 2px; font-size: 10px; font-variant-numeric: tabular-nums; }
  .file-metric-chip { color: #484f58; white-space: nowrap; }
  .file-review-btn {
    width: 22px; height: 22px; border-radius: 5px; border: 1px solid transparent;
    background: transparent; cursor: pointer; color: #484f58;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; line-height: 1; transition: all 0.15s; padding: 0;
  }
  .file-review-btn:hover { background: rgba(13,53,32,0.5); color: #3fb950; border-color: rgba(35,134,54,0.5); }
  .file-review-btn.reviewed { background: rgba(13,53,32,0.7); color: #3fb950; border-color: rgba(35,134,54,0.6); }
  .file-review-next-btn {
    width: 22px; height: 22px; border-radius: 5px; border: 1px solid transparent;
    background: transparent; cursor: pointer; color: #484f58;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; line-height: 1; transition: all 0.15s; padding: 0; flex-shrink: 0;
  }
  .file-review-next-btn:hover { background: rgba(13,53,32,0.5); color: #3fb950; border-color: rgba(35,134,54,0.5); }
  .file-row-deleted { background: rgba(19,6,9,0.8); border-left: 2px solid rgba(218,54,51,0.6); padding-left: 14px; }
  .file-row-deleted:hover { background: rgba(28,11,16,0.9); }
  .file-row-deleted .file-name-text { text-decoration: line-through; text-decoration-color: #f85149; color: #8b949e; }
  .file-row-deleted .file-name-path { opacity: 0.5; }
  .file-deleted-icon { flex-shrink: 0; color: #f85149; opacity: 0.7; }
  .file-section-divider {
    padding: 4px 16px; font-size: 9px; font-weight: 600; letter-spacing: 0.6px;
    text-transform: uppercase; color: #484f58; border-bottom: 1px solid #21262d;
    display: flex; align-items: center; gap: 8px; flex-shrink: 0;
  }
  .file-section-divider::after { content: ''; flex: 1; height: 1px; background: #21262d; }
  .file-section-divider.watched { color: #d29922; }

  /* ── Narrow panel (< 520px) ── */
  .files-panel.narrow .file-col-status,
  .files-panel.narrow .file-metrics-chips { display: none; }
  .files-panel.narrow .file-col-review { width: 48px; }
  .files-panel.narrow .file-review-next-btn { display: none; }
  .files-panel.narrow .file-col-signal { width: 34px; }
  .files-panel.narrow .file-col-signal .file-col-val { font-size: 13px; }
  .files-panel.narrow .file-col-changes { width: 54px; }
  .files-panel.narrow .file-changes { font-size: 11px; }
  .files-panel.narrow .file-col-findings { width: 50px; }

  /* ── Very narrow panel (< 400px) ── */
  .files-panel.very-narrow .file-col-status,
  .files-panel.very-narrow .file-col-findings,
  .files-panel.very-narrow .file-col-changes { display: none; }
  .files-panel.very-narrow .file-col-signal { width: 30px; }
  .files-panel.very-narrow .file-col-signal .file-col-val { font-size: 12px; }
  .files-panel.very-narrow .files-header-row .file-col-signal { display: none; }
  .files-panel.very-narrow .file-name-main { font-size: 12px; }

  /* ── Circular dependencies panel ── */
  .cycles-panel {
    position: absolute; top: 0; right: -100%; height: 100%;
    background: #161b22; border-left: 1px solid #21262d; z-index: 30;
    display: flex; flex-direction: column;
    box-shadow: -8px 0 40px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.03) inset;
    transition: right 0.28s cubic-bezier(0.22,1,0.36,1); width: 420px;
  }
  .cycles-panel.open { right: 0; }

  /* ── Review clusters panel (slides from left) ── */
  .clusters-panel {
    position: absolute; top: 0; left: -100%; height: 100%;
    background: #0d1117; border-right: 1px solid #21262d; z-index: 30;
    display: flex; flex-direction: column;
    box-shadow: 8px 0 40px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.03) inset;
    transition: left 0.28s cubic-bezier(0.22,1,0.36,1); width: 360px;
  }
  .clusters-panel.open { left: 0; }

  /* ── Cluster group card ── */
  .cluster-group { border-bottom: 1px solid #161b22; }
  .cluster-group-btn {
    width: 100%; background: none; border: none; padding: 0; cursor: pointer;
    text-align: left; display: block;
  }
  .cluster-header-inner {
    display: flex; align-items: stretch; transition: background 0.12s;
  }
  .cluster-group-btn:hover .cluster-header-inner { background: rgba(255,255,255,0.04); }
  .cluster-color-bar { width: 3px; flex-shrink: 0; border-radius: 0 2px 2px 0; }
  .cluster-header-content {
    flex: 1; min-width: 0; display: flex; align-items: center;
    justify-content: space-between; padding: 11px 14px 11px 12px; gap: 8px;
  }
  .cluster-header-left { display: flex; align-items: center; gap: 8px; min-width: 0; }
  .cluster-header-title { font-size: 11px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
  .cluster-view-badge {
    font-size: 10px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
    padding: 2px 7px; border-radius: 4px; border: 1px solid; flex-shrink: 0;
    opacity: 0; transition: opacity 0.15s;
  }
  .cluster-group-btn:hover .cluster-view-badge { opacity: 1; }
  .cluster-group-btn.cluster-filter-active .cluster-view-badge { opacity: 1; }
  .cluster-hide-btn {
    background: none; border: none; cursor: pointer; padding: 3px 5px; border-radius: 4px;
    color: #484f58; line-height: 1; flex-shrink: 0; display: flex; align-items: center;
    opacity: 0; transition: opacity 0.15s, color 0.15s, background 0.15s;
  }
  .cluster-group-btn:hover .cluster-hide-btn { opacity: 1; }
  .cluster-hide-btn.is-hidden { opacity: 1; color: #f85149; }
  .cluster-hide-btn:hover { background: rgba(255,255,255,0.08); color: #c9d1d9; }
  .cluster-group.cluster-hidden .cluster-file-row { opacity: 0.35; pointer-events: none; }
  .cluster-group.cluster-hidden .cluster-header-title,
  .cluster-group.cluster-hidden .cycle-pill { opacity: 0.45; }
  .cluster-file-row {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 14px 6px 18px; cursor: pointer; transition: background 0.1s;
    border-top: 1px solid rgba(33,38,45,0.6);
  }
  .cluster-file-row:hover { background: #161b22; }

  /* ── PR Comments panel ── */
  .comments-panel {
    position: absolute; top: 0; left: -100%; height: 100%;
    background: #161b22; border-right: 1px solid #21262d; z-index: 30;
    display: flex; flex-direction: column;
    box-shadow: 8px 0 40px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.03) inset;
    transition: left 0.28s cubic-bezier(0.22,1,0.36,1); width: 460px;
  }
  .comments-panel.open { left: 0; }
  .comments-panel-resize {
    position: absolute; top: 0; right: -4px; width: 8px; height: 100%;
    cursor: col-resize; z-index: 35;
  }
  .comments-panel-resize::after {
    content: ''; position: absolute; top: 0; right: 3px; width: 2px; height: 100%;
    background: transparent; transition: background 0.2s;
  }
  .comments-panel-resize:hover::after, .comments-panel-resize.active::after { background: #388bfd; }
  .comments-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px 10px; border-bottom: 1px solid #21262d; flex-shrink: 0;
    background: linear-gradient(180deg, rgba(28,33,40,0.7) 0%, rgba(22,27,34,0) 100%);
  }
  .comments-panel-header h3 { font-size: 13.5px; font-weight: 600; color: #e6edf3; letter-spacing: -0.01em; display:flex; align-items:center; gap:8px; }
  .comments-panel-close {
    background: none; border: none; color: #6e7681; font-size: 18px;
    cursor: pointer; line-height: 1; padding: 4px; border-radius: 6px;
    transition: color 0.15s, background 0.15s;
  }
  .comments-panel-close:hover { color: #e6edf3; background: #21262d; }
  .comments-scroll { flex: 1 1 0%; overflow-y: auto; min-height: 0; padding: 12px 16px 20px; }
  .comments-scroll::-webkit-scrollbar { width: 5px; }
  .comments-scroll::-webkit-scrollbar-track { background: transparent; }
  .comments-scroll::-webkit-scrollbar-thumb { background: #2d333b; border-radius: 10px; }
  .comment-card {
    background: #1c2128; border: 1px solid #30363d; border-radius: 10px; overflow: hidden;
    margin-bottom: 12px; transition: border-color 0.15s;
  }
  .comment-card.clickable-card { cursor: pointer; }
  .comment-card.clickable-card:hover { border-color: #58a6ff; }
  .comment-card-header {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px; border-bottom: 1px solid #21262d;
    background: rgba(255,255,255,.02);
  }
  .comment-avatar {
    width: 20px; height: 20px; border-radius: 50%; background: #30363d;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 600; color: #8b949e; flex-shrink: 0; overflow: hidden;
  }
  .comment-avatar img { width: 100%; height: 100%; object-fit: cover; }
  .comment-author { font-size: 12px; font-weight: 600; color: #e6edf3; }
  .comment-time { font-size: 11px; color: #6e7681; margin-left: auto; white-space: nowrap; }
  .comment-state-badge { font-size: 10.5px; font-weight: 500; padding: 1px 7px; border-radius: 20px; border: 1px solid; }
  .comment-hide-btn {
    background: none; border: none; color: #484f58; cursor: pointer; font-size: 15px;
    line-height: 1; padding: 1px 5px; border-radius: 4px; flex-shrink: 0; margin-left: 4px;
    transition: color 0.15s, background 0.15s;
  }
  .comment-hide-btn:hover { color: #e6edf3; background: #30363d; }
  .comment-body { padding: 10px 12px; font-size: 12.5px; color: #c9d1d9; line-height: 1.6; word-break: break-word; }
  .comment-body:empty { display: none; }
  .comment-body code { font-family: ui-monospace, monospace; font-size: 11.5px; background: #21262d; color: #79c0ff; padding: 1px 5px; border-radius: 4px; }
  .comment-body strong { color: #e6edf3; font-weight: 600; }
  .comment-body em { font-style: italic; }
  .comment-body a { color: #58a6ff; text-decoration: none; }
  .comment-body a:hover { text-decoration: underline; }
  .comment-body p { margin: 0 0 6px; }
  .comment-body p:last-child { margin-bottom: 0; }
  .comment-body h1, .comment-body h2, .comment-body h3 { color: #e6edf3; font-weight: 600; margin: 8px 0 4px; }
  .comment-body h1 { font-size: 14px; }
  .comment-body h2 { font-size: 13px; }
  .comment-body h3 { font-size: 12.5px; }
  .comment-body ul, .comment-body ol { margin: 4px 0 6px; padding-left: 18px; }
  .comment-body li { margin-bottom: 2px; }
  .comment-body blockquote { border-left: 3px solid #30363d; margin: 6px 0; padding: 2px 10px; color: #8b949e; }
  .comment-body pre { background: #21262d; border: 1px solid #30363d; border-radius: 6px; padding: 10px 12px; overflow-x: auto; margin: 6px 0; }
  .comment-body pre code { background: none; color: #c9d1d9; padding: 0; font-size: 11.5px; }
  .comment-body table { border-collapse: collapse; width: 100%; margin: 6px 0; font-size: 11.5px; }
  .comment-body th, .comment-body td { border: 1px solid #30363d; padding: 5px 10px; text-align: left; }
  .comment-body th { background: #21262d; color: #e6edf3; font-weight: 600; }
  .comment-body tr:nth-child(even) td { background: rgba(255,255,255,.02); }
  .comment-body details { margin: 4px 0; }
  .comment-body summary { cursor: pointer; color: #58a6ff; font-size: 12px; user-select: none; }
  .comment-footer { padding: 4px 12px 8px; }
  .comment-link { font-size: 10.5px; color: #58a6ff; text-decoration: none; opacity: 0.7; transition: opacity 0.15s; }
  .comment-link:hover { opacity: 1; }
  .cycles-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px 10px; border-bottom: 1px solid #21262d; flex-shrink: 0;
    background: linear-gradient(180deg, rgba(28,33,40,0.7) 0%, rgba(22,27,34,0) 100%);
  }
  .cycles-panel-header h3 { font-size: 13.5px; font-weight: 600; color: #e6edf3; letter-spacing: -0.01em; }
  .cycles-panel-close {
    background: none; border: none; color: #6e7681; font-size: 18px;
    cursor: pointer; line-height: 1; padding: 4px; border-radius: 6px;
    transition: color 0.15s, background 0.15s;
  }
  .cycles-panel-close:hover { color: #e6edf3; background: #21262d; }
  .cycles-scroll { flex: 1 1 0%; overflow-y: auto; min-height: 0; padding: 10px 0; }
  .cycles-scroll::-webkit-scrollbar { width: 5px; }
  .cycles-scroll::-webkit-scrollbar-track { background: transparent; }
  .cycles-scroll::-webkit-scrollbar-thumb { background: #2d333b; border-radius: 10px; }
  .cycle-group { margin-bottom: 6px; }
  .cycle-group-header {
    display: flex; align-items: center; gap: 8px;
    padding: 5px 18px; font-size: 10.5px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.6px;
    border-bottom: 1px solid #21262d;
  }
  .cycle-group-header .cycle-pill {
    border-radius: 20px; padding: 1px 8px; font-size: 10px;
  }
  .cycle-file-row {
    display: flex; align-items: center; gap: 10px;
    padding: 7px 18px 7px 26px; cursor: pointer; transition: background 0.1s;
    border-bottom: 1px solid rgba(33,38,45,0.5);
  }
  .cycle-file-row:hover { background: #1c2128; }
  .cycle-file-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
  .cycle-file-info { flex: 1; min-width: 0; }
  .cycle-file-name { font-size: 13px; font-weight: 500; color: #e6edf3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .cycle-file-path { font-size: 11px; color: #484f58; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
  .cycles-empty { padding: 24px 20px; font-size: 13px; color: #6e7681; text-align: center; }

  /* ── Findings panel (only what Tailwind can't express) ── */
  .findings-panel { transition: left 0.28s cubic-bezier(0.22,1,0.36,1); }
  .findings-panel.open { left: 0; }
  .findings-panel-resize::after {
    content: ''; position: absolute; top: 0; right: 3px; width: 2px; height: 100%;
    background: transparent; transition: background 0.2s;
  }
  .findings-panel-resize:hover::after, .findings-panel-resize.active::after { background: #388bfd; }

  /* ── AI Review modal ── */
  .ai-review-overlay {
    position: fixed; inset: 0; z-index: 50;
    background: rgba(1,4,9,0.72); backdrop-filter: blur(3px);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none;
    transition: opacity 0.18s ease;
  }
  .ai-review-overlay.open { opacity: 1; pointer-events: all; }
  .ai-review-panel {
    background: #161b22; border: 1px solid #30363d; border-radius: 12px;
    display: flex; flex-direction: column;
    width: 680px; max-width: calc(100vw - 48px); max-height: calc(100vh - 80px);
    box-shadow: 0 32px 96px rgba(0,0,0,.85), 0 0 0 1px rgba(255,255,255,.04) inset;
    transform: scale(0.96) translateY(8px);
    transition: transform 0.22s cubic-bezier(0.22,1,0.36,1);
  }
  .ai-review-overlay.open .ai-review-panel { transform: scale(1) translateY(0); }
  .ai-review-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px 10px; border-bottom: 1px solid #21262d; flex-shrink: 0;
    background: linear-gradient(180deg, rgba(28,33,40,0.7) 0%, rgba(22,27,34,0) 100%);
  }
  .ai-review-panel-header h3 { font-size: 13.5px; font-weight: 600; color: #e6edf3; letter-spacing: -0.01em; display:flex; align-items:center; gap:8px; }
  .ai-review-close {
    background: none; border: none; color: #6e7681; font-size: 18px;
    cursor: pointer; line-height: 1; padding: 4px; border-radius: 6px;
    transition: color 0.15s, background 0.15s;
  }
  .ai-review-close:hover { color: #e6edf3; background: #21262d; }
  .ai-review-scroll { flex: 1 1 0%; overflow-y: auto; min-height: 0; padding: 18px 20px 24px; }
  .ai-review-scroll::-webkit-scrollbar { width: 5px; }
  .ai-review-scroll::-webkit-scrollbar-track { background: transparent; }
  .ai-review-scroll::-webkit-scrollbar-thumb { background: #2d333b; border-radius: 10px; }
  .ai-review-body { font-size: 13px; color: #c9d1d9; line-height: 1.65; }
  .ai-review-body h2 { font-size: 14px; font-weight: 600; color: #e6edf3; margin: 20px 0 8px; padding-bottom: 6px; border-bottom: 1px solid #21262d; }
  .ai-review-body h2:first-child { margin-top: 0; }
  .ai-review-body h3 { font-size: 12.5px; font-weight: 600; color: #e6edf3; margin: 14px 0 6px; }
  .ai-review-body p { margin: 0 0 10px; }
  .ai-review-body ul, .ai-review-body ol { margin: 0 0 10px; padding-left: 18px; }
  .ai-review-body li { margin-bottom: 4px; }
  .ai-review-body strong { color: #e6edf3; font-weight: 600; }
  .ai-review-body code { font-family: ui-monospace, monospace; font-size: 11.5px; background: #21262d; color: #79c0ff; padding: 1px 5px; border-radius: 4px; }
  .ai-review-body a { color: #58a6ff; text-decoration: none; }
  .ai-review-body a:hover { text-decoration: underline; }

  /* ── Global scrollbars ── */
  ::-webkit-scrollbar { width: 5px; height: 5px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: #30363d; border-radius: 10px; }
  ::-webkit-scrollbar-thumb:hover { background: #484f58; }

</style>
</head>
<body>
<div class="topbar">
  <div class="headline">
    {!! $headlineHtml !!}
    <span class="pr-meta">{{ $fileCount }} files &middot; +{{ $prAdditions }} &minus;{{ $prDeletions }}</span>
  </div>
  <div class="tabs" style="border-right:1px solid #21262d;align-self:stretch;padding:0 12px;border-left:none">
    <button class="tab" id="filesTab" onclick="toggleFilesPanel()">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M1.75 1h8.5c.966 0 1.75.784 1.75 1.75v5.5A1.75 1.75 0 0110.25 10H7.061l-2.574 2.573A.25.25 0 014 12.354V10h-.25A1.75 1.75 0 012 8.25v-5.5C2 1.784 2.784 1 3.75 1zM1.75 2.5a.25.25 0 00-.25.25v5.5c0 .138.112.25.25.25h2.5a.75.75 0 01.75.75v1.19l2.06-2.06a.75.75 0 01.53-.22h3.41a.25.25 0 00.25-.25v-5.5a.25.25 0 00-.25-.25h-8.5z"/></svg>Files
    </button>
    <button class="tab" id="clustersTab" onclick="toggleClustersPanel()" style="display:none">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M2 5.5a3.5 3.5 0 1 1 5.898 2.549 5.508 5.508 0 0 1 3.034 4.084.75.75 0 1 1-1.482.235 4 4 0 0 0-7.9 0 .75.75 0 0 1-1.482-.236A5.507 5.507 0 0 1 3.102 8.05 3.493 3.493 0 0 1 2 5.5ZM11 4a3.001 3.001 0 0 1 2.22 5.018 5.01 5.01 0 0 1 2.56 3.012.749.749 0 0 1-.885.954.752.752 0 0 1-.549-.514 3.507 3.507 0 0 0-2.522-2.372.75.75 0 0 1-.574-.73v-.352a.75.75 0 0 1 .416-.672A1.5 1.5 0 0 0 11 5.5.75.75 0 0 1 11 4Zm-5.5-.5a2 2 0 1 0-.001 3.999A2 2 0 0 0 5.5 3.5Z"/></svg>Clusters
    </button>
    <button class="tab" id="findingsTab" onclick="toggleFindingsPanel()">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm9 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-.25-6.25a.75.75 0 0 0-1.5 0v3.5a.75.75 0 0 0 1.5 0v-3.5z"/></svg>Findings
    </button>
    @if($prCommentCount > 0)
    <button class="tab" id="commentsTab" onclick="toggleCommentsPanel()">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M1.75 1h8.5c.966 0 1.75.784 1.75 1.75v5.5A1.75 1.75 0 0 1 10.25 10H7.061l-2.574 2.573A.25.25 0 0 1 4 12.354V10h-.25A1.75 1.75 0 0 1 2 8.25v-5.5C2 1.784 2.784 1 3.75 1zM1.75 2.5a.25.25 0 0 0-.25.25v5.5c0 .138.112.25.25.25h2.5a.75.75 0 0 1 .75.75v1.19l2.06-2.06a.75.75 0 0 1 .53-.22h3.41a.25.25 0 0 0 .25-.25v-5.5a.25.25 0 0 0-.25-.25h-8.5z"/></svg>Comments <span style="background:#21262d;border:1px solid #30363d;border-radius:20px;padding:0 5px;font-size:10.5px;margin-left:3px">{{ $prCommentCount }}</span>
    </button>
    @endif
    @if($affectedEndpointsCount > 0 || $affectedScheduledJobsCount > 0)
    <button class="tab" id="routesJobsTab" onclick="toggleRoutesJobsPanel()">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Zm4.879-2.773 4.264 2.559a.25.25 0 0 1 0 .428l-4.264 2.559A.25.25 0 0 1 6 10.559V5.442a.25.25 0 0 1 .379-.215Z"/></svg>Routes &amp; Jobs <span style="background:#21262d;border:1px solid #30363d;border-radius:20px;padding:0 5px;font-size:10.5px;margin-left:3px" id="routesJobsTabCount"></span>
    </button>
    @endif
    @if($aiReviewMarkdown)
    <button class="tab" id="aiReviewTab" onclick="toggleAiReviewPanel()">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M8 .25a.75.75 0 0 1 .673.418l1.882 3.815 4.21.612a.75.75 0 0 1 .416 1.279l-3.046 2.97.719 4.192a.751.751 0 0 1-1.088.791L8 12.347l-3.766 1.98a.75.75 0 0 1-1.088-.79l.72-4.194L.818 6.374a.75.75 0 0 1 .416-1.28l4.21-.611L7.327.668A.75.75 0 0 1 8 .25Z"/></svg>AI Review
    </button>
    @endif
  </div>
  <div class="files-review-progress" id="filesReviewProgress">
    <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:3px">
      <div class="files-review-progress-bar-wrap" id="filesProgressBar"></div>
      <div id="filesProgressLegend" style="display:flex;gap:2px"></div>
    </div>
    <span class="files-review-progress-label" id="filesProgressLabel">0%</span>
  </div>
  <div class="topbar-badges">
    {!! $riskBadgeHtml !!}
  </div>
  <div class="tabs">
    <button class="tab tab-orange" id="cyclesTab" onclick="toggleCyclesPanel()" style="display:none">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M8 2a6 6 0 1 0 5.659 8.006.75.75 0 0 1 1.414.494A7.5 7.5 0 1 1 14.5 6.32V5.25a.75.75 0 0 1 1.5 0v3a.75.75 0 0 1-.75.75h-3a.75.75 0 0 1 0-1.5h1.313A6.011 6.011 0 0 0 8 2Z"/></svg>Circular deps
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
      <button class="file-type-btn" id="filterJsJsx" onclick="toggleTypeFilter('js')" title="Show JS / JSX files only">JS/JSX</button>
      <button class="file-type-btn" id="filterPhp" onclick="toggleTypeFilter('php')" title="Show PHP files only">PHP</button>
      <select id="filesSort">
        <option value="signal">Signal</option>
        <option value="severity">Severity</option>
        <option value="changes">Changes</option>
        <option value="name">Name</option>
        <option value="cc">CC</option>
        <option value="mi">MI</option>
        <option value="ce">Ce</option>
        <option value="flog">Flog</option>
      </select>
    </div>
    <div class="files-header-row">
      <div class="file-col file-col-review"></div>
      <div class="file-col file-col-signal">Signal</div>
      <div class="file-col file-col-name"></div>
      <div class="file-col file-col-status">Status</div>
      <div class="file-col file-col-changes">Changes</div>
      <div class="file-col file-col-findings">Findings</div>
    </div>
    <div class="files-scroll" id="filesScroll">
      <div id="filesRows"></div>
    </div>
  </div>

  <div class="findings-panel absolute top-0 left-[-100%] h-full bg-surface border-r border-border-subtle z-[30] flex flex-col shadow-[8px_0_40px_rgba(0,0,0,.6),0_0_0_1px_rgba(255,255,255,.03)_inset]" id="findingsPanel" style="width:580px">
    <div class="findings-panel-resize absolute top-0 right-[-4px] w-2 h-full cursor-col-resize z-[35]" id="findingsPanelResize"></div>
    <div class="flex items-center justify-between px-[18px] pt-[14px] pb-[10px] border-b border-border-subtle shrink-0 bg-gradient-to-b from-[rgba(28,33,40,0.7)] to-transparent">
      <h3 class="text-[13.5px] font-semibold text-fg tracking-[-0.01em] flex items-center gap-2">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" class="shrink-0 opacity-70"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm9 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-.25-6.25a.75.75 0 0 0-1.5 0v3.5a.75.75 0 0 0 1.5 0v-3.5z"/></svg>
        Findings <span class="text-[10.5px] font-medium bg-overlay border border-border-default rounded-[10px] px-[7px] py-[1px] text-fg-muted" id="findingsCountBadge"></span>
      </h3>
      <div class="flex items-center gap-2">
        <button class="bg-transparent border border-border-default rounded-md text-fg-muted text-[11px] px-2 py-[3px] cursor-pointer whitespace-nowrap transition-all font-sans hover:border-fg-subtle hover:text-[#c9d1d9]" id="findingsShowDoneBtn" onclick="toggleFindingsShowDone()">Show done <span id="findingsDoneBadge" style="display:none" class="bg-[#0d3520] text-success rounded-lg px-[5px] py-[1px] ml-[2px] text-[10px]"></span></button>
        <button class="bg-transparent border-0 text-fg-subtle text-lg cursor-pointer leading-none p-1 rounded-md transition-colors hover:text-fg hover:bg-overlay" onclick="toggleFindingsPanel()">&times;</button>
      </div>
    </div>
    <div class="flex items-center gap-2 px-4 py-2 border-b border-border-subtle shrink-0">
      <input type="text" class="files-search" id="findingsSearch" placeholder="Filter findings...">
      <select id="findingsSevFilter" class="bg-overlay border border-border-default text-[#c9d1d9] text-[11.5px] px-2 py-1 rounded-md cursor-pointer font-sans shrink-0 focus:outline-none focus:border-accent">
        <option value="">All severities</option>
        <option value="very_high">Very High+</option>
        <option value="high">High+</option>
        <option value="medium">Medium+</option>
        <option value="low">Low+</option>
        <option value="info">Info+</option>
      </select>
      <select id="findingsTypeFilter" class="bg-overlay border border-border-default text-[#c9d1d9] text-[11.5px] px-2 py-1 rounded-md cursor-pointer font-sans shrink-0 focus:outline-none focus:border-accent">
        <option value="">All types</option>
      </select>
    </div>
    <div class="flex-1 overflow-y-auto min-h-0" id="findingsScroll">
      <div id="findingsRows"></div>
    </div>
  </div>

  <div class="cycles-panel" id="cyclesPanel">
    <div class="cycles-panel-header">
      <h3 style="display:flex;align-items:center;gap:8px">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="#f0883e" style="flex-shrink:0"><path d="M8 2a6 6 0 1 0 5.659 8.006.75.75 0 0 1 1.414.494A7.5 7.5 0 1 1 14.5 6.32V5.25a.75.75 0 0 1 1.5 0v3a.75.75 0 0 1-.75.75h-3a.75.75 0 0 1 0-1.5h1.313A6.011 6.011 0 0 0 8 2Z"/></svg>
        Circular Dependencies
      </h3>
      <button class="cycles-panel-close" onclick="toggleCyclesPanel()">&times;</button>
    </div>
    <div class="cycles-scroll" id="cyclesScroll"></div>
  </div>

  <div class="clusters-panel" id="clustersPanel">
    <div class="cycles-panel-header">
      <h3 style="display:flex;align-items:center;gap:8px">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="#4d96ff" style="flex-shrink:0"><path d="M2 5.5a3.5 3.5 0 1 1 5.898 2.549 5.508 5.508 0 0 1 3.034 4.084.75.75 0 1 1-1.482.235 4 4 0 0 0-7.9 0 .75.75 0 0 1-1.482-.236A5.507 5.507 0 0 1 3.102 8.05 3.493 3.493 0 0 1 2 5.5ZM11 4a3.001 3.001 0 0 1 2.22 5.018 5.01 5.01 0 0 1 2.56 3.012.749.749 0 0 1-.885.954.752.752 0 0 1-.549-.514 3.507 3.507 0 0 0-2.522-2.372.75.75 0 0 1-.574-.73v-.352a.75.75 0 0 1 .416-.672A1.5 1.5 0 0 0 11 5.5.75.75 0 0 1 11 4Zm-5.5-.5a2 2 0 1 0-.001 3.999A2 2 0 0 0 5.5 3.5Z"/></svg>
        Review Clusters
      </h3>
      <button class="cycles-panel-close" onclick="toggleClustersPanel()">&times;</button>
    </div>
    <p style="font-size:12px;color:#6e7681;padding:10px 18px 0;line-height:1.6;flex-shrink:0">Files in the same cluster share dependencies and are best reviewed together.</p>
    <div class="cycles-scroll" id="clustersScroll"></div>
  </div>

  @if($prCommentCount > 0)
  <div class="comments-panel" id="commentsPanel">
    <div class="comments-panel-resize" id="commentsPanelResize"></div>
    <div class="comments-panel-header">
      <h3>
        <svg width="14" height="14" viewBox="0 0 16 16" fill="#58a6ff" style="flex-shrink:0"><path d="M1.75 1h8.5c.966 0 1.75.784 1.75 1.75v5.5A1.75 1.75 0 0 1 10.25 10H7.061l-2.574 2.573A.25.25 0 0 1 4 12.354V10h-.25A1.75 1.75 0 0 1 2 8.25v-5.5C2 1.784 2.784 1 3.75 1zM1.75 2.5a.25.25 0 0 0-.25.25v5.5c0 .138.112.25.25.25h2.5a.75.75 0 0 1 .75.75v1.19l2.06-2.06a.75.75 0 0 1 .53-.22h3.41a.25.25 0 0 0 .25-.25v-5.5a.25.25 0 0 0-.25-.25h-8.5z"/></svg>
        PR Comments
        <span id="commentsPanelCount" style="background:#21262d;border:1px solid #30363d;border-radius:20px;padding:0 6px;font-size:10.5px;font-weight:500;color:#8b949e">{{ $prCommentCount }}</span>
      </h3>
      <div style="display:flex;align-items:center;gap:6px">
        <button id="commentsShowHiddenBtn" style="display:none;background:none;border:1px solid #30363d;border-radius:6px;color:#8b949e;font-size:11px;padding:3px 8px;cursor:pointer;white-space:nowrap;transition:all 0.15s;font-family:inherit">Show hidden <span id="commentsHiddenCount">0</span></button>
        <button class="comments-panel-close" onclick="toggleCommentsPanel()">&times;</button>
      </div>
    </div>
    <div class="comments-scroll" id="commentsScroll"></div>
  </div>
  @endif
  @if($affectedEndpointsCount > 0 || $affectedScheduledJobsCount > 0)
  <div class="files-panel" id="routesJobsPanel" style="width:440px">
    <div class="files-panel-resize" id="routesJobsPanelResize"></div>
    <div class="files-panel-header">
      <h3 style="display:flex;align-items:center;gap:8px">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="#b392f0" style="flex-shrink:0"><path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Zm4.879-2.773 4.264 2.559a.25.25 0 0 1 0 .428l-4.264 2.559A.25.25 0 0 1 6 10.559V5.442a.25.25 0 0 1 .379-.215Z"/></svg>
        Routes &amp; Jobs
        <span style="background:#21262d;border:1px solid #30363d;border-radius:20px;padding:0 6px;font-size:10.5px;font-weight:500;color:#8b949e" id="routesJobsPanelCount"></span>
      </h3>
      <button class="files-panel-close" onclick="toggleRoutesJobsPanel()">&times;</button>
    </div>
    <div style="display:flex;border-bottom:1px solid rgba(255,255,255,.05);flex-shrink:0">
      @if($affectedEndpointsCount > 0)
      <button id="routesJobsInternalTabEndpoints" onclick="switchRoutesJobsTab('endpoints')" style="flex:1;background:none;border:none;border-bottom:2px solid #b392f0;color:#e6edf3;font-size:12px;font-weight:500;padding:9px 12px;cursor:pointer;font-family:inherit;transition:color .15s,border-color .15s;white-space:nowrap">
        Endpoints <span id="routesJobsEndpointsCount" style="background:#21262d;border:1px solid #30363d;border-radius:10px;padding:0 5px;font-size:10px;margin-left:3px">{{ $affectedEndpointsCount }}</span>
      </button>
      @endif
      @if($affectedScheduledJobsCount > 0)
      <button id="routesJobsInternalTabJobs" onclick="switchRoutesJobsTab('jobs')" style="flex:1;background:none;border:none;border-bottom:2px solid {{ $affectedEndpointsCount > 0 ? 'transparent' : '#b392f0' }};color:{{ $affectedEndpointsCount > 0 ? '#8b949e' : '#e6edf3' }};font-size:12px;font-weight:500;padding:9px 12px;cursor:pointer;font-family:inherit;transition:color .15s,border-color .15s;white-space:nowrap">
        Scheduled Jobs <span id="routesJobsJobsCount" style="background:#21262d;border:1px solid #30363d;border-radius:10px;padding:0 5px;font-size:10px;margin-left:3px">{{ $affectedScheduledJobsCount }}</span>
      </button>
      @endif
    </div>
    <div style="border-bottom:1px solid rgba(255,255,255,.05);flex-shrink:0;padding:10px 12px 8px">
      <input id="routesJobsSearch" type="text" placeholder="Search…" autocomplete="off"
        style="width:100%;box-sizing:border-box;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#e6edf3;font-size:12px;padding:6px 10px;outline:none;transition:border-color .15s"
        onfocus="this.style.borderColor='#388bfd'" onblur="this.style.borderColor='#30363d'">
    </div>
    <div id="routesJobsHiddenBar" style="display:none;padding:5px 12px 7px;align-items:center;justify-content:space-between;flex-shrink:0">
      <span id="routesJobsHiddenMsg" style="font-size:11px;color:#6e7681"></span>
      <button id="routesJobsShowHidden" style="background:none;border:none;color:#58a6ff;font-size:11px;cursor:pointer;padding:0;font-family:inherit">Show hidden</button>
    </div>
    <div style="flex:1;overflow-y:auto;min-height:0;padding:0 0 20px" id="routesJobsScroll"></div>
  </div>
  @endif
  @if($aiReviewMarkdown)
  <div class="ai-review-overlay" id="aiReviewOverlay" onclick="closeAiReviewOnBackdrop(event)">
    <div class="ai-review-panel" id="aiReviewPanel">
      <div class="ai-review-panel-header">
        <h3>
          <svg width="14" height="14" viewBox="0 0 16 16" fill="#79c0ff" style="flex-shrink:0"><path d="M8 .25a.75.75 0 0 1 .673.418l1.882 3.815 4.21.612a.75.75 0 0 1 .416 1.279l-3.046 2.97.719 4.192a.751.751 0 0 1-1.088.791L8 12.347l-3.766 1.98a.75.75 0 0 1-1.088-.79l.72-4.194L.818 6.374a.75.75 0 0 1 .416-1.28l4.21-.611L7.327.668A.75.75 0 0 1 8 .25Z"/></svg>
          AI Review
        </h3>
        <button class="ai-review-close" onclick="toggleAiReviewPanel()">&times;</button>
      </div>
      <div class="ai-review-scroll"><div class="ai-review-body" id="aiReviewBody"></div></div>
    </div>
  </div>
  @endif
</div>
<script>
  {!! $wrapperSeverityJs !!}
  const filesNodes = {!! $wrapperNodesJson !!};
  const wrapperAffectedEndpoints = {!! $wrapperAffectedEndpointsJson !!};
  const wrapperAffectedScheduledJobs = {!! $wrapperAffectedScheduledJobsJson !!};

  function sendToIframe(msg) {
    var iframe = document.getElementById('view');
    if (iframe && iframe.contentWindow) iframe.contentWindow.postMessage(msg, '*');
  }

  var clearActiveRouteJobItem = function() { sendToIframe({ type: 'clearHighlight' }); };
  var activeRouteJobHighlight = null;

  // ── Routes & Jobs panel ───────────────────────────────────────────────────
  (function() {
    var hasEndpoints = wrapperAffectedEndpoints.length > 0;
    var hasJobs = wrapperAffectedScheduledJobs.length > 0;
    if (!hasEndpoints && !hasJobs) return;

    var activeTab = hasEndpoints ? 'endpoints' : 'jobs';
    var activeItemIdx = null;
    var searchQuery = '';
    var hiddenItems = new Set();

    var methodColors = { GET:'#3fb950', POST:'#58a6ff', PUT:'#d29922', PATCH:'#d29922', DELETE:'#f85149', OPTIONS:'#8b949e', ANY:'#8b949e' };

    var tabCount = document.getElementById('routesJobsTabCount');
    if (tabCount) tabCount.textContent = wrapperAffectedEndpoints.length + wrapperAffectedScheduledJobs.length;

    var scroll = document.getElementById('routesJobsScroll');
    if (!scroll) return;

    var hiddenBar = document.getElementById('routesJobsHiddenBar');
    var hiddenMsg = document.getElementById('routesJobsHiddenMsg');
    var showHiddenBtn = document.getElementById('routesJobsShowHidden');
    var panelCount = document.getElementById('routesJobsPanelCount');

    window.switchRoutesJobsTab = function(tab) {
      activeTab = tab;
      activeItemIdx = null;
      hiddenItems = new Set();
      activeRouteJobHighlight = null;
      sendToIframe({ type: 'clearHighlight' });
      var epBtn = document.getElementById('routesJobsInternalTabEndpoints');
      var jobBtn = document.getElementById('routesJobsInternalTabJobs');
      if (epBtn) { epBtn.style.color = tab === 'endpoints' ? '#e6edf3' : '#8b949e'; epBtn.style.borderBottomColor = tab === 'endpoints' ? '#b392f0' : 'transparent'; }
      if (jobBtn) { jobBtn.style.color = tab === 'jobs' ? '#e6edf3' : '#8b949e'; jobBtn.style.borderBottomColor = tab === 'jobs' ? '#b392f0' : 'transparent'; }
      renderList();
    };

    function matchesSearch(q) {
      if (!q) return true;
      var haystack = Array.prototype.slice.call(arguments, 1).join(' ').toLowerCase();
      return haystack.indexOf(q) !== -1;
    }

    function renderEndpointsTab() {
      var q = searchQuery.toLowerCase().trim();
      var hiddenCount = hiddenItems.size;
      var total = wrapperAffectedEndpoints.length;
      if (panelCount) panelCount.textContent = hiddenCount ? (total - hiddenCount) + ' of ' + total : total;
      if (hiddenBar) hiddenBar.style.display = hiddenCount > 0 ? 'flex' : 'none';
      if (hiddenMsg && hiddenCount > 0) hiddenMsg.textContent = hiddenCount + ' hidden';

      var groups = {}, groupOrder = [];
      wrapperAffectedEndpoints.forEach(function(ep, i) {
        if (hiddenItems.has(i)) return;
        if (!matchesSearch(q, ep.method, ep.uri, ep.name || '', (ep.middleware || []).join(' '))) return;
        var file = ep.sourceFile ? ep.sourceFile.split('/').pop() : 'routes';
        if (!groups[file]) { groups[file] = []; groupOrder.push(file); }
        groups[file].push({ ep: ep, idx: i });
      });

      var html = '';
      groupOrder.forEach(function(file) {
        var items = groups[file];
        html += '<div style="padding:6px 16px 4px;font-size:10px;font-weight:600;color:#6e7681;letter-spacing:0.06em;text-transform:uppercase;background:rgba(255,255,255,.015);border-bottom:1px solid rgba(255,255,255,.04)">' + file + '</div>';
        items.forEach(function(item) {
          var ep = item.ep, i = item.idx;
          var color = methodColors[ep.method] || '#8b949e';
          var isActive = activeTab === 'endpoints' && activeItemIdx === i;
          var chain = ep.dependencyChain && ep.dependencyChain.length > 1
            ? ep.dependencyChain.map(function(p){ return p.split('/').pop().replace(/\.php$/, ''); }).join(' → ')
            : ep.triggeredByPath.split('/').pop().replace(/\.php$/, '');
          html += '<div data-ep-idx="' + i + '" style="padding:10px 16px;cursor:pointer;transition:background 0.15s;position:relative;'
            + (isActive ? 'background:rgba(179,146,240,0.08);outline:1px solid rgba(179,146,240,0.25);outline-offset:-1px;' : '')
            + 'border-bottom:1px solid rgba(255,255,255,.05)" '
            + 'onmouseenter="this.style.background=\'' + (isActive ? 'rgba(179,146,240,0.1)' : 'rgba(255,255,255,0.03)') + '\';this.querySelector(\'.rj-hide-btn\').style.opacity=\'1\'" '
            + 'onmouseleave="this.style.background=\'' + (isActive ? 'rgba(179,146,240,0.08)' : '') + '\';this.querySelector(\'.rj-hide-btn\').style.opacity=\'0\'">';
          html += '<button class="rj-hide-btn" data-ep-hide-idx="' + i + '" title="Hide" style="position:absolute;top:8px;right:10px;background:none;border:none;color:#6e7681;cursor:pointer;font-size:14px;line-height:1;padding:2px 4px;border-radius:4px;opacity:0;transition:opacity .15s,color .15s" onmouseenter="this.style.color=\'#e6edf3\'" onmouseleave="this.style.color=\'#6e7681\'">&times;</button>';
          html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;padding-right:20px">';
          html += '<span style="font-size:10px;font-weight:600;padding:2px 7px;border-radius:5px;background:' + color + '1a;color:' + color + ';border:1px solid ' + color + '40;letter-spacing:0.05em;flex-shrink:0">' + ep.method + '</span>';
          html += '<code style="font-size:12px;color:#c9d1d9;word-break:break-all;line-height:1.4">' + ep.uri + '</code>';
          if (isActive) html += '<svg width="11" height="11" viewBox="0 0 16 16" fill="#b392f0" style="flex-shrink:0;margin-left:auto"><path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Zm4.879-2.773 4.264 2.559a.25.25 0 0 1 0 .428l-4.264 2.559A.25.25 0 0 1 6 10.559V5.442a.25.25 0 0 1 .379-.215Z"/></svg>';
          html += '</div>';
          if (ep.name) html += '<div style="font-size:11px;color:#6e7681;margin-bottom:2px">' + ep.name + '</div>';
          if (ep.middleware && ep.middleware.length) html += '<div style="font-size:11px;color:#6e7681;margin-bottom:2px">' + ep.middleware.join(', ') + '</div>';
          html += '<div style="font-size:11px;color:#484f58;margin-top:1px" title="' + ep.dependencyChain.join(' → ') + '">via ' + chain + '</div>';
          html += '</div>';
        });
      });
      scroll.innerHTML = html;
    }

    function renderJobsTab() {
      var q = searchQuery.toLowerCase().trim();
      var hiddenCount = hiddenItems.size;
      var total = wrapperAffectedScheduledJobs.length;
      if (panelCount) panelCount.textContent = hiddenCount ? (total - hiddenCount) + ' of ' + total : total;
      if (hiddenBar) hiddenBar.style.display = hiddenCount > 0 ? 'flex' : 'none';
      if (hiddenMsg && hiddenCount > 0) hiddenMsg.textContent = hiddenCount + ' hidden';

      var html = '';
      wrapperAffectedScheduledJobs.forEach(function(job, i) {
        if (hiddenItems.has(i)) return;
        var shortName = job.handlerFqcn.split('\\').pop();
        if (!matchesSearch(q, job.scheduleExpression, job.handlerFqcn, shortName)) return;
        var isActive = activeTab === 'jobs' && activeItemIdx === i;
        var expr = job.scheduleExpression || '->run()';
        var firstMethod = expr.match(/->(\w+)\(/);
        var badgeText = firstMethod ? firstMethod[1] : expr;
        var showFullExpr = expr !== ('->' + badgeText + '()');
        var chain = job.dependencyChain && job.dependencyChain.length > 1
          ? job.dependencyChain.map(function(p){ return p.split('/').pop().replace(/\.php$/, ''); }).join(' → ')
          : job.triggeredByPath.split('/').pop().replace(/\.php$/, '');
        html += '<div data-job-idx="' + i + '" style="padding:10px 16px;cursor:pointer;transition:background 0.15s;position:relative;'
          + (isActive ? 'background:rgba(179,146,240,0.08);outline:1px solid rgba(179,146,240,0.25);outline-offset:-1px;' : '')
          + 'border-bottom:1px solid rgba(255,255,255,.05)" '
          + 'onmouseenter="this.style.background=\'' + (isActive ? 'rgba(179,146,240,0.1)' : 'rgba(255,255,255,0.03)') + '\';this.querySelector(\'.rj-hide-btn\').style.opacity=\'1\'" '
          + 'onmouseleave="this.style.background=\'' + (isActive ? 'rgba(179,146,240,0.08)' : '') + '\';this.querySelector(\'.rj-hide-btn\').style.opacity=\'0\'">';
        html += '<button class="rj-hide-btn" data-job-hide-idx="' + i + '" title="Hide" style="position:absolute;top:8px;right:10px;background:none;border:none;color:#6e7681;cursor:pointer;font-size:14px;line-height:1;padding:2px 4px;border-radius:4px;opacity:0;transition:opacity .15s,color .15s" onmouseenter="this.style.color=\'#e6edf3\'" onmouseleave="this.style.color=\'#6e7681\'">&times;</button>';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;padding-right:20px">';
        html += '<span style="font-size:10px;font-weight:600;padding:2px 7px;border-radius:5px;background:#b392f01a;color:#b392f0;border:1px solid #b392f040;letter-spacing:0.05em;flex-shrink:0">' + badgeText + '</span>';
        html += '<span style="font-size:12.5px;color:#c9d1d9;word-break:break-word;line-height:1.4">' + shortName + '</span>';
        if (isActive) html += '<svg width="11" height="11" viewBox="0 0 16 16" fill="#b392f0" style="flex-shrink:0;margin-left:auto"><path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Zm4.879-2.773 4.264 2.559a.25.25 0 0 1 0 .428l-4.264 2.559A.25.25 0 0 1 6 10.559V5.442a.25.25 0 0 1 .379-.215Z"/></svg>';
        html += '</div>';
        if (showFullExpr) html += '<div style="font-size:11px;color:#6e7681;margin-bottom:2px;font-family:\'JetBrains Mono\',monospace">' + expr + '</div>';
        html += '<div style="font-size:11px;color:#484f58;margin-top:1px" title="' + job.dependencyChain.join(' → ') + '">via ' + chain + '</div>';
        html += '</div>';
      });
      scroll.innerHTML = html;
    }

    var renderList = function() {
      if (activeTab === 'endpoints') renderEndpointsTab();
      else renderJobsTab();
    };

    clearActiveRouteJobItem = function() {
      if (activeItemIdx === null && !activeRouteJobHighlight) return;
      activeItemIdx = null;
      activeRouteJobHighlight = null;
      sendToIframe({ type: 'clearHighlight' });
      renderList();
    };

    if (showHiddenBtn) {
      showHiddenBtn.addEventListener('click', function() {
        hiddenItems = new Set();
        renderList();
      });
    }

    scroll.addEventListener('click', function(e) {
      var epHideBtn = e.target.closest('[data-ep-hide-idx]');
      if (epHideBtn) {
        e.stopPropagation();
        var hideIdx = parseInt(epHideBtn.getAttribute('data-ep-hide-idx'), 10);
        hiddenItems.add(hideIdx);
        if (activeTab === 'endpoints' && activeItemIdx === hideIdx) { activeItemIdx = null; activeRouteJobHighlight = null; sendToIframe({ type: 'clearHighlight' }); }
        renderList(); return;
      }
      var jobHideBtn = e.target.closest('[data-job-hide-idx]');
      if (jobHideBtn) {
        e.stopPropagation();
        var hideIdx = parseInt(jobHideBtn.getAttribute('data-job-hide-idx'), 10);
        hiddenItems.add(hideIdx);
        if (activeTab === 'jobs' && activeItemIdx === hideIdx) { activeItemIdx = null; activeRouteJobHighlight = null; sendToIframe({ type: 'clearHighlight' }); }
        renderList(); return;
      }
      var epRow = e.target.closest('[data-ep-idx]');
      if (epRow && activeTab === 'endpoints') {
        var idx = parseInt(epRow.getAttribute('data-ep-idx'), 10);
        var ep = wrapperAffectedEndpoints[idx];
        if (!ep) return;
        if (activeItemIdx === idx) { activeItemIdx = null; activeRouteJobHighlight = null; sendToIframe({ type: 'clearHighlight' }); }
        else { activeItemIdx = idx; activeRouteJobHighlight = { nodeIds: ep.reachableNodeIds || [], nodeDepths: ep.reachableDepths || {} }; sendToIframe({ type: 'highlightCycle', nodeIds: ep.reachableNodeIds || [], nodeDepths: ep.reachableDepths || {} }); }
        renderList(); return;
      }
      var jobRow = e.target.closest('[data-job-idx]');
      if (jobRow && activeTab === 'jobs') {
        var idx = parseInt(jobRow.getAttribute('data-job-idx'), 10);
        var job = wrapperAffectedScheduledJobs[idx];
        if (!job) return;
        if (activeItemIdx === idx) { activeItemIdx = null; activeRouteJobHighlight = null; sendToIframe({ type: 'clearHighlight' }); }
        else { activeItemIdx = idx; activeRouteJobHighlight = { nodeIds: job.reachableNodeIds || [], nodeDepths: job.reachableDepths || {} }; sendToIframe({ type: 'highlightCycle', nodeIds: job.reachableNodeIds || [], nodeDepths: job.reachableDepths || {} }); }
        renderList(); return;
      }
    });

    var searchEl = document.getElementById('routesJobsSearch');
    if (searchEl) {
      searchEl.addEventListener('input', function() { searchQuery = this.value; renderList(); });
    }

    window.switchRoutesJobsTab(activeTab);
  })();

  function closeAllPanels() {
    // files-panels use inline style for position; others use CSS class only
    document.querySelectorAll('.files-panel.open').forEach(function(p) {
      p.classList.remove('open'); p.style.left = '-100%';
    });
    document.querySelectorAll('.findings-panel, .cycles-panel, .clusters-panel, .comments-panel').forEach(function(p) {
      p.classList.remove('open');
    });
    ['filesTab','routesJobsTab','findingsTab','cyclesTab','clustersTab','commentsTab'].forEach(function(id) {
      var el = document.getElementById(id); if (el) el.classList.remove('active');
    });
  }
  function toggleRoutesJobsPanel() {
    var panel = document.getElementById('routesJobsPanel');
    if (!panel) return;
    var isOpen = panel.classList.contains('open');
    closeAllPanels();
    if (!isOpen) { panel.classList.add('open'); panel.style.left = '0'; var t = document.getElementById('routesJobsTab'); if (t) t.classList.add('active'); }
  }
  const filesAnalysis = {!! $wrapperAnalysisJson !!};
  const filesMetrics = {!! $wrapperMetricsJson !!};
  var sharedFileContents = {!! $sharedFileContentsJson !!};

  function hexA(hex, alpha) {
    var r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
  }

  // ── Circular dependencies ──
  (function() {
    var cycleNodes = filesNodes.filter(function(n) { return n.cycleId != null; });
    if (!cycleNodes.length) return;

    document.getElementById('cyclesTab').style.display = '';

    // Group by cycleId
    var groups = {};
    cycleNodes.forEach(function(n) {
      if (!groups[n.cycleId]) groups[n.cycleId] = [];
      groups[n.cycleId].push(n);
    });

    function renderCycles() {
      var html = '';
      var ids = Object.keys(groups).map(Number).sort(function(a, b) { return a - b; });
      ids.forEach(function(id) {
        var members = groups[id];
        var color = members[0].cycleColor || '#f0883e';
        html += '<div class="cycle-group">';
        html += '<div class="cycle-group-header" style="color:' + color + '">' +
          'Cycle ' + id +
          ' <span class="cycle-pill" style="background:' + hexA(color, 0.15) + ';border:1px solid ' + hexA(color, 0.35) + ';color:' + color + '">' + members.length + ' files</span>' +
          '</div>';
        members.forEach(function(n) {
          var baseName = n.id.replace(/</g, '&lt;');
          var path = n.path.replace(/</g, '&lt;');
          var dot = '<div class="cycle-file-dot" style="background:' + (n.domainColor || '#484f58') + ';border:1.5px dashed ' + color + '"></div>';
          html += '<div class="cycle-file-row" data-node-id="' + n.id.replace(/"/g, '&quot;') + '" data-cycle-color="' + color + '">' +
            dot +
            '<div class="cycle-file-info">' +
              '<div class="cycle-file-name">' + baseName + '</div>' +
              '<div class="cycle-file-path">' + path + '</div>' +
            '</div>' +
            '</div>';
        });
        html += '</div>';
      });
      document.getElementById('cyclesScroll').innerHTML = html;
    }

    // Build nodeId → all sibling nodeIds in same cycle
    var nodeToCycleMembers = {};
    Object.keys(groups).forEach(function(id) {
      var memberIds = groups[id].map(function(n) { return n.id; });
      groups[id].forEach(function(n) { nodeToCycleMembers[n.id] = memberIds; });
    });

    renderCycles();

    var cyclesScrollEl = document.getElementById('cyclesScroll');

    cyclesScrollEl.addEventListener('click', function(e) {
      var row = e.target.closest('.cycle-file-row');
      if (!row) return;
      var nodeId = row.dataset.nodeId;
      toggleCyclesPanel();
      var iframe = document.getElementById('view');
      iframe.contentWindow.postMessage({ type: 'openFile', nodeId: nodeId, fromFiles: false }, '*');
    });

    cyclesScrollEl.addEventListener('mouseover', function(e) {
      var row = e.target.closest('.cycle-file-row');
      if (!row) return;
      var nodeIds = nodeToCycleMembers[row.dataset.nodeId] || [row.dataset.nodeId];
      document.getElementById('view').contentWindow.postMessage({ type: 'highlightCycle', nodeIds: nodeIds }, '*');
    });

    cyclesScrollEl.addEventListener('mouseout', function(e) {
      if (e.target.closest('.cycle-file-row') && !e.relatedTarget?.closest('.cycle-file-row')) {
        document.getElementById('view').contentWindow.postMessage({ type: 'clearHighlight' }, '*');
      }
    });
  })();

  // ── Review clusters ──
  (function() {
    var clusterNodes = filesNodes.filter(function(n) { return !n.isConnected && n.clusterId != null; });
    var groups = {};
    clusterNodes.forEach(function(n) {
      if (!groups[n.clusterId]) groups[n.clusterId] = [];
      groups[n.clusterId].push(n);
    });
    Object.keys(groups).forEach(function(id) { if (groups[id].length <= 1) delete groups[id]; });
    if (!Object.keys(groups).length) return;

    document.getElementById('clustersTab').style.display = '';

    // Map from cluster id → array of nodeIds (built before renderClusters so the
    // click handler can reference it immediately after rendering).
    var clusterNodeIds = {};
    Object.keys(groups).forEach(function(id) {
      clusterNodeIds[id] = groups[id].map(function(n) { return n.id; });
    });

    var wrapperHiddenClusters = new Set();
    var activeFilterClusterId = null;

    function updateActiveBadgeUI() {
      document.querySelectorAll('.cluster-group-btn').forEach(function(btn) {
        var cid = btn.dataset.clusterId;
        var color = btn.dataset.clusterColor || '#4d96ff';
        var badge = btn.querySelector('.cluster-view-badge');
        var isActive = String(activeFilterClusterId) === String(cid);
        btn.classList.toggle('cluster-filter-active', isActive);
        if (badge) {
          badge.innerHTML = isActive ? '&#10003; Viewing' : 'View only &rsaquo;';
          badge.style.background = isActive ? hexA(color, 0.2) : hexA(color, 0.08);
          badge.style.opacity = isActive ? '1' : '';
        }
      });
    }

    // Called from outer filterStateChanged handler to sync badge state
    window._updateClustersFilterUI = function(clusterFilter) {
      activeFilterClusterId = clusterFilter ? String(clusterFilter.clusterId) : null;
      updateActiveBadgeUI();
    };

    var eyeOpenSvg = '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M8 2C4.5 2 1.5 5 0 8c1.5 3 4.5 6 8 6s6.5-3 8-6C14.5 5 11.5 2 8 2zm0 10a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-6.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z"/></svg>';
    var eyeSlashSvg = '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M8 2C4.5 2 1.5 5 0 8c1.5 3 4.5 6 8 6s6.5-3 8-6C14.5 5 11.5 2 8 2zm0 10a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-6.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z"/><line x1="1.5" y1="1.5" x2="14.5" y2="14.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

    function updateClusterHiddenUI() {
      document.querySelectorAll('.cluster-hide-btn').forEach(function(btn) {
        var cid = btn.dataset.clusterId;
        var isHidden = wrapperHiddenClusters.has(cid);
        btn.innerHTML = isHidden ? eyeSlashSvg : eyeOpenSvg;
        btn.title = isHidden ? 'Show cluster on canvas' : 'Hide cluster from canvas';
        btn.classList.toggle('is-hidden', isHidden);
        var group = btn.closest('.cluster-group');
        if (group) group.classList.toggle('cluster-hidden', isHidden);
      });
    }

    function sendHiddenClustersToIframe() {
      document.getElementById('view').contentWindow.postMessage({
        type: 'setHiddenClusters',
        clusterIds: Array.from(wrapperHiddenClusters)
      }, '*');
    }

    function renderClusters() {
      var html = '';
      var ids = Object.keys(groups).map(Number).sort(function(a, b) { return a - b; });
      ids.forEach(function(id) {
        var members = groups[id];
        var color = members[0].clusterColor || '#4d96ff';
        var colorAlpha = hexA(color, 0.18);
        var colorBorder = hexA(color, 0.45);
        var isHidden = wrapperHiddenClusters.has(String(id));
        html += '<div class="cluster-group' + (isHidden ? ' cluster-hidden' : '') + '">';
        html += '<button class="cluster-group-btn" data-cluster-id="' + id + '" data-cluster-color="' + color + '">' +
          '<div class="cluster-header-inner">' +
            '<div class="cluster-color-bar" style="background:' + color + '"></div>' +
            '<div class="cluster-header-content">' +
              '<div class="cluster-header-left">' +
                '<span class="cluster-header-title" style="color:' + color + '">Cluster ' + id + '</span>' +
                '<span class="cycle-pill" style="background:' + colorAlpha + ';border:1px solid ' + colorBorder + ';color:' + color + '">' + members.length + ' files</span>' +
              '</div>' +
              '<div style="display:flex;align-items:center;gap:4px">' +
                '<span class="cluster-view-badge" style="color:' + color + ';border-color:' + colorBorder + ';background:' + hexA(color, 0.08) + '">View only &rsaquo;</span>' +
                '<button class="cluster-hide-btn' + (isHidden ? ' is-hidden' : '') + '" data-cluster-id="' + id + '" title="' + (isHidden ? 'Show cluster on canvas' : 'Hide cluster from canvas') + '">' + (isHidden ? eyeSlashSvg : eyeOpenSvg) + '</button>' +
              '</div>' +
            '</div>' +
          '</div>' +
        '</button>';
        members.forEach(function(n) {
          var baseName = n.id.replace(/</g, '&lt;');
          var path = n.path.replace(/</g, '&lt;');
          var dot = '<div class="cycle-file-dot" style="background:' + (n.domainColor || '#484f58') + ';border:1.5px solid ' + color + '"></div>';
          html += '<div class="cluster-file-row" data-node-id="' + n.id.replace(/"/g, '&quot;') + '" data-cluster-color="' + color + '">' +
            dot +
            '<div class="cycle-file-info">' +
              '<div class="cycle-file-name">' + baseName + '</div>' +
              '<div class="cycle-file-path">' + path + '</div>' +
            '</div>' +
            '</div>';
        });
        html += '</div>';
      });
      document.getElementById('clustersScroll').innerHTML = html;
    }

    var nodeToClusterMembers = {};
    Object.keys(groups).forEach(function(id) {
      var memberIds = groups[id].map(function(n) { return n.id; });
      groups[id].forEach(function(n) { nodeToClusterMembers[n.id] = memberIds; });
    });

    renderClusters();

    var clustersScrollEl = document.getElementById('clustersScroll');

    clustersScrollEl.addEventListener('click', function(e) {
      var hideBtn = e.target.closest('.cluster-hide-btn');
      if (hideBtn) {
        e.stopPropagation();
        var cid = hideBtn.dataset.clusterId;
        if (wrapperHiddenClusters.has(cid)) {
          wrapperHiddenClusters.delete(cid);
        } else {
          wrapperHiddenClusters.add(cid);
        }
        updateClusterHiddenUI();
        sendHiddenClustersToIframe();
        return;
      }
      var btn = e.target.closest('.cluster-group-btn');
      if (btn) {
        var cid = btn.dataset.clusterId;
        var color = btn.dataset.clusterColor;
        toggleClustersPanel();
        if (String(activeFilterClusterId) === String(cid)) {
          activeFilterClusterId = null;
          updateActiveBadgeUI();
          document.getElementById('view').contentWindow.postMessage({ type: 'clearClusterFilter' }, '*');
        } else {
          activeFilterClusterId = String(cid);
          updateActiveBadgeUI();
          document.getElementById('view').contentWindow.postMessage({
            type: 'setClusterFilter',
            clusterId: cid,
            clusterColor: color,
            nodeIds: clusterNodeIds[cid] || []
          }, '*');
        }
        return;
      }
      var row = e.target.closest('.cluster-file-row');
      if (!row) return;
      toggleClustersPanel();
      document.getElementById('view').contentWindow.postMessage({ type: 'openFile', nodeId: row.dataset.nodeId, fromFiles: false }, '*');
    });

    clustersScrollEl.addEventListener('mouseover', function(e) {
      var row = e.target.closest('.cluster-file-row');
      if (!row) return;
      var nodeIds = nodeToClusterMembers[row.dataset.nodeId] || [row.dataset.nodeId];
      document.getElementById('view').contentWindow.postMessage({ type: 'highlightCycle', nodeIds: nodeIds }, '*');
    });

    clustersScrollEl.addEventListener('mouseout', function(e) {
      if (e.target.closest('.cluster-file-row') && !e.relatedTarget?.closest('.cluster-file-row')) {
        document.getElementById('view').contentWindow.postMessage({ type: 'clearHighlight' }, '*');
      }
    });
  })();

  function toggleClustersPanel() {
    var panel = document.getElementById('clustersPanel');
    var isOpen = panel.classList.contains('open');
    if (isOpen) {
      panel.classList.remove('open');
      document.getElementById('clustersTab').classList.remove('active');
      document.getElementById('view').contentWindow.postMessage({ type: 'clearHighlight' }, '*');
    } else {
      clearActiveRouteJobItem();
      closeAllPanels();
      document.getElementById('view').contentWindow.postMessage({ type: 'closePanel' }, '*');
      panel.classList.add('open');
      document.getElementById('clustersTab').classList.add('active');
    }
  }

  function toggleCyclesPanel() {
    var panel = document.getElementById('cyclesPanel');
    var isOpen = panel.classList.contains('open');
    if (isOpen) {
      panel.classList.remove('open');
      document.getElementById('cyclesTab').classList.remove('active');
      document.getElementById('view').contentWindow.postMessage({ type: 'clearHighlight' }, '*');
    } else {
      clearActiveRouteJobItem();
      closeAllPanels();
      document.getElementById('view').contentWindow.postMessage({ type: 'closePanel' }, '*');
      panel.classList.add('open');
      document.getElementById('cyclesTab').classList.add('active');
    }
  }

  @if($prCommentCount > 0)
  (function() {
    var prComments = {!! $prCommentsJson !!};
    var prUrl = {!! json_encode($prUrl) !!};
    var hiddenComments = new Set();

    var stateConfig = {
      'APPROVED':           { label: 'Approved',          bg: '#0d3520', color: '#3fb950', border: '#238636' },
      'CHANGES_REQUESTED':  { label: 'Changes requested', bg: '#3d1214', color: '#f85149', border: '#da3633' },
      'DISMISSED':          { label: 'Dismissed',         bg: '#1c2128', color: '#8b949e', border: '#484f58' },
      'COMMENTED':          { label: 'Commented',         bg: '#1c2128', color: '#8b949e', border: '#484f58' },
    };

    function escHtml(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function inlineFmtComment(s) {
      s = escHtml(s);
      s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
      s = s.replace(/\*(.+?)\*/g, '<em>$1</em>');
      s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
      s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
      return s;
    }
    function isTableRow(line) { return /^\s*\|.+\|\s*$/.test(line); }
    function isSeparatorRow(line) { return /^\s*\|[\s|:-]+\|\s*$/.test(line) && !/[a-zA-Z0-9]/.test(line); }
    function renderTable(rows) {
      if (rows.length < 2) return rows.map(function(r) { return '<p>' + inlineFmtComment(r) + '</p>'; }).join('');
      function splitCells(row) {
        return row.trim().replace(/^\||\|$/g, '').split('|').map(function(c) { return c.trim(); });
      }
      var headers = splitCells(rows[0]);
      var out = '<table><thead><tr>' + headers.map(function(h) { return '<th>' + inlineFmtComment(h) + '</th>'; }).join('') + '</tr></thead><tbody>';
      for (var i = 2; i < rows.length; i++) {
        var cells = splitCells(rows[i]);
        out += '<tr>' + cells.map(function(c) { return '<td>' + inlineFmtComment(c) + '</td>'; }).join('') + '</tr>';
      }
      return out + '</tbody></table>';
    }
    function formatBody(text) {
      if (!text) return '';
      var lines = text.split('\n'), html = '', inUl = false, inOl = false, inPre = false, preLines = [], tableBuf = [];
      function closeList() {
        if (inUl) { html += '</ul>'; inUl = false; }
        if (inOl) { html += '</ol>'; inOl = false; }
      }
      function flushTable() {
        if (!tableBuf.length) return;
        html += renderTable(tableBuf);
        tableBuf = [];
      }
      function flushPre() {
        html += '<pre><code>' + preLines.map(function(l) { return escHtml(l); }).join('\n') + '</code></pre>';
        preLines = []; inPre = false;
      }
      // Passthrough for GitHub-flavoured HTML tags (details, summary, br, hr)
      var allowedHtml = /^<\/?(?:details|summary|br|hr)[\s>]/i;
      var selfClose = /^<(?:br|hr)[^>]*\/?>/i;
      for (var i = 0; i < lines.length; i++) {
        var line = lines[i];
        var trimmed = line.trim();
        if (/^```/.test(trimmed)) {
          if (inPre) { flushPre(); } else { closeList(); flushTable(); inPre = true; }
          continue;
        }
        if (inPre) { preLines.push(line); continue; }
        // Table rows
        if (isTableRow(trimmed)) { closeList(); tableBuf.push(trimmed); continue; }
        if (tableBuf.length) flushTable();
        // Allow safe passthrough HTML
        if (allowedHtml.test(trimmed) || selfClose.test(trimmed) || trimmed === '<details>' || trimmed === '</details>') {
          closeList(); html += trimmed; continue;
        }
        // Handle <summary>...</summary> on one line
        if (/^<summary>/i.test(trimmed)) { closeList(); html += trimmed; continue; }
        if (/^# /.test(line))        { closeList(); html += '<h1>' + inlineFmtComment(line.slice(2)) + '</h1>'; }
        else if (/^## /.test(line))  { closeList(); html += '<h2>' + inlineFmtComment(line.slice(3)) + '</h2>'; }
        else if (/^### /.test(line)) { closeList(); html += '<h3>' + inlineFmtComment(line.slice(4)) + '</h3>'; }
        else if (/^> /.test(line))   { closeList(); html += '<blockquote>' + inlineFmtComment(line.slice(2)) + '</blockquote>'; }
        else if (/^[-*] /.test(line))  { if (!inUl) { closeList(); html += '<ul>'; inUl = true; } html += '<li>' + inlineFmtComment(line.slice(2)) + '</li>'; }
        else if (/^\d+\. /.test(line)) { if (!inOl) { closeList(); html += '<ol>'; inOl = true; } html += '<li>' + inlineFmtComment(line.replace(/^\d+\. /, '')) + '</li>'; }
        else if (trimmed === '') { closeList(); }
        else { closeList(); html += '<p>' + inlineFmtComment(line) + '</p>'; }
      }
      if (inPre) flushPre();
      flushTable();
      closeList();
      return html;
    }

    function relativeTime(iso) {
      if (!iso) return '';
      var d = new Date(iso);
      var now = Date.now();
      var diff = Math.floor((now - d.getTime()) / 1000);
      if (diff < 60)  return 'just now';
      if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
      if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
      if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function renderComments() {
      var visibleCount = 0;
      var html = '';
      prComments.forEach(function(c, idx) {
        var isHidden = hiddenComments.has(idx);
        if (!isHidden) visibleCount++;
        var initials = (c.author || '?').slice(0, 2).toUpperCase();
        var avatarUrl = 'https://github.com/' + encodeURIComponent(c.author || '') + '.png?size=40';
        var stateBadge = '';
        if (c.state && stateConfig[c.state]) {
          var sc = stateConfig[c.state];
          stateBadge = '<span class="comment-state-badge" style="background:' + sc.bg + ';color:' + sc.color + ';border-color:' + sc.border + '">' + sc.label + '</span>';
        }
        var locationBadge = '';
        var isInline = c.type === 'inline' && c.path;
        if (isInline) {
          var fileName = c.path.split('/').pop();
          var lineLabel = c.line ? ':' + c.line : '';
          locationBadge = '<span style="font-size:10px;font-family:ui-monospace,monospace;background:#21262d;border:1px solid #30363d;border-radius:4px;padding:1px 6px;color:#8b949e;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;vertical-align:middle" title="' + escHtml(c.path + lineLabel) + '">' + escHtml(fileName + lineLabel) + '</span>';
        }
        var bodyHtml = formatBody(c.body);
        var footerHtml = c.url ? '<div class="comment-footer"><a class="comment-link" href="' + escHtml(c.url) + '" target="_blank" rel="noopener">View on GitHub ↗</a></div>' : '';
        var reviewUrl = !isInline ? (c.url || prUrl) : '';
        var isReviewLink = !!reviewUrl;
        var inlineAttrs = isInline ? ' data-inline-path="' + escHtml(c.path) + '" data-inline-line="' + (c.line || '') + '"' : '';
        var urlAttr = isReviewLink ? ' data-url="' + escHtml(reviewUrl) + '"' : '';
        var hideBtn = '<button class="comment-hide-btn" data-idx="' + idx + '" title="Hide this comment">&times;</button>';
        html += '<div class="comment-card' + (isInline || isReviewLink ? ' clickable-card' : '') + '"'
          + inlineAttrs
          + urlAttr
          + ' data-comment-idx="' + idx + '"'
          + (isHidden ? ' style="display:none"' : '')
          + '>'
          + '<div class="comment-card-header">'
            + '<div class="comment-avatar"><img src="' + escHtml(avatarUrl) + '" alt="" onerror="this.style.display=\'none\';this.parentNode.textContent=\'' + initials + '\'">'
            + '</div>'
            + '<span class="comment-author">' + escHtml(c.author || 'unknown') + '</span>'
            + stateBadge
            + locationBadge
            + '<span class="comment-time">' + relativeTime(c.createdAt) + '</span>'
            + hideBtn
          + '</div>'
          + (bodyHtml ? '<div class="comment-body">' + bodyHtml + '</div>' : '')
          + footerHtml
          + '</div>';
      });
      document.getElementById('commentsScroll').innerHTML = html;

      // Update visible count badge
      var countEl = document.getElementById('commentsPanelCount');
      if (countEl) countEl.textContent = visibleCount;

      // Update "Show hidden" button
      var showHiddenBtn = document.getElementById('commentsShowHiddenBtn');
      var hiddenCountEl = document.getElementById('commentsHiddenCount');
      if (showHiddenBtn) showHiddenBtn.style.display = hiddenComments.size > 0 ? '' : 'none';
      if (hiddenCountEl) hiddenCountEl.textContent = hiddenComments.size;

      // Wire hide buttons
      document.querySelectorAll('#commentsScroll .comment-hide-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.stopPropagation();
          hiddenComments.add(parseInt(btn.getAttribute('data-idx'), 10));
          renderComments();
        });
      });

      // Wire inline comment cards: close panel then navigate to file + line
      document.querySelectorAll('#commentsScroll .comment-card[data-inline-path]').forEach(function(card) {
        card.addEventListener('click', function(e) {
          if (e.target.closest('a') || e.target.closest('.comment-hide-btn')) return;
          var path = card.getAttribute('data-inline-path');
          var line = parseInt(card.getAttribute('data-inline-line'), 10) || null;
          var node = filesNodes.find(function(n) { return n.path === path; });
          if (!node) return;
          var panel = document.getElementById('commentsPanel');
          if (panel && panel.classList.contains('open')) toggleCommentsPanel();
          document.getElementById('view').contentWindow.postMessage({
            type: 'openFile', nodeId: node.id, fromFiles: false, targetLine: line,
          }, '*');
        });
      });

      // Wire PR-level review comment cards: open GitHub URL in new tab
      document.querySelectorAll('#commentsScroll .comment-card[data-url]').forEach(function(card) {
        card.addEventListener('click', function(e) {
          if (e.target.closest('a') || e.target.closest('.comment-hide-btn')) return;
          window.open(card.getAttribute('data-url'), '_blank', 'noopener');
        });
      });
    }

    renderComments();

    // "Show hidden" button restores all hidden comments
    var showHiddenBtn = document.getElementById('commentsShowHiddenBtn');
    if (showHiddenBtn) {
      showHiddenBtn.addEventListener('click', function() {
        hiddenComments.clear();
        renderComments();
      });
    }

    // ── Comments panel resize ──
    var commentsPanelEl = document.getElementById('commentsPanel');
    var commentsPanelHandle = document.getElementById('commentsPanelResize');
    if (commentsPanelHandle) {
      var commentsPanelResizing = false;
      var commentsPanelWidth = 460;
      commentsPanelEl.style.width = commentsPanelWidth + 'px';
      var commentsResizeOverlay = document.createElement('div');
      commentsResizeOverlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;cursor:col-resize;display:none';
      document.body.appendChild(commentsResizeOverlay);
      commentsPanelHandle.addEventListener('mousedown', function(e) {
        e.preventDefault();
        commentsPanelResizing = true;
        commentsPanelHandle.classList.add('active');
        commentsPanelEl.style.transition = 'none';
        commentsResizeOverlay.style.display = 'block';
      });
      document.addEventListener('mousemove', function(e) {
        if (!commentsPanelResizing) return;
        var contentArea = document.querySelector('.content-area');
        var rect = contentArea.getBoundingClientRect();
        var newW = e.clientX - rect.left;
        newW = Math.max(320, Math.min(newW, rect.width * 0.9));
        commentsPanelWidth = newW;
        commentsPanelEl.style.width = commentsPanelWidth + 'px';
      });
      document.addEventListener('mouseup', function() {
        if (!commentsPanelResizing) return;
        commentsPanelResizing = false;
        commentsPanelHandle.classList.remove('active');
        commentsPanelEl.style.transition = '';
        commentsResizeOverlay.style.display = 'none';
      });
    }

    window.toggleCommentsPanel = function() {
      var panel = document.getElementById('commentsPanel');
      var isOpen = panel.classList.contains('open');
      if (isOpen) {
        panel.classList.remove('open');
        document.getElementById('commentsTab').classList.remove('active');
      } else {
        clearActiveRouteJobItem();
        closeAllPanels();
        panel.classList.add('open');
        document.getElementById('commentsTab').classList.add('active');
      }
    };
  })();
  @endif

  @if($aiReviewMarkdown)
  (function() {
    var aiReviewMd = {!! json_encode($aiReviewMarkdown) !!};

    function escHtml(s) {
      return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function inlineFmt(s) {
      s = escHtml(s);
      s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
      s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
      s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>');
      return s;
    }
    function mdToHtml(md) {
      var lines = md.split('\n'), html = '', inUl = false, inOl = false;
      function closeList() {
        if (inUl) { html += '</ul>'; inUl = false; }
        if (inOl) { html += '</ol>'; inOl = false; }
      }
      for (var i = 0; i < lines.length; i++) {
        var line = lines[i];
        if (/^## /.test(line)) { closeList(); html += '<h2>' + inlineFmt(line.slice(3)) + '</h2>'; }
        else if (/^### /.test(line)) { closeList(); html += '<h3>' + inlineFmt(line.slice(4)) + '</h3>'; }
        else if (/^[-*] /.test(line)) { if (!inUl) { closeList(); html += '<ul>'; inUl = true; } html += '<li>' + inlineFmt(line.slice(2)) + '</li>'; }
        else if (/^\d+\. /.test(line)) { if (!inOl) { closeList(); html += '<ol>'; inOl = true; } html += '<li>' + inlineFmt(line.replace(/^\d+\. /, '')) + '</li>'; }
        else if (line.trim() === '') { closeList(); }
        else { closeList(); html += '<p>' + inlineFmt(line) + '</p>'; }
      }
      closeList();
      return html;
    }

    document.getElementById('aiReviewBody').innerHTML = mdToHtml(aiReviewMd);
  })();

  function toggleAiReviewPanel() {
    var overlay = document.getElementById('aiReviewOverlay');
    var isOpen = overlay.classList.contains('open');
    if (isOpen) {
      overlay.classList.remove('open');
      document.getElementById('aiReviewTab').classList.remove('active');
    } else {
      overlay.classList.add('open');
      document.getElementById('aiReviewTab').classList.add('active');
    }
  }
  function closeAiReviewOnBackdrop(e) {
    if (e.target === document.getElementById('aiReviewOverlay')) toggleAiReviewPanel();
  }
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      var overlay = document.getElementById('aiReviewOverlay');
      if (overlay && overlay.classList.contains('open')) toggleAiReviewPanel();
    }
  });
  @endif

  const layouts = {
    {!! $jsLayoutData !!}
  };

  var initialViewLoaded = false;
  function show(name) {
    document.querySelectorAll('.tab[data-layout]').forEach(t => t.classList.toggle('active', t.dataset.layout === name));
    var iframe = document.getElementById('view');
    iframe.srcdoc = atob(layouts[name]);
    if (initialViewLoaded) pendingFilterApply = true;
    initialViewLoaded = true;
    if (Object.keys(sharedFileContents).length > 0) {
      iframe.addEventListener('load', function() {
        iframe.contentWindow.postMessage({ type: 'injectFileContents', fileContents: sharedFileContents }, '*');
      }, { once: true });
    }
  }

  document.querySelectorAll('.tab[data-layout]').forEach(t => t.addEventListener('click', () => show(t.dataset.layout)));

  function signalColor(score) {
    if (score >= 60) return '#f85149';
    if (score >= 30) return '#d29922';
    if (score >= 10) return '#58a6ff';
    return '#3fb950';
  }

  function valColor(pts) {
    if (pts >= 20) return '#f85149';
    if (pts >= 8) return '#d29922';
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
  var currentFilterState = null;
  var pendingFilterApply = false;
  var changedFiles = filesNodes.filter(function(n) { return !n.isConnected; });
  changedFiles.forEach(function(n) { n._signal = n._signal || 0; });

  var currentSort = 'signal';
  var currentDir = -1; // descending
  var activeTypeFilters = {}; // e.g. { js: true, php: true }
  var hiddenChangeTypes = {}; // mirrors inner.blade.php hiddenChangeTypes

  function toggleTypeFilter(type) {
    if (activeTypeFilters[type]) {
      delete activeTypeFilters[type];
    } else {
      activeTypeFilters = {};
      activeTypeFilters[type] = true;
    }
    document.getElementById('filterJsJsx').classList.toggle('active', !!activeTypeFilters['js']);
    document.getElementById('filterPhp').classList.toggle('active', !!activeTypeFilters['php']);
    renderFileList();
  }

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
      case 'ce': var m = filesMetrics[n.path]; return m && m.coupling != null ? m.coupling : -1;
      case 'flog': var m = filesMetrics[n.path]; return m && m.flog != null ? m.flog : -1;
      case 'status': return n.status;
      default: return 0;
    }
  }

  var signalTips = {};

  // Global signal tooltip element, positioned by JS on mousemove
  var signalTipEl = document.createElement('div');
  signalTipEl.style.cssText = 'position:fixed;display:none;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:10px 12px;box-shadow:0 8px 24px rgba(0,0,0,.5);z-index:9999;min-width:180px;white-space:nowrap;pointer-events:none;';
  document.body.appendChild(signalTipEl);

  function buildSignalTipRows(n, m) {
    var rows = '';
    var sevs = [['very_high', n.veryHighCount], ['high', n.highCount], ['medium', n.mediumCount], ['low', n.lowCount], ['info', n.infoCount]];
    for (var i = 0; i < sevs.length; i++) {
      var sev = sevs[i][0], count = sevs[i][1];
      if (!count) continue;
      var pts = count * (sevScores[sev] || 0);
      rows += '<div class="signal-tooltip-row">'
        + '<span class="label"><span style="width:6px;height:6px;border-radius:50%;background:' + sevColors[sev] + ';display:inline-block;flex-shrink:0"></span>' + sevLabels[sev] + ' &times; ' + count + '</span>'
        + '<span class="val" style="color:' + valColor(pts) + '">+' + pts + '</span>'
        + '</div>';
    }
    var changePts = Math.round(Math.sqrt(n.add + n.del) * 2);
    if (changePts) {
      rows += '<div class="signal-tooltip-row"><span class="label">Lines changed (&plusmn;' + (n.add + n.del) + ')</span><span class="val" style="color:' + valColor(changePts) + '">+' + changePts + '</span></div>';
    }
    if (n._connectionBoost != null && n._connectionBoost > 0) {
      rows += '<div class="signal-tooltip-row"><span class="label" style="display:flex;align-items:center;gap:5px"><span style="width:6px;height:6px;border-radius:50%;background:#58a6ff;display:inline-block;flex-shrink:0"></span>PR connections (&times;' + n._connections + ')</span><span class="val" style="color:#58a6ff">+' + n._connectionBoost + '</span></div>';
    }
    if (n.cycleId != null && n._cycleBoost != null) {
      rows += '<div class="signal-tooltip-row"><span class="label" style="display:flex;align-items:center;gap:5px"><span style="width:6px;height:6px;border-radius:50%;border:1.5px solid ' + (n.cycleColor || '#f0883e') + ';display:inline-block;flex-shrink:0"></span>Circular dependency</span><span class="val" style="color:' + (n.cycleColor || '#f0883e') + '">+' + n._cycleBoost + '</span></div>';
    }
    if (m) {
      if ((m.cc || 0) > 10) {
        var ccPts = Math.round((m.cc - 10) * 2);
        rows += '<div class="signal-tooltip-row"><span class="label">Complexity (CC)</span><span class="val" style="color:' + valColor(ccPts) + '">+' + ccPts + '</span></div>';
      }
      if (m.mi != null && m.mi < 65) {
        var miPts = Math.round((65 - m.mi) * 0.5);
        rows += '<div class="signal-tooltip-row"><span class="label">Maintainability (MI)</span><span class="val" style="color:' + valColor(miPts) + '">+' + miPts + '</span></div>';
      }
    }
    return rows;
  }

  function renderFileList() {
    var filter = (document.getElementById('filesSearch').value || '').toLowerCase();
    var hasTypeFilter = Object.keys(activeTypeFilters).length > 0;
    function matchesFilters(n, includeReviewed) {
      if (!includeReviewed && reviewedFiles.has(n.id)) return false;
      if (hiddenChangeTypes[n.status]) return false;
      if (hasTypeFilter) {
        var ext = (n.ext || '').toLowerCase();
        var match = (activeTypeFilters['js'] && (ext === 'js' || ext === 'jsx'))
                 || (activeTypeFilters['php'] && ext === 'php');
        if (!match) return false;
      }
      if (!filter) return true;
      return n.id.toLowerCase().indexOf(filter) !== -1 || n.path.toLowerCase().indexOf(filter) !== -1;
    }
    var list = changedFiles.filter(function(n) { return matchesFilters(n, showReviewed); });
    var progressList = changedFiles.filter(function(n) { return matchesFilters(n, true); });

    list.sort(function(a, b) {
      // Watched files always surface above unwatched, regardless of sort criterion
      if (!!a.watched !== !!b.watched) return a.watched ? -1 : 1;
      var va = getSort(a), vb = getSort(b);
      if (typeof va === 'string') return currentDir * va.localeCompare(vb);
      return currentDir * (va - vb);
    });

    document.getElementById('filesSort').value = currentSort;

    signalTips = {};
    var watchedCount = list.filter(function(n) { return !!n.watched; }).length;
    var html = '';
    var inWatchedSection = false, watchedSectionClosed = false;
    for (var i = 0; i < list.length; i++) {
      var n = list[i];
      if (watchedCount > 0) {
        if (!inWatchedSection && n.watched) {
          inWatchedSection = true;
          html += '<div class="file-section-divider watched">&#9670; Watched</div>';
        } else if (inWatchedSection && !n.watched && !watchedSectionClosed) {
          watchedSectionClosed = true;
          html += '<div class="file-section-divider">Other files</div>';
        }
      }
      var m = filesMetrics[n.path] || {};
      var st = statusStyles[n.status] || statusStyles.modified;
      var rc = signalColor(n._signal);

      var sevHtml = '';
      if (n.veryHighCount > 0) sevHtml += '<span class="file-sev-dot"><span style="background:' + sevColors.very_high + '"></span>' + n.veryHighCount + '</span>';
      if (n.highCount > 0) sevHtml += '<span class="file-sev-dot"><span style="background:' + sevColors.high + '"></span>' + n.highCount + '</span>';
      if (n.mediumCount > 0) sevHtml += '<span class="file-sev-dot"><span style="background:' + sevColors.medium + '"></span>' + n.mediumCount + '</span>';
      if (n.lowCount > 0) sevHtml += '<span class="file-sev-dot"><span style="background:' + sevColors.low + '"></span>' + n.lowCount + '</span>';
      if (!sevHtml) sevHtml = '<span style="color:#484f58">&mdash;</span>';

      var isReviewed = reviewedFiles.has(n.id);
      var b = m.before || null;
      var ccArrow = '', miArrow = '', flogArrow = '';
      if (b != null) {
        if (b.cc != null && m.cc != null) {
          var ccDelta = m.cc - b.cc;
          ccArrow = ccDelta > 0 ? '<span style="color:#f85149;font-size:9px;margin-left:2px">\u2191</span>'
                  : ccDelta < 0 ? '<span style="color:#3fb950;font-size:9px;margin-left:2px">\u2193</span>'
                  : '';
        }
        if (b.mi != null && m.mi != null) {
          var miDelta = m.mi - b.mi;
          miArrow = miDelta > 0 ? '<span style="color:#3fb950;font-size:9px;margin-left:2px">\u2191</span>'
                  : miDelta < 0 ? '<span style="color:#f85149;font-size:9px;margin-left:2px">\u2193</span>'
                  : '';
        }
        if (b.flog != null && m.flog != null) {
          var flogDelta = Math.round((m.flog - b.flog) * 10) / 10;
          flogArrow = flogDelta > 0 ? '<span style="color:#f85149;font-size:9px;margin-left:2px">\u2191</span>'
                    : flogDelta < 0 ? '<span style="color:#3fb950;font-size:9px;margin-left:2px">\u2193</span>'
                    : '';
        }
      }

      var chipsHtml = '';
      var chips = [];
      if (n.cycleId != null) {
        chips.push('<span class="file-metric-chip" style="color:' + (n.cycleColor || '#f0883e') + ';font-weight:600">&#8635; Cycle ' + n.cycleId + '</span>');
      }
      if (m.cc != null) {
        var ccChipColor = m.cc >= 20 ? '#f85149' : m.cc >= 10 ? '#d29922' : '#484f58';
        chips.push('<span class="file-metric-chip" style="color:' + ccChipColor + '">cc ' + m.cc + ccArrow + '</span>');
      }
      if (m.mi != null) {
        var miChipColor = m.mi < 65 ? '#f85149' : m.mi < 85 ? '#d29922' : '#484f58';
        chips.push('<span class="file-metric-chip" style="color:' + miChipColor + '">mi ' + Math.round(m.mi) + '%' + miArrow + '</span>');
      }
      if (m.bugs != null) {
        var bugColor = m.bugs > 0.1 ? '#f85149' : m.bugs > 0.05 ? '#d29922' : '#484f58';
        chips.push('<span class="file-metric-chip" style="color:' + bugColor + '">bugs ' + m.bugs.toFixed(2) + '</span>');
      }
      if (m.coupling != null) {
        var cplColor = m.coupling > 15 ? '#f85149' : m.coupling > 8 ? '#d29922' : '#484f58';
        chips.push('<span class="file-metric-chip" style="color:' + cplColor + '">ce ' + m.coupling + '</span>');
      }
      if (m.flog != null) {
        var flogChipColor = m.flog >= 60 ? '#f85149' : m.flog >= 30 ? '#d29922' : '#484f58';
        chips.push('<span class="file-metric-chip" style="color:' + flogChipColor + '">flog ' + m.flog + flogArrow + '</span>');
      }
      if (m.lloc != null) {
        chips.push('<span class="file-metric-chip">loc ' + m.lloc + '</span>');
      }
      if (chips.length) chipsHtml = '<div class="file-metrics-chips">' + chips.join('<span style="color:#30363d">|</span>') + '</div>';
      var tipRows = buildSignalTipRows(n, filesMetrics[n.path] || null);
      if (tipRows) signalTips[n.id] = tipRows;
      var isDeleted = n.status === 'deleted';
      var deletedIcon = isDeleted ? '<svg class="file-deleted-icon" width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M11 1.75V3h2.25a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75ZM4.496 6.675l.66 6.6a.25.25 0 0 0 .249.225h5.19a.25.25 0 0 0 .249-.225l.66-6.6a.75.75 0 0 1 1.492.149l-.66 6.6A1.748 1.748 0 0 1 10.595 15h-5.19a1.75 1.75 0 0 1-1.741-1.575l-.66-6.6a.75.75 0 1 1 1.492-.15ZM6.5 1.75V3h3V1.75a.25.25 0 0 0-.25-.25h-2.5a.25.25 0 0 0-.25.25Z"/></svg>' : '';
      html += '<div class="file-row' + (isDeleted ? ' file-row-deleted' : '') + '" data-node-id="' + n.id.replace(/"/g, '&quot;') + '">' +
        '<div class="file-col file-col-review"><button class="file-review-btn' + (isReviewed ? ' reviewed' : '') + '" data-node-id="' + n.id.replace(/"/g, '&quot;') + '" title="' + (isReviewed ? 'Unmark reviewed' : 'Mark as reviewed') + '">' + (isReviewed ? '&#10003;' : '&#9744;') + '</button><button class="file-review-next-btn" data-node-id="' + n.id.replace(/"/g, '&quot;') + '" title="Mark as reviewed &amp; open next unreviewed file">&#8594;</button></div>' +
        '<div class="file-col file-col-signal"><span class="file-col-label">Signal</span><span class="file-col-val" style="color:' + rc + '">' + n._signal + '</span></div>' +
        '<div class="file-col file-col-name">' +
          '<div class="file-name-main"><span class="file-domain-dot" style="background:' + (n.domainColor || '#8b949e') + '"></span>' + deletedIcon + '<span class="file-name-text">' + n.id.replace(/</g, '&lt;') + '</span>' + (n.ext ? '<span class="file-ext-badge">.' + n.ext + '</span>' : '') + (n.watched ? ' <span style="color:#d29922;font-size:10px" title="Watched' + (n.watchReason ? ': ' + n.watchReason : '') + '">&#9670;</span>' : '') + '</div>' +
          '<div class="file-name-path">' + n.path.replace(/</g, '&lt;') + '</div>' +
          chipsHtml +
        '</div>' +
        '<div class="file-col file-col-status"><span class="file-col-label">Status</span><span class="file-col-val" style="color:' + st[1] + '">' + st[3] + '</span></div>' +
        '<div class="file-col file-col-changes"><span class="file-col-label">Changes</span><span class="file-changes"><span class="add">+' + n.add + '</span> <span class="del">&minus;' + n.del + '</span></span></div>' +
        '<div class="file-col file-col-findings"><span class="file-col-label">Findings</span><span class="file-sev">' + sevHtml + '</span></div>' +
        '</div>';
    }
    document.getElementById('filesRows').innerHTML = html;
    updateReviewProgress(progressList);
  }

  // ── Show/hide reviewed ──
  var progressTypeColors = {
    js:    { fill: '#d4a72c', bg: 'rgba(212,167,44,0.18)' },
    ts:    { fill: '#3d8fd1', bg: 'rgba(61,143,209,0.18)' },
    php:   { fill: '#8892bf', bg: 'rgba(136,146,191,0.18)' },
    other: { fill: '#6e7681', bg: 'rgba(110,118,129,0.15)' },
  };

  function extToGroup(ext) {
    if (ext === 'js' || ext === 'jsx') return 'js';
    if (ext === 'ts' || ext === 'tsx') return 'ts';
    if (ext === 'php') return 'php';
    return 'other';
  }

  function updateReviewProgress(list) {
    var groups = {};
    var grandTotal = 0, grandReviewed = 0;
    list.forEach(function(n) {
      var key = extToGroup((n.ext || '').toLowerCase());
      if (!groups[key]) groups[key] = { total: 0, reviewed: 0 };
      var sig = n._signal || 0;
      groups[key].total += sig;
      grandTotal += sig;
      if (reviewedFiles.has(n.id)) {
        groups[key].reviewed += sig;
        grandReviewed += sig;
      }
    });

    var order = ['js', 'ts', 'php', 'other'];
    var barHtml = '', legendHtml = '';
    order.forEach(function(key) {
      if (!groups[key] || !groups[key].total) return;
      var g = groups[key];
      var c = progressTypeColors[key];
      var fillPct = g.total > 0 ? (g.reviewed / g.total * 100).toFixed(1) : '0.0';
      barHtml += '<div class="files-review-progress-segment"'
               + ' style="flex:' + g.total + ';background:' + c.bg + '"'
               + ' title="' + key.toUpperCase() + ': ' + fillPct + '% reviewed">'
               + '<div class="files-review-progress-segment-fill"'
               + ' style="width:' + fillPct + '%;background:' + c.fill + '"></div>'
               + '</div>';
      legendHtml += '<span style="flex:' + g.total + ';min-width:0;overflow:hidden;display:flex;align-items:center;gap:3px;white-space:nowrap">'
                  + '<span style="font-size:9px;font-family:\'JetBrains Mono\',monospace;font-weight:600;color:' + c.fill + ';opacity:0.8">' + key.toUpperCase() + '</span>'
                  + '<span style="font-size:9px;font-family:\'JetBrains Mono\',monospace;color:' + c.fill + ';opacity:0.6">' + fillPct + '%</span>'
                  + '</span>';
    });
    document.getElementById('filesProgressBar').innerHTML = barHtml;
    document.getElementById('filesProgressLegend').innerHTML = legendHtml;

    var pct = grandTotal > 0 ? (grandReviewed / grandTotal * 100).toFixed(1) : '0.0';
    var label = document.getElementById('filesProgressLabel');
    label.textContent = pct + '%';
    label.classList.toggle('done', grandTotal > 0 && grandReviewed >= grandTotal);
  }

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

  function markReviewedAndOpenNext(nodeId) {
    if (!reviewedFiles.has(nodeId)) {
      reviewedFiles.add(nodeId);
      document.getElementById('view').contentWindow.postMessage({ type: 'markFileReviewed', nodeId: nodeId }, '*');
      updateReviewedBadge();
      renderFileList();
    }
    var next = null;
    var bestSignal = -Infinity;
    for (var i = 0; i < changedFiles.length; i++) {
      var f = changedFiles[i];
      if (f.id === nodeId) continue;
      if (reviewedFiles.has(f.id)) continue;
      if ((f._signal || 0) > bestSignal) {
        bestSignal = f._signal || 0;
        next = f;
      }
    }
    if (next) {
      document.getElementById('view').contentWindow.postMessage({ type: 'openFile', nodeId: next.id, fromFiles: true }, '*');
    }
  }

  // ── Toggle panel ──
  function toggleFilesPanel() {
    var panel = document.getElementById('filesPanel');
    var isOpen = panel.classList.contains('open');
    if (isOpen) {
      panel.classList.remove('open');
      document.getElementById('filesTab').classList.remove('active');
    } else {
      clearActiveRouteJobItem();
      closeAllPanels();
      panel.classList.add('open');
      panel.style.left = '';
      document.getElementById('filesTab').classList.add('active');
      renderFileList();
    }
  }

  // ── Signal tooltip mouse tracking ──
  document.getElementById('filesRows').addEventListener('mousemove', function(e) {
    var sigCol = e.target.closest('.file-col-signal');
    if (!sigCol) { signalTipEl.style.display = 'none'; return; }
    var row = sigCol.closest('.file-row');
    if (!row) { signalTipEl.style.display = 'none'; return; }
    var tip = signalTips[row.dataset.nodeId];
    if (!tip) { signalTipEl.style.display = 'none'; return; }
    signalTipEl.innerHTML = tip;
    signalTipEl.style.display = 'block';
    var x = e.clientX + 18;
    var y = e.clientY + 8;
    var tipW = signalTipEl.offsetWidth;
    var tipH = signalTipEl.offsetHeight;
    if (x + tipW > window.innerWidth - 8) x = e.clientX - tipW - 8;
    if (y + tipH > window.innerHeight - 8) y = window.innerHeight - tipH - 8;
    signalTipEl.style.left = x + 'px';
    signalTipEl.style.top = y + 'px';
  });
  document.getElementById('filesRows').addEventListener('mouseleave', function() {
    signalTipEl.style.display = 'none';
  });

  // ── Wire events ──
  document.getElementById('filesSearch').addEventListener('input', renderFileList);
  document.getElementById('filesSort').addEventListener('change', function() {
    currentSort = this.value;
    currentDir = (currentSort === 'name' || currentSort === 'mi') ? 1 : -1;
    renderFileList();
  });
  document.getElementById('filesRows').addEventListener('click', function(e) {
    var nextBtn = e.target.closest('.file-review-next-btn');
    if (nextBtn) {
      e.stopPropagation();
      markReviewedAndOpenNext(nextBtn.dataset.nodeId);
      return;
    }
    var btn = e.target.closest('.file-review-btn');
    if (btn) {
      e.stopPropagation();
      toggleReviewFromRow(btn.dataset.nodeId);
      return;
    }
    var row = e.target.closest('.file-row');
    if (!row) return;
    var nodeId = row.dataset.nodeId;
    var iframe = document.getElementById('view');
    iframe.contentWindow.postMessage({ type: 'openFile', nodeId: nodeId, fromFiles: true }, '*');
  });

  window.addEventListener('message', function(e) {
    if (!e.data) return;
    if (e.data.type === 'openCyclesPanel') {
      var cp = document.getElementById('cyclesPanel');
      if (!cp.classList.contains('open')) toggleCyclesPanel();
      // Scroll to and flash the target row after the panel slides in
      setTimeout(function() {
        var targetRow = null;
        document.querySelectorAll('.cycle-file-row').forEach(function(r) {
          if (r.dataset.nodeId === e.data.nodeId) targetRow = r;
        });
        if (!targetRow) return;
        targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var flashColor = targetRow.dataset.cycleColor || '#f0883e';
        targetRow.style.transition = 'background 0.15s';
        targetRow.style.background = hexA(flashColor, 0.2);
        setTimeout(function() { targetRow.style.background = ''; }, 1200);
      }, 280);
    }
    if (e.data.type === 'openClustersPanel') {
      var clp = document.getElementById('clustersPanel');
      if (!clp.classList.contains('open')) toggleClustersPanel();
      setTimeout(function() {
        var targetRow = null;
        document.querySelectorAll('.cluster-file-row').forEach(function(r) {
          if (r.dataset.nodeId === e.data.nodeId) targetRow = r;
        });
        if (!targetRow) return;
        targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var flashColor = targetRow.dataset.clusterColor || '#4d96ff';
        targetRow.style.transition = 'background 0.15s';
        targetRow.style.background = hexA(flashColor, 0.2);
        setTimeout(function() { targetRow.style.background = ''; }, 1200);
      }, 280);
    }
    if (e.data.type === 'backToFiles') {
      var iframe = document.getElementById('view');
      iframe.contentWindow.postMessage({ type: 'closePanel' }, '*');
    }
    if (e.data.type === 'panelOpened') {
      var cyclesPanelEl = document.getElementById('cyclesPanel');
      if (cyclesPanelEl.classList.contains('open')) {
        cyclesPanelEl.classList.remove('open');
        document.getElementById('cyclesTab').classList.remove('active');
        document.getElementById('view').contentWindow.postMessage({ type: 'clearHighlight' }, '*');
      }
      var clustersPanelEl = document.getElementById('clustersPanel');
      if (clustersPanelEl.classList.contains('open')) {
        clustersPanelEl.classList.remove('open');
        document.getElementById('clustersTab').classList.remove('active');
        document.getElementById('view').contentWindow.postMessage({ type: 'clearHighlight' }, '*');
      }
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
    if (e.data.type === 'fileReviewedOpenNext') {
      markReviewedAndOpenNext(e.data.nodeId);
    }
    if (e.data.type === 'fileUnreviewed') {
      reviewedFiles.delete(e.data.nodeId);
      updateReviewedBadge();
      if (filesPanelEl.classList.contains('open')) renderFileList();
    }
    if (e.data.type === 'changeTypeFilterChanged') {
      hiddenChangeTypes = e.data.hiddenChangeTypes;
      if (filesPanelEl.classList.contains('open')) renderFileList();
    }
    if (e.data.type === 'filterStateChanged') {
      currentFilterState = e.data.state;
      if (typeof window._updateClustersFilterUI === 'function') {
        window._updateClustersFilterUI(e.data.state.clusterFilter || null);
      }
    }
    if (e.data.type === 'visibleNodesChanged') {
      if (pendingFilterApply && currentFilterState) {
        pendingFilterApply = false;
        var state = Object.assign({}, currentFilterState, { reviewedNodes: Array.from(reviewedFiles) });
        document.getElementById('view').contentWindow.postMessage({ type: 'applyFilters', state: state }, '*');
      }
      if (activeRouteJobHighlight) {
        document.getElementById('view').contentWindow.postMessage({ type: 'highlightCycle', nodeIds: activeRouteJobHighlight.nodeIds, nodeDepths: activeRouteJobHighlight.nodeDepths }, '*');
      }
    }
  });

  // ── Metrics tooltip: click file to open it ──
  document.addEventListener('click', function(e) {
    var row = e.target.closest('.metrics-tooltip tr[data-path]');
    if (!row) return;
    var path = row.dataset.path;
    var node = filesNodes.find(function(n) { return n.path === path; });
    if (!node) return;
    var badge = e.target.closest('.metrics-badge');
    if (badge) {
      badge.classList.add('tooltip-hidden');
      badge.addEventListener('mouseleave', function clear() {
        badge.classList.remove('tooltip-hidden');
        badge.removeEventListener('mouseleave', clear);
      });
    }
    var iframe = document.getElementById('view');
    iframe.contentWindow.postMessage({ type: 'openFile', nodeId: node.id, fromFiles: false }, '*');
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
    var newW = e.clientX - rect.left;
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

  // ── Findings panel ──────────────────────────────────────────────────────────
  (function() {
    // Build path → node lookup
    var filePathToNode = {};
    filesNodes.forEach(function(n) { if (n.path) filePathToNode[n.path] = n; });

    var sevOrder = ['very_high', 'high', 'medium', 'low', 'info'];

    // Flatten all findings across all files into one array, sorted by severity
    var allFindings = [];
    Object.keys(filesAnalysis).forEach(function(path) {
      var findings = filesAnalysis[path];
      if (!findings || !findings.length) return;
      var node = filePathToNode[path] || null;
      findings.forEach(function(f) {
        allFindings.push({
          severity: f.severity || 'info',
          category: f.category || '',
          description: f.description || '',
          location: f.location || null,
          line: f.line || null,
          filePath: path,
          node: node,
        });
      });
    });

    allFindings.sort(function(a, b) {
      var ai = sevOrder.indexOf(a.severity), bi = sevOrder.indexOf(b.severity);
      if (ai === -1) ai = 99;
      if (bi === -1) bi = 99;
      return ai - bi;
    });

    // Populate type filter with categories present in this report
    (function() {
      var cats = [];
      allFindings.forEach(function(f) { if (f.category && cats.indexOf(f.category) === -1) cats.push(f.category); });
      cats.sort();
      var typeSelect = document.getElementById('findingsTypeFilter');
      if (cats.length <= 1) { typeSelect.style.display = 'none'; return; }
      cats.forEach(function(cat) {
        var opt = document.createElement('option');
        opt.value = cat;
        opt.textContent = catLabel(cat);
        typeSelect.appendChild(opt);
      });
    }());

    // ── Stable key for each finding (used for localStorage persistence) ──
    // Scope per-report using the first few node IDs so different reports don't share state.
    var _scope = filesNodes.slice(0, 4).map(function(n) { return n.id; }).join('|').replace(/[^a-z0-9|]/gi, '_').slice(0, 60);
    var storageKey = 'findings_done_' + _scope;

    function findingKey(f) {
      return f.filePath + '\x01' + f.category + '\x01' + (f.line || '') + '\x01' + f.description.slice(0, 120);
    }

    // Load persisted done set from localStorage
    var doneFindings = new Set();
    try {
      var stored = localStorage.getItem(storageKey);
      if (stored) JSON.parse(stored).forEach(function(k) { doneFindings.add(k); });
    } catch(e) {}

    function saveDone() {
      try { localStorage.setItem(storageKey, JSON.stringify(Array.from(doneFindings))); } catch(e) {}
    }

    var showDone = false;

    function updateDoneBadge() {
      var badge = document.getElementById('findingsDoneBadge');
      var btn = document.getElementById('findingsShowDoneBtn');
      badge.textContent = doneFindings.size;
      badge.style.display = doneFindings.size > 0 ? 'inline' : 'none';
      // Toggle active appearance via Tailwind classes (no custom CSS needed)
      btn.classList.toggle('bg-[#0d3520]', showDone);
      btn.classList.toggle('text-success', showDone);
      btn.classList.toggle('border-[#238636]', showDone);
      btn.classList.toggle('text-fg-muted', !showDone);
      btn.classList.toggle('border-border-default', !showDone);
    }

    function toggleFindingsShowDone() {
      showDone = !showDone;
      updateDoneBadge();
      renderFindingsList();
    }

    function escF(s) {
      return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function catLabel(cat) { return (cat || '').replace(/_/g, ' '); }
    function basename(path) { return (path || '').split('/').pop(); }
    function dirname(path) { var p = (path || '').split('/'); p.pop(); return p.join('/'); }

    function renderFindingsList() {
      var filter = (document.getElementById('findingsSearch').value || '').toLowerCase();
      var sevFilter = document.getElementById('findingsSevFilter').value;
      var typeFilter = document.getElementById('findingsTypeFilter').value;

      var list = allFindings.filter(function(f) {
        var isDone = doneFindings.has(findingKey(f));
        if (isDone && !showDone) return false;
        if (sevFilter && sevScores[f.severity] < sevScores[sevFilter]) return false;
        if (typeFilter && f.category !== typeFilter) return false;
        if (!filter) return true;
        return f.description.toLowerCase().indexOf(filter) !== -1
            || f.filePath.toLowerCase().indexOf(filter) !== -1
            || f.category.toLowerCase().indexOf(filter) !== -1
            || (f.location || '').toLowerCase().indexOf(filter) !== -1;
      });

      // Count badge shows only active (non-done) findings
      var activeCount = list.filter(function(f) { return !doneFindings.has(findingKey(f)); }).length;
      document.getElementById('findingsCountBadge').textContent = activeCount + (showDone && doneFindings.size > 0 ? ' + ' + doneFindings.size + ' done' : '');

      var html = '';
      var lastSev = null;

      list.forEach(function(f) {
        var sev = f.severity;
        var color = sevColors[sev] || '#8b949e';
        var key = findingKey(f);
        var isDone = doneFindings.has(key);

        if (sev !== lastSev) {
          var isFirstHeader = lastSev === null;
          lastSev = sev;
          var sevCount = list.filter(function(x) { return x.severity === sev; }).length;
          html += '<div class="flex items-center gap-[7px] px-4 py-[5px] text-[9px] font-bold uppercase tracking-[0.6px] border-b border-border-subtle shrink-0 bg-surface sticky top-0 z-[2]' + (isFirstHeader ? '' : ' border-t border-border-subtle') + '" style="color:' + color + '">' +
            '<span class="w-[7px] h-[7px] rounded-full inline-block shrink-0" style="background:' + color + '"></span>' +
            escF(sevLabels[sev] || sev) +
            '<span class="rounded-[10px] px-[7px] py-[1px] text-[9px] ml-[2px]" style="background:' + color + '22;border:1px solid ' + color + '44;color:' + color + '">' + sevCount + '</span>' +
            '</div>';
        }

        var fileName = basename(f.filePath);
        var fileDir = dirname(f.filePath);
        var loc = '';
        if (f.location) loc = f.location;
        if (f.line) loc += (f.location ? ':' : 'line ') + f.line;

        var nodeId = f.node ? escF(f.node.id) : '';
        var domainColor = f.node ? (f.node.domainColor || '#484f58') : '#484f58';
        var lineAttr = f.line ? ' data-target-line="' + f.line + '"' : '';
        var locAttr = (!f.line && f.location) ? ' data-target-location="' + escF(f.location) + '"' : '';
        var keyAttr = ' data-finding-key="' + escF(key) + '"';

        var doneBtnCls = isDone
          ? 'border border-[rgba(35,134,54,0.6)] bg-[rgba(13,53,32,0.7)] text-success'
          : 'border border-transparent bg-transparent text-[#484f58] hover:bg-[rgba(13,53,32,0.5)] hover:text-success hover:border-[rgba(35,134,54,0.5)]';

        html += '<div class="finding-row flex items-start gap-[10px] px-4 py-[9px] border-b border-[rgba(33,38,45,0.7)] cursor-pointer hover:bg-overlay transition-colors' + (isDone ? ' opacity-40' : '') + '" data-node-id="' + nodeId + '"' + lineAttr + locAttr + keyAttr + '>' +
          '<div class="w-2 h-2 rounded-full shrink-0 mt-1" style="background:' + color + '"></div>' +
          '<div class="flex-1 min-w-0">' +
            '<div class="text-[12.5px] text-[#c9d1d9] leading-snug mb-1' + (isDone ? ' line-through decoration-[#484f58]' : '') + '">' + escF(f.description) + '</div>' +
            '<div class="flex items-center gap-[5px] flex-wrap">' +
              '<span class="bg-overlay border border-border-default rounded px-[5px] py-[1px] text-fg-subtle font-mono text-[10px] whitespace-nowrap">' + escF(catLabel(f.category)) + '</span>' +
            '</div>' +
          '</div>' +
          '<div class="text-right shrink-0 max-w-[38%] min-w-0">' +
            '<div class="text-[11.5px] font-medium text-fg-muted whitespace-nowrap overflow-hidden text-ellipsis flex items-center justify-end gap-1">' +
              '<span class="w-[6px] h-[6px] rounded-full shrink-0 inline-block" style="background:' + domainColor + '"></span>' +
              escF(fileName) +
            '</div>' +
            (fileDir ? '<div class="text-[10px] text-[#484f58] whitespace-nowrap overflow-hidden text-ellipsis mt-[1px]">' + escF(fileDir) + '</div>' : '') +
            (loc ? '<div class="text-[10px] text-fg-subtle whitespace-nowrap mt-[1px] font-mono">' + escF(loc) + '</div>' : '') +
          '</div>' +
          '<div class="shrink-0 flex items-start pt-[1px] ml-[6px]">' +
            '<button class="finding-done-btn w-5 h-5 rounded-[5px] cursor-pointer flex items-center justify-center text-[12px] leading-none transition-all p-0 ' + doneBtnCls + '" data-finding-key="' + escF(key) + '" title="' + (isDone ? 'Mark as active' : 'Mark as done') + '">&#10003;</button>' +
          '</div>' +
          '</div>';
      });

      if (!list.length) {
        var msg = showDone ? 'No findings match your filter.' : (doneFindings.size > 0 ? 'All findings marked as done.' : 'No findings match your filter.');
        html = '<div class="py-10 px-5 text-center text-fg-subtle text-[13px]">' + msg + '</div>';
      }

      document.getElementById('findingsRows').innerHTML = html;
      updateDoneBadge();
    }

    function toggleFindingsPanel() {
      var panel = document.getElementById('findingsPanel');
      var isOpen = panel.classList.contains('open');
      if (isOpen) {
        panel.classList.remove('open');
        document.getElementById('findingsTab').classList.remove('active');
      } else {
        clearActiveRouteJobItem();
        closeAllPanels();
        panel.classList.add('open');
        document.getElementById('findingsTab').classList.add('active');
        renderFindingsList();
      }
    }

    // Wire search/filter
    document.getElementById('findingsSearch').addEventListener('input', renderFindingsList);
    document.getElementById('findingsSevFilter').addEventListener('change', renderFindingsList);
    document.getElementById('findingsTypeFilter').addEventListener('change', renderFindingsList);

    // Delegated click on the findings rows container
    document.getElementById('findingsRows').addEventListener('click', function(e) {
      // Done button — toggle without navigating
      var doneBtn = e.target.closest('.finding-done-btn');
      if (doneBtn) {
        e.stopPropagation();
        var key = doneBtn.dataset.findingKey;
        if (doneFindings.has(key)) { doneFindings.delete(key); } else { doneFindings.add(key); }
        saveDone();
        renderFindingsList();
        return;
      }

      // Row click → open file in full-file mode at the finding's line
      var row = e.target.closest('.finding-row');
      if (!row || !row.dataset.nodeId) return;
      var line = row.dataset.targetLine ? parseInt(row.dataset.targetLine, 10) : null;
      var location = row.dataset.targetLocation || null;
      document.getElementById('view').contentWindow.postMessage({
        type: 'openFile',
        nodeId: row.dataset.nodeId,
        fromFiles: false,
        fromFindings: true,
        targetLine: line,
        targetLocation: location,
      }, '*');
    });

    // Resize handle (mirrors files panel resize logic)
    var findingsPanelEl = document.getElementById('findingsPanel');
    var findingsPanelHandle = document.getElementById('findingsPanelResize');
    var findingsPanelWidth = 580;
    var findingsPanelResizing = false;
    findingsPanelEl.style.width = findingsPanelWidth + 'px';

    var findingsResizeOverlay = document.createElement('div');
    findingsResizeOverlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;cursor:col-resize;display:none';
    document.body.appendChild(findingsResizeOverlay);

    findingsPanelHandle.addEventListener('mousedown', function(e) {
      e.preventDefault();
      findingsPanelResizing = true;
      findingsPanelHandle.classList.add('active');
      findingsPanelEl.style.transition = 'none';
      findingsResizeOverlay.style.display = 'block';
    });
    document.addEventListener('mousemove', function(e) {
      if (!findingsPanelResizing) return;
      var rect = document.querySelector('.content-area').getBoundingClientRect();
      var newW = Math.max(360, Math.min(e.clientX - rect.left, rect.width * 0.9));
      findingsPanelWidth = newW;
      findingsPanelEl.style.width = newW + 'px';
    });
    document.addEventListener('mouseup', function() {
      if (!findingsPanelResizing) return;
      findingsPanelResizing = false;
      findingsPanelHandle.classList.remove('active');
      findingsPanelEl.style.transition = '';
      findingsResizeOverlay.style.display = 'none';
    });

    // Expose for tab button onclick and "Show done" button
    window.toggleFindingsPanel = toggleFindingsPanel;
    window.toggleFindingsShowDone = toggleFindingsShowDone;
  })();

  show('{{ $defaultView }}');
</script>
</body>
</html>
