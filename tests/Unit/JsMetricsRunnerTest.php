<?php

use Vistik\LaravelCodeAnalytics\Support\JsMetrics;
use Vistik\LaravelCodeAnalytics\Support\JsMetricsRunner;

it('returns empty array when no content given', function () {
    $result = (new JsMetricsRunner)->run([]);
    expect($result)->toBe([]);
});

it('returns empty array when all content is null', function () {
    $result = (new JsMetricsRunner)->run(['App.jsx' => null, 'index.js' => null]);
    expect($result)->toBe([]);
});

it('returns metrics for a simple JS file', function () {
    $result = (new JsMetricsRunner)->run([
        'utils.js' => <<<'JS'
            function add(a, b) { return a + b; }
            function subtract(a, b) { return a - b; }
            module.exports = { add, subtract };
            JS,
    ]);

    expect($result)->toHaveKey('utils.js');
    $m = $result['utils.js'];
    expect($m)->toBeInstanceOf(JsMetrics::class);
    expect($m->cyclomaticComplexity)->toBeGreaterThanOrEqual(1);
    expect($m->logicalLinesOfCode)->toBeGreaterThan(0);
    expect($m->functionCount)->toBe(2);
});

it('parses jsx syntax without errors', function () {
    $result = (new JsMetricsRunner)->run([
        'Button.jsx' => <<<'JSX'
            import React from 'react';
            export function Button({ label, onClick, disabled }) {
                if (disabled) return null;
                return <button onClick={onClick}>{label}</button>;
            }
            JSX,
    ]);

    expect($result)->toHaveKey('Button.jsx');
    $m = $result['Button.jsx'];
    expect($m->cyclomaticComplexity)->toBeGreaterThanOrEqual(2); // 1 base + 1 if
    expect($m->maintainabilityIndex)->toBeGreaterThan(0);
});

it('parses tsx with typescript types', function () {
    $result = (new JsMetricsRunner)->run([
        'Card.tsx' => <<<'TSX'
            import React from 'react';
            interface Props { title: string; count: number; }
            export const Card: React.FC<Props> = ({ title, count }) => {
                const label = count > 0 ? `${count} items` : 'empty';
                return <div><h2>{title}</h2><p>{label}</p></div>;
            };
            TSX,
    ]);

    expect($result)->toHaveKey('Card.tsx');
    $m = $result['Card.tsx'];
    expect($m->cyclomaticComplexity)->toBeGreaterThanOrEqual(2); // 1 base + 1 ternary
});

it('handles es module imports and exports', function () {
    $result = (new JsMetricsRunner)->run([
        'api.js' => <<<'JS'
            import axios from 'axios';
            export async function fetchUser(id) {
                if (!id) throw new Error('no id');
                const res = await axios.get(`/users/${id}`);
                return res.data;
            }
            export async function updateUser(id, data) {
                if (!id || !data) return null;
                const res = await axios.put(`/users/${id}`, data);
                return res.data;
            }
            JS,
    ]);

    expect($result)->toHaveKey('api.js');
    $m = $result['api.js'];
    expect($m->cyclomaticComplexity)->toBeGreaterThanOrEqual(3);
    expect($m->functionCount)->toBe(2);
});

it('scores higher complexity for branchy code', function () {
    $simple = (new JsMetricsRunner)->run([
        'simple.js' => 'function hello() { return "hi"; }',
    ]);

    $complex = (new JsMetricsRunner)->run([
        'complex.js' => <<<'JS'
            function classify(x, y) {
                if (x > 0 && y > 0) {
                    return 'both positive';
                } else if (x < 0 || y < 0) {
                    return 'some negative';
                } else {
                    return x === 0 ? 'x is zero' : 'y is zero';
                }
            }
            JS,
    ]);

    $simpleCC = $simple['simple.js']->cyclomaticComplexity ?? 0;
    $complexCC = $complex['complex.js']->cyclomaticComplexity ?? 0;

    expect($complexCC)->toBeGreaterThan($simpleCC);
});

it('returns metrics keyed by original relative path', function () {
    $result = (new JsMetricsRunner)->run([
        'resources/js/Components/Modal.jsx' => <<<'JSX'
            export function Modal({ open, children }) {
                if (!open) return null;
                return <div className="modal">{children}</div>;
            }
            JSX,
    ]);

    expect($result)->toHaveKey('resources/js/Components/Modal.jsx');
    expect($result)->not->toHaveKey('Modal.jsx');
});
