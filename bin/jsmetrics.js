#!/usr/bin/env node
'use strict';

/**
 * JS/TS/JSX/TSX complexity metrics using @babel/parser.
 *
 * Outputs JSON parsed by JsMetricsRunner::fromRaw().
 *
 * Usage: node bin/jsmetrics.js <dir>
 */

const fs   = require('fs');
const path = require('path');

let parse;
try {
    parse = require('@babel/parser').parse;
} catch {
    process.stderr.write('@babel/parser not found. Run: npm install\n');
    process.exit(1);
}

// AST node types that add a branch (each +1 to cyclomatic complexity)
const BRANCH_TYPES = new Set([
    'IfStatement',
    'ConditionalExpression',  // ternary
    'LogicalExpression',      // && || ??
    'ForStatement',
    'ForInStatement',
    'ForOfStatement',
    'WhileStatement',
    'DoWhileStatement',
    'SwitchCase',
    'CatchClause',
    'OptionalMemberExpression',
    'OptionalCallExpression',
]);

// AST node types that begin a new function scope
const FUNCTION_TYPES = new Set([
    'FunctionDeclaration',
    'FunctionExpression',
    'ArrowFunctionExpression',
    'ObjectMethod',
    'ClassMethod',
    'ClassPrivateMethod',
]);

// ── File discovery ─────────────────────────────────────────────────────────

function walkDir(dir) {
    const results = [];
    let entries;
    try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { return results; }

    for (const entry of entries) {
        if (entry.name === 'node_modules' || entry.name.startsWith('.')) continue;
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            results.push(...walkDir(full));
        } else if (entry.isFile() && /\.(js|jsx|ts|tsx|mjs|cjs|vue)$/.test(entry.name)) {
            results.push(full);
        }
    }
    return results;
}

// ── AST helpers ────────────────────────────────────────────────────────────

function getFunctionName(node) {
    if (node.id?.name)   return node.id.name;
    if (node.key?.name)  return node.key.name;
    if (node.key?.value) return String(node.key.value);
    return '<anonymous>';
}

function childNodes(node) {
    const kids = [];
    for (const key of Object.keys(node)) {
        if (key === 'type' || key === 'loc' || key === 'start' || key === 'end'
                || key === 'extra' || key === 'innerComments' || key === 'leadingComments'
                || key === 'trailingComments') continue;
        const val = node[key];
        if (Array.isArray(val)) {
            for (const v of val) { if (v && typeof v === 'object' && v.type) kids.push(v); }
        } else if (val && typeof val === 'object' && val.type) {
            kids.push(val);
        }
    }
    return kids;
}

// ── Metric collection ──────────────────────────────────────────────────────

function collectMetrics(ast, source) {
    const functions = [];
    // Each entry: { name, line, cc, params }
    const scopeStack = [];

    function currentScope() { return scopeStack[scopeStack.length - 1]; }

    function walk(node) {
        if (!node || typeof node !== 'object') return;

        const isFn = FUNCTION_TYPES.has(node.type);
        if (isFn) {
            scopeStack.push({
                name:   getFunctionName(node),
                line:   node.loc?.start?.line ?? 0,
                cc:     1,
                params: (node.params?.length ?? 0),
            });
        }

        if (BRANCH_TYPES.has(node.type)) {
            // Credit the innermost function scope, or file-level aggregate
            if (scopeStack.length > 0) currentScope().cc++;
        }

        for (const child of childNodes(node)) walk(child);

        if (isFn) functions.push(scopeStack.pop());
    }

    walk(ast.program ?? ast);

    // File-level aggregate CC: sum of all function CCs (or 1 if no functions)
    const aggregateCC = functions.length
        ? functions.reduce((s, f) => s + f.cc, 0)
        : 1;

    // LLOC: non-blank lines, excluding single-line comments
    const lines = source.split('\n');
    const lloc  = lines.filter(l => {
        const t = l.trim();
        return t.length > 0 && !t.startsWith('//') && !t.startsWith('*') && t !== '*/';
    }).length;

    // Maintainability Index (Microsoft variant, 0-100)
    // MI = max(0, (171 − 5.2·ln(HV) − 0.23·CC − 16.2·ln(lloc)) × 100 / 171)
    // We skip Halstead volume and use CC as a proxy: HV ≈ lloc * 4 (rough estimate)
    const hv  = Math.max(1, lloc * 4);
    const mi  = Math.max(0, Math.min(100,
        (171 - 5.2 * Math.log(hv) - 0.23 * aggregateCC - 16.2 * Math.log(Math.max(1, lloc))) * 100 / 171
    ));

    // Bugs estimate (Halstead): B = V / 3000, V ≈ hv
    const bugs = hv / 3000;

    // Average CC per function (reported as top-level `cyclomatic`)
    const avgCC = functions.length ? aggregateCC / functions.length : aggregateCC;

    return { functions, aggregateCC, lloc, mi, bugs, avgCC };
}

// ── Parse + analyse one file ───────────────────────────────────────────────

const BABEL_PLUGINS = [
    'jsx',
    'typescript',
    ['decorators', { decoratorsBeforeExport: true }],
    'classProperties',
    'classPrivateProperties',
    'classPrivateMethods',
    'exportDefaultFrom',
    'exportNamespaceFrom',
    'dynamicImport',
    'nullishCoalescingOperator',
    'optionalChaining',
    'logicalAssignment',
];

function analyzeFile(filePath, sourceDir) {
    let source;
    try { source = fs.readFileSync(filePath, 'utf8'); } catch { return null; }

    let ast;
    try {
        ast = parse(source, {
            sourceType:                  'module',
            allowImportExportEverywhere: true,
            allowReturnOutsideFunction:  true,
            allowSuperOutsideMethod:     true,
            errorRecovery:               true,
            plugins:                     BABEL_PLUGINS,
        });
    } catch {
        return null;
    }

    const { functions, aggregateCC, lloc, mi, bugs, avgCC } = collectMetrics(ast, source);

    return {
        path: path.relative(sourceDir, filePath),
        aggregate: {
            sloc:      { logical: lloc, physical: source.split('\n').length },
            cyclomatic: aggregateCC,
            halstead:  { bugs },
        },
        functions: functions.map(f => ({
            name:      f.name,
            line:      f.line,
            cyclomatic: f.cc,
            params:    f.params,
            sloc:      { logical: 0, physical: 0 },
            halstead:  { bugs: f.cc * bugs / Math.max(1, aggregateCC) },
        })),
        maintainability: Math.round(mi * 10) / 10,
        loc:             lloc,
        cyclomatic:      Math.round(avgCC * 10) / 10,
        effort:          0,
        params:          0,
    };
}

// ── Main ───────────────────────────────────────────────────────────────────

const dir = process.argv[2];
if (!dir || !fs.existsSync(dir)) {
    process.stderr.write('Usage: jsmetrics.js <dir>\n');
    process.exit(1);
}

const files   = walkDir(dir);
const reports = files.map(f => analyzeFile(f, dir)).filter(Boolean);

process.stdout.write(JSON.stringify({
    reports,
    firstOrderDensity: 0,
    changeCost:        0,
    coreSize:          0,
    loc:               0,
    cyclomatic:        0,
    effort:            0,
    params:            0,
    maintainability:   0,
}));
