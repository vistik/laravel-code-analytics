<?php

use Vistik\LaravelCodeAnalytics\Actions\GenerateHtmlReport;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\Enums\GraphLayout;

function makeHtml(): string
{
    return (new GenerateHtmlReport)->execute(
        nodes: [],
        edges: [],
        fileDiffs: [],
        analysisData: [],
        prNumber: '1',
        prTitle: 'Test PR',
        prUrl: 'https://github.com/test/repo/pull/1',
        prAdditions: 0,
        prDeletions: 0,
        repo: 'test/repo',
        headCommit: 'abc1234',
        fileCount: 0,
        connectedCount: 0,
        extTogglesHtml: '',
        folderTogglesHtml: '',
    );
}

test('rendered html does not contain the severity data placeholder', function () {
    expect(makeHtml())->not->toContain('__SEVERITY_DATA_JS__');
});

test('rendered html defines sevColors const', function () {
    expect(makeHtml())->toContain('const sevColors=');
});

test('rendered html defines sevLabels const', function () {
    expect(makeHtml())->toContain('const sevLabels=');
});

test('rendered html defines sevOrder const', function () {
    expect(makeHtml())->toContain('const sevOrder=');
});

test('sevColors contains correct hex color for each severity case', function (Severity $severity) {
    expect(makeHtml())->toContain("'{$severity->value}':'{$severity->color()}'");
})->with(Severity::cases());

test('sevLabels contains correct label for each severity case', function (Severity $severity) {
    expect(makeHtml())->toContain("'{$severity->value}':'{$severity->label()}'");
})->with(Severity::cases());

test('sevOrder assigns very_high the lowest index', function () {
    expect(makeHtml())->toContain("'very_high':0");
});

test('sevOrder assigns info the highest index', function () {
    $caseCount = count(Severity::cases()) - 1;
    expect(makeHtml())->toContain("'info':{$caseCount}");
});

test('blade view contains severity data variable injection', function () {
    $viewPath = realpath(__DIR__.'/../../../resources/views/analysis/inner.blade.php');
    expect(file_get_contents($viewPath))->toContain('{!! $severityDataJs !!}');
});

function makeMetricsBadge(array $metricsData): string
{
    return (new GenerateHtmlReport)->buildMetricsBadge($metricsData);
}

test('metrics badge shows up arrow when overall quality improved', function () {
    $badge = makeMetricsBadge([
        'app/Foo.php' => [
            'cc' => 5,
            'mi' => 90.0,
            'bugs' => 0.01,
            'before' => ['cc' => 20, 'mi' => 50.0, 'bugs' => 0.5],
        ],
    ]);

    expect($badge)->toContain('&#8593;');
});

test('metrics badge shows down arrow when overall quality degraded', function () {
    $badge = makeMetricsBadge([
        'app/Foo.php' => [
            'cc' => 25,
            'mi' => 40.0,
            'bugs' => 0.8,
            'before' => ['cc' => 5, 'mi' => 90.0, 'bugs' => 0.01],
        ],
    ]);

    expect($badge)->toContain('&#8595;');
});

test('metrics badge shows right arrow when metrics are unchanged', function () {
    $badge = makeMetricsBadge([
        'app/Foo.php' => [
            'cc' => 10,
            'mi' => 75.0,
            'before' => ['cc' => 10, 'mi' => 75.0],
        ],
    ]);

    expect($badge)->toContain('&#8594;');
});

test('metrics badge shows no trend indicator when there is no before data', function () {
    $badge = makeMetricsBadge([
        'app/Foo.php' => ['cc' => 25, 'mi' => 40.0, 'bugs' => 0.8],
    ]);

    expect($badge)
        ->not->toContain('&#8593;')
        ->not->toContain('&#8595;')
        ->not->toContain('&#8594;');
});

// ── fileContents / Full file diff view ───────────────────────────────────────

test('rendered html contains empty fileContents object when none provided', function () {
    expect(makeHtml())->toContain('const fileContents = {}');
});

test('rendered html embeds provided file contents', function () {
    $html = (new GenerateHtmlReport)->execute(
        nodes: [], edges: [], fileDiffs: [], analysisData: [],
        prNumber: '1', prTitle: 'Test', prUrl: '',
        prAdditions: 0, prDeletions: 0, repo: 'test', headCommit: 'abc1234',
        fileCount: 0, connectedCount: 0, extTogglesHtml: '', folderTogglesHtml: '',
        fileContents: ['app/Foo.php' => "<?php\necho 'hello';\n"],
    );

    expect($html)
        ->toContain('const fileContents = ')
        ->toContain('"app/Foo.php"');
});

test('blade view injects fileContentsJson variable', function () {
    $viewPath = realpath(__DIR__.'/../../../resources/views/analysis/inner.blade.php');
    expect(file_get_contents($viewPath))->toContain('{!! $fileContentsJson !!}');
});

test('blade view contains renderFullFile function', function () {
    $viewPath = realpath(__DIR__.'/../../../resources/views/analysis/inner.blade.php');
    expect(file_get_contents($viewPath))->toContain('function renderFullFile(');
});

test('blade view contains Full file button', function () {
    $viewPath = realpath(__DIR__.'/../../../resources/views/analysis/inner.blade.php');
    expect(file_get_contents($viewPath))->toContain('Full file');
});

// ── defaultView ───────────────────────────────────────────────────────────────

test('generate uses force as default view when no defaultView is specified', function () {
    $html = (new GenerateHtmlReport)->generate(
        nodes: [], edges: [], fileDiffs: [], analysisData: [],
        title: 'Test', repo: 'test/repo', headCommit: 'abc1234',
        prAdditions: 0, prDeletions: 0, fileCount: 0,
    );

    expect($html)->toContain("show('force')");
});

test('generate uses specified defaultView', function () {
    $html = (new GenerateHtmlReport)->generate(
        nodes: [], edges: [], fileDiffs: [], analysisData: [],
        title: 'Test', repo: 'test/repo', headCommit: 'abc1234',
        prAdditions: 0, prDeletions: 0, fileCount: 0,
        defaultView: GraphLayout::Tree,
    );

    expect($html)->toContain("show('tree')");
});
