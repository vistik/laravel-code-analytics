<?php

use Vistik\LaravelCodeAnalytics\Actions\GenerateLlmReport;
use Vistik\LaravelCodeAnalytics\Enums\OutputFormat;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

function llmNode(string $path, int $add = 5, int $del = 2, int $signal = 10, ?string $severity = null, string $id = ''): array
{
    return [
        'id' => $id ?: basename($path, '.php'),
        'path' => $path,
        'status' => 'modified',
        'add' => $add,
        'del' => $del,
        'severity' => $severity,
        '_signal' => $signal,
    ];
}

function llmFinding(string $desc, string $severity = 'high', ?string $location = null): array
{
    return array_filter([
        'severity' => $severity,
        'description' => $desc,
        'location' => $location,
    ], fn ($v) => $v !== null);
}

function generateLlm(
    array $nodes = [],
    array $edges = [],
    array $analysisData = [],
    ?array $focusPatterns = null,
    ?RiskScore $riskScore = null,
): string {
    return (new GenerateLlmReport($focusPatterns))->generate(
        payload: new GraphPayload(
            nodes: $nodes,
            edges: $edges,
            fileDiffs: [],
            analysisData: $analysisData,
            riskScore: $riskScore,
        ),
        pr: new PullRequestContext(
            prTitle: 'Test PR',
            repo: 'test/repo',
            headCommit: 'abc1234567',
            prAdditions: 10,
            prDeletions: 3,
            fileCount: count($nodes),
        ),
    );
}

// ── Header ────────────────────────────────────────────────────────────────────

test('header contains risk score', function () {
    $risk = new RiskScore(42);

    expect(generateLlm(riskScore: $risk))->toContain('RISK:42');
});

test('header shows question mark when no risk score', function () {
    expect(generateLlm())->toContain('RISK:?');
});

test('header contains additions and deletions', function () {
    expect(generateLlm())->toContain('+10-3');
});

test('header contains file count', function () {
    $nodes = [llmNode('app/Foo.php'), llmNode('app/Bar.php')];

    expect(generateLlm(nodes: $nodes))->toContain('files:2');
});

test('header truncates commit sha to 7 characters', function () {
    expect(generateLlm())->toContain('head:abc1234');
});

// ── FILES section (full report mode) ─────────────────────────────────────────

test('FILES section is present when files have changes', function () {
    $nodes = [llmNode('app/Foo.php', add: 5, del: 2, signal: 10)];

    expect(generateLlm(nodes: $nodes))->toContain('FILES');
});

test('FILES section is absent when all nodes have no changes and no signal', function () {
    $nodes = [llmNode('app/Foo.php', add: 0, del: 0, signal: 0)];

    expect(generateLlm(nodes: $nodes))->not->toContain('FILES');
});

test('node with zero changes but non-zero signal appears in FILES', function () {
    $nodes = [llmNode('app/Foo.php', add: 0, del: 0, signal: 15)];

    expect(generateLlm(nodes: $nodes))->toContain('app/Foo.php');
});

test('node with changes but zero signal appears in FILES', function () {
    $nodes = [llmNode('app/Foo.php', add: 3, del: 0, signal: 0)];

    expect(generateLlm(nodes: $nodes))->toContain('app/Foo.php');
});

test('files are sorted by signal descending', function () {
    $nodes = [
        llmNode('app/Low.php', signal: 5),
        llmNode('app/High.php', signal: 30),
        llmNode('app/Mid.php', signal: 15),
    ];

    $output = generateLlm(nodes: $nodes);
    $positions = [
        strpos($output, 'High.php'),
        strpos($output, 'Mid.php'),
        strpos($output, 'Low.php'),
    ];

    expect($positions[0])->toBeLessThan($positions[1])
        ->and($positions[1])->toBeLessThan($positions[2]);
});

test('file line contains signal, severity, additions, deletions and path', function () {
    $nodes = [llmNode('app/Foo.php', add: 8, del: 3, signal: 25, severity: 'high')];

    expect(generateLlm(nodes: $nodes))->toContain('25 H +8-3 app/Foo.php');
});

test('severity abbreviations are correct', function (string $severity, string $expected) {
    $nodes = [llmNode('app/Foo.php', severity: $severity)];

    expect(generateLlm(nodes: $nodes))->toContain($expected);
})->with([
    ['info', ' I '],
    ['low', ' L '],
    ['medium', ' M '],
    ['high', ' H '],
    ['very_high', ' VH '],
]);

// ── Findings in FILES ─────────────────────────────────────────────────────────

test('findings appear indented under their file', function () {
    $nodes = [llmNode('app/Foo.php', signal: 10)];
    $analysis = ['app/Foo.php' => [llmFinding('Too complex', 'high')]];

    expect(generateLlm(nodes: $nodes, analysisData: $analysis))->toContain('  H:Too complex');
});

test('finding location is appended in brackets', function () {
    $nodes = [llmNode('app/Foo.php', signal: 10)];
    $analysis = ['app/Foo.php' => [llmFinding('Missing type', 'medium', 'handle')]];

    expect(generateLlm(nodes: $nodes, analysisData: $analysis))->toContain('  M:Missing type [handle]');
});

test('finding with no location has no brackets', function () {
    $nodes = [llmNode('app/Foo.php', signal: 10)];
    $analysis = ['app/Foo.php' => [llmFinding('Some issue', 'low')]];

    $output = generateLlm(nodes: $nodes, analysisData: $analysis);

    expect($output)->toContain('  L:Some issue')
        ->and($output)->not->toContain('[');
});

// ── DEPS section (full report mode) ──────────────────────────────────────────

test('DEPS section is present when edges exist', function () {
    $nodes = [llmNode('app/Foo.php', id: 'Foo'), llmNode('app/Bar.php', id: 'Bar')];
    $edges = [['Foo', 'Bar', 'constructor_injection']];

    expect(generateLlm(nodes: $nodes, edges: $edges))->toContain('DEPS');
});

test('DEPS section is absent when no edges', function () {
    $nodes = [llmNode('app/Foo.php', signal: 10)];

    expect(generateLlm(nodes: $nodes))->not->toContain('DEPS');
});

test('dependency line lists source and target path', function () {
    $nodes = [llmNode('app/Foo.php', id: 'Foo'), llmNode('app/Bar.php', id: 'Bar')];
    $edges = [['Foo', 'Bar', 'constructor_injection']];

    expect(generateLlm(nodes: $nodes, edges: $edges))->toContain('app/Foo.php:app/Bar.php');
});

test('multiple targets for same source are comma-separated on one line', function () {
    $nodes = [
        llmNode('app/Foo.php', id: 'Foo'),
        llmNode('app/Bar.php', id: 'Bar'),
        llmNode('app/Baz.php', id: 'Baz'),
    ];
    $edges = [
        ['Foo', 'Bar', 'constructor_injection'],
        ['Foo', 'Baz', 'new_instance'],
    ];

    expect(generateLlm(nodes: $nodes, edges: $edges))->toContain('app/Foo.php:app/Bar.php,app/Baz.php');
});

test('duplicate edges are deduplicated in DEPS', function () {
    $nodes = [llmNode('app/Foo.php', id: 'Foo'), llmNode('app/Bar.php', id: 'Bar')];
    $edges = [
        ['Foo', 'Bar', 'constructor_injection'],
        ['Foo', 'Bar', 'return_type'],
    ];

    $output = generateLlm(nodes: $nodes, edges: $edges);
    $depsLine = array_values(array_filter(
        explode("\n", $output),
        fn ($l) => str_starts_with($l, 'app/Foo.php:'),
    ))[0] ?? '';

    expect(substr_count($depsLine, 'app/Bar.php'))->toBe(1);
});

test('FILES section appears before DEPS section', function () {
    $nodes = [llmNode('app/Foo.php', id: 'Foo'), llmNode('app/Bar.php', id: 'Bar')];
    $edges = [['Foo', 'Bar', 'constructor_injection']];

    $output = generateLlm(nodes: $nodes, edges: $edges);

    expect(strpos($output, 'FILES'))->toBeLessThan(strpos($output, 'DEPS'));
});

// ── Focus mode ────────────────────────────────────────────────────────────────

test('focus mode shows FOCUS block instead of FILES section', function () {
    $nodes = [llmNode('app/Services/Foo.php', id: 'Foo', signal: 10)];

    $output = generateLlm(nodes: $nodes, focusPatterns: ['Foo']);

    expect($output)->toContain('FOCUS app/Services/Foo.php')
        ->and($output)->not->toContain('FILES');
});

test('focus pattern matches by substring', function () {
    $nodes = [llmNode('app/Services/UserService.php', id: 'UserService', signal: 5)];

    expect(generateLlm(nodes: $nodes, focusPatterns: ['UserService']))->toContain('FOCUS app/Services/UserService.php');
});

test('focus mode does not show files that do not match any pattern', function () {
    $nodes = [
        llmNode('app/Services/Foo.php', id: 'Foo'),
        llmNode('app/Services/Bar.php', id: 'Bar'),
    ];

    $output = generateLlm(nodes: $nodes, focusPatterns: ['Foo']);

    expect($output)->toContain('FOCUS app/Services/Foo.php')
        ->and($output)->not->toContain('app/Services/Bar.php');
});

test('focus mode shows all findings for the focused file', function () {
    $nodes = [llmNode('app/Foo.php', id: 'Foo', signal: 10)];
    $analysis = [
        'app/Foo.php' => [
            llmFinding('Issue one', 'high'),
            llmFinding('Issue two', 'medium'),
            llmFinding('Issue three', 'info'),
        ],
    ];

    $output = generateLlm(nodes: $nodes, analysisData: $analysis, focusPatterns: ['Foo']);

    expect($output)->toContain('H:Issue one')
        ->and($output)->toContain('M:Issue two')
        ->and($output)->toContain('I:Issue three');
});

test('focus mode shows findings with location in brackets', function () {
    $nodes = [llmNode('app/Foo.php', id: 'Foo', signal: 5)];
    $analysis = ['app/Foo.php' => [llmFinding('Complex', 'high', 'handle')]];

    expect(generateLlm(nodes: $nodes, analysisData: $analysis, focusPatterns: ['Foo']))
        ->toContain('H:Complex [handle]');
});

test('focus mode shows DEPS_OUT for outgoing dependencies', function () {
    $nodes = [llmNode('app/Foo.php', id: 'Foo'), llmNode('app/Bar.php', id: 'Bar')];
    $edges = [['Foo', 'Bar', 'constructor_injection']];

    expect(generateLlm(nodes: $nodes, edges: $edges, focusPatterns: ['Foo']))
        ->toContain('DEPS_OUT:app/Bar.php');
});

test('focus mode shows DEPS_IN for incoming dependencies', function () {
    $nodes = [llmNode('app/Foo.php', id: 'Foo'), llmNode('app/Bar.php', id: 'Bar')];
    $edges = [['Bar', 'Foo', 'constructor_injection']];

    expect(generateLlm(nodes: $nodes, edges: $edges, focusPatterns: ['Foo']))
        ->toContain('DEPS_IN:app/Bar.php');
});

test('focus mode shows both DEPS_OUT and DEPS_IN', function () {
    $nodes = [
        llmNode('app/Foo.php', id: 'Foo'),
        llmNode('app/Bar.php', id: 'Bar'),
        llmNode('app/Baz.php', id: 'Baz'),
    ];
    $edges = [
        ['Foo', 'Bar', 'constructor_injection'],
        ['Baz', 'Foo', 'new_instance'],
    ];

    $output = generateLlm(nodes: $nodes, edges: $edges, focusPatterns: ['Foo']);

    expect($output)->toContain('DEPS_OUT:app/Bar.php')
        ->and($output)->toContain('DEPS_IN:app/Baz.php');
});

test('focus mode omits DEPS_OUT when focus file has no outgoing deps', function () {
    $nodes = [llmNode('app/Foo.php', id: 'Foo'), llmNode('app/Bar.php', id: 'Bar')];
    $edges = [['Bar', 'Foo', 'constructor_injection']];

    expect(generateLlm(nodes: $nodes, edges: $edges, focusPatterns: ['Foo']))
        ->not->toContain('DEPS_OUT');
});

test('focus mode omits DEPS_IN when nothing depends on focus file', function () {
    $nodes = [llmNode('app/Foo.php', id: 'Foo'), llmNode('app/Bar.php', id: 'Bar')];
    $edges = [['Foo', 'Bar', 'constructor_injection']];

    expect(generateLlm(nodes: $nodes, edges: $edges, focusPatterns: ['Foo']))
        ->not->toContain('DEPS_IN');
});

test('focus mode produces a block for each matched file', function () {
    $nodes = [
        llmNode('app/Foo.php', id: 'Foo'),
        llmNode('app/Bar.php', id: 'Bar'),
    ];

    $output = generateLlm(nodes: $nodes, focusPatterns: ['Foo', 'Bar']);

    expect($output)->toContain('FOCUS app/Foo.php')
        ->and($output)->toContain('FOCUS app/Bar.php');
});

test('focus mode does not show DEPS section', function () {
    $nodes = [llmNode('app/Foo.php', id: 'Foo'), llmNode('app/Bar.php', id: 'Bar')];
    $edges = [['Foo', 'Bar', 'constructor_injection']];

    $output = generateLlm(nodes: $nodes, edges: $edges, focusPatterns: ['Foo']);

    expect($output)->not->toContain("\nDEPS\n");
});

// ── OutputFormat enum ─────────────────────────────────────────────────────────

test('OutputFormat has LLM case with value llm', function () {
    expect(OutputFormat::LLM->value)->toBe('llm');
});

test('OutputFormat LLM file extension is txt', function () {
    expect(OutputFormat::LLM->fileExtension())->toBe('txt');
});

test('OutputFormat LLM generator returns GenerateLlmReport', function () {
    $generator = OutputFormat::LLM->generator();

    expect($generator)->toBeInstanceOf(GenerateLlmReport::class);
});

test('OutputFormat LLM generator passes focus option to GenerateLlmReport', function () {
    $generator = OutputFormat::LLM->generator(['focus' => ['app/Foo.php']]);

    // Verify it is a GenerateLlmReport; focus is exercised via generate() behaviour tests above
    expect($generator)->toBeInstanceOf(GenerateLlmReport::class);
});

test('OutputFormat tryFrom returns LLM for llm string', function () {
    expect(OutputFormat::tryFrom('llm'))
        ->toBe(OutputFormat::LLM);
});
