// ── Diff view ─────────────────────────────────────────────────────────────────
var diffViewMode = localStorage.getItem('diffViewMode') || 'unified';
var diffAnnotationsData = [];

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
