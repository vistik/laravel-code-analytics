// ── Syntax highlighting ───────────────────────────────────────────────────────
function escapeHtml(s) { s = (s == null) ? '' : String(s); return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

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
      if (kwType === 'plain' && linkMap && Object.prototype.hasOwnProperty.call(linkMap, word)) {
        var k = j;
        while (k < len && (code[k] === ' ' || code[k] === '\t')) k++;
        if (code[k] === '(') {
          var pi = tokens.length - 1;
          while (pi >= 0 && tokens[pi].type === 'plain') pi--;
          var prevKeyword = pi >= 0 ? tokens[pi] : null;
          var isDefinition = prevKeyword && prevKeyword.type === 'keyword' && prevKeyword.text === 'function';
          if (!isDefinition) {
            // If preceded by a class_link (ClassName::method), use the class node as the
            // target rather than the linkMap entry. This prevents self-referential links
            // when the current class also defines a method with the same name.
            var targetId = (prevKeyword && prevKeyword.type === 'class_link') ? prevKeyword.targetId : linkMap[word];
            tokens.push({type: 'method_link', text: word, targetId: targetId}); i = j; continue;
          }
        }
      }
      // Detect class reference link: UpperCamelCase word in classMap.
      // Covers type hints, `new`/`extends`/`implements`/`instanceof`, `::`, and
      // the class/interface declaration itself (so `interface LoggerInterface` is
      // clickable and can show the implementations picker).
      if (kwType === 'plain' && classMap && Object.prototype.hasOwnProperty.call(classMap, word)) {
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
