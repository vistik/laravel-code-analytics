<?php

namespace Vistik\LaravelCodeAnalytics\Renderers;

class GroupedRenderer implements LayoutRenderer
{
    public function getLayoutSetupJs(): string
    {
        return <<<'JS'
// ── Grouped layout: domain clusters with sub-folder sections ──────────────────
var domainBoxes = []; // [{domain, color, x, y, w, h, subGroups: [{label, y, h}]}]

function computeGroupedLayout() {
  var vis = nodes.filter(isVisible);
  if (vis.length === 0) { domainBoxes = []; return; }

  // Group by domain, then by sub-folder within each domain
  var groups = {};
  vis.forEach(function(n) {
    var d = n.domain || '(root)';
    if (!groups[d]) groups[d] = { domain: d, color: n.domainColor || '#8b949e', subFolders: {} };
    var folder = n.folder || d;
    // Sub-folder is the part after the domain (e.g. "Http/Controllers" → "Controllers")
    var sub = folder === d ? '' : folder.substring(d.length + 1);
    var subKey = sub || '(main)';
    if (!groups[d].subFolders[subKey]) groups[d].subFolders[subKey] = [];
    groups[d].subFolders[subKey].push(n);
  });

  // Sort domains alphabetically, (root) last
  var domainKeys = Object.keys(groups).sort(function(a, b) {
    if (a === '(root)') return 1;
    if (b === '(root)') return -1;
    return a.localeCompare(b);
  });

  // Layout constants
  var padding = 30;
  var nodeSpacing = 56;
  var domainHeaderH = 34;
  var subHeaderH = 22;
  var boxPadding = 16;
  var subGap = 10;

  // Columns to use inside a sub-folder: max 10 nodes per column, capped at 5
  function subFolderCols(count) {
    return Math.min(5, Math.max(1, Math.ceil(count / 10)));
  }

  // Calculate box sizes
  var boxes = domainKeys.map(function(d) {
    var g = groups[d];
    var subKeys = Object.keys(g.subFolders).sort(function(a, b) {
      if (a === '(main)') return -1;
      if (b === '(main)') return 1;
      return a.localeCompare(b);
    });

    // Sort nodes within each sub-folder
    subKeys.forEach(function(sk) {
      g.subFolders[sk].sort(function(a, b) { return a.id.localeCompare(b.id); });
    });

    var maxR = 0;
    var totalNodes = 0;
    subKeys.forEach(function(sk) {
      g.subFolders[sk].forEach(function(n) { if (n.r > maxR) maxR = n.r; });
      totalNodes += g.subFolders[sk].length;
    });

    var hasSubs = subKeys.length > 1 || (subKeys.length === 1 && subKeys[0] !== '(main)');
    // Column count based on total domain nodes so all sub-folders share the same grid width
    var nc = subFolderCols(totalNodes);
    var colW = Math.max(130, maxR * 2 + 20);
    var boxH = domainHeaderH + boxPadding;
    subKeys.forEach(function(sk) {
      var count = g.subFolders[sk].length;
      if (hasSubs) boxH += subHeaderH + subGap;
      boxH += Math.ceil(count / nc) * nodeSpacing;
    });
    boxH += boxPadding;
    var boxW = Math.max(190, nc * colW + 2 * boxPadding);

    return { domain: d, color: g.color, subKeys: subKeys, subFolders: g.subFolders, hasSubs: hasSubs, w: boxW, h: boxH, nc: nc };
  });

  // Arrange in columns, balanced by height
  var cols = Math.max(1, Math.min(6, Math.ceil(Math.sqrt(boxes.length))));
  var columns = [];
  for (var i = 0; i < cols; i++) columns.push({ boxes: [], height: 0 });

  boxes.forEach(function(box) {
    var shortest = columns[0];
    for (var i = 1; i < columns.length; i++) {
      if (columns[i].height < shortest.height) shortest = columns[i];
    }
    shortest.boxes.push(box);
    shortest.height += box.h + padding;
  });

  // Compute column widths
  var colWidths = columns.map(function(col) {
    var maxW = 0;
    col.boxes.forEach(function(b) { if (b.w > maxW) maxW = b.w; });
    return maxW;
  });
  var totalWidth = 0;
  colWidths.forEach(function(w) { totalWidth += w + padding; });
  totalWidth -= padding;

  var maxHeight = 0;
  columns.forEach(function(col) { if (col.height > maxHeight) maxHeight = col.height; });

  // Position boxes, sub-groups, and nodes
  var curX = W / 2 - totalWidth / 2;
  domainBoxes = [];

  columns.forEach(function(col, ci) {
    var curY = H / 2 - maxHeight / 2;
    col.boxes.forEach(function(box) {
      var subGroups = [];
      var innerY = curY + domainHeaderH + boxPadding;
      var contentW = colWidths[ci] - 2 * boxPadding;

      var nc = box.nc;
      var colWi = contentW / nc;
      box.subKeys.forEach(function(sk) {
        var subY = innerY;
        if (box.hasSubs) {
          innerY += subHeaderH + subGap;
        }
        var subNodes = box.subFolders[sk];
        var numRows = Math.ceil(subNodes.length / nc);
        subNodes.forEach(function(n, idx) {
          if (!n.pinned) {
            n.x = curX + boxPadding + (idx % nc + 0.5) * colWi;
            n.y = innerY + Math.floor(idx / nc) * nodeSpacing + nodeSpacing / 2;
          }
        });
        innerY += numRows * nodeSpacing;
        if (box.hasSubs) {
          subGroups.push({ label: sk === '(main)' ? '' : sk, y: subY, h: innerY - subY, color: box.color });
        }
      });

      domainBoxes.push({
        domain: box.domain, color: box.color,
        x: curX, y: curY, w: colWidths[ci], h: box.h,
        subGroups: subGroups
      });

      curY += box.h + padding;
    });
    curX += colWidths[ci] + padding;
  });

  // Strip domain/folder prefix from labels since the group box shows it
  vis.forEach(function(n) {
    var label = n.id;
    var domain = n.domain || '';
    if (domain && label.indexOf(domain + '/') === 0) {
      label = label.substring(domain.length + 1);
    }
    // Also strip sub-folder prefix (e.g. "Controllers/Foo" inside Http/Controllers group)
    var folder = n.folder || '';
    if (folder && label.indexOf(folder + '/') === 0) {
      label = label.substring(folder.length + 1);
    }
    n.displayLabel = label;
  });
}

var nodeDraggingDisabled = true;
var recomputeLayout = computeGroupedLayout;
computeGroupedLayout();
JS;
    }

    public function getSimulationJs(): string
    {
        return '// Grouped layout: no physics simulation';
    }

    public function getFrameHookJs(): string
    {
        return <<<'JS'
  // Draw domain boxes with sub-folder sections
  if (typeof domainBoxes !== 'undefined') {
    for (var bi = 0; bi < domainBoxes.length; bi++) {
      var box = domainBoxes[bi];
      var bx = box.x, by = box.y, bw = box.w, bh = box.h;

      // Box background
      ctx.beginPath();
      ctx.roundRect(bx, by, bw, bh, 8);
      ctx.fillStyle = 'rgba(22,27,34,0.8)';
      ctx.fill();
      ctx.strokeStyle = box.color + '40';
      ctx.lineWidth = 1;
      ctx.stroke();

      // Domain header bar
      ctx.beginPath();
      ctx.roundRect(bx, by, bw, 32, [8, 8, 0, 0]);
      ctx.fillStyle = box.color + '20';
      ctx.fill();

      // Domain label
      ctx.font = 'bold 12px -apple-system, sans-serif';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = box.color;
      ctx.fillText(box.domain, bx + 12, by + 16);

      // Sub-folder sections — rendered as nested panels
      if (box.subGroups) {
        for (var si = 0; si < box.subGroups.length; si++) {
          var sg = box.subGroups[si];
          if (!sg.label) continue;

          var sInset = 8, sHeaderH = 22;

          // Section container
          ctx.beginPath();
          ctx.roundRect(bx + sInset, sg.y, bw - sInset * 2, sg.h, 4);
          ctx.fillStyle = sg.color + '0c';
          ctx.fill();
          ctx.strokeStyle = sg.color + '38';
          ctx.lineWidth = 0.75;
          ctx.stroke();

          // Section header bar
          ctx.beginPath();
          ctx.roundRect(bx + sInset, sg.y, bw - sInset * 2, sHeaderH, [4, 4, 0, 0]);
          ctx.fillStyle = sg.color + '20';
          ctx.fill();

          // Section label
          ctx.font = '10px -apple-system, sans-serif';
          ctx.textAlign = 'left';
          ctx.textBaseline = 'middle';
          ctx.fillStyle = sg.color + 'bb';
          ctx.fillText('\u25b8 ' + sg.label, bx + sInset + 8, sg.y + sHeaderH / 2);
        }
      }
    }
  }
JS;
    }
}
