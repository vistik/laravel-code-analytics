<?php

use Vistik\LaravelCodeAnalytics\DiffParser\DiffParser;

// ── Helpers ───────────────────────────────────────────────────────────────────

function parsed(string $diff): array
{
    return (new DiffParser)->parse($diff);
}

// ── Hunk headers ──────────────────────────────────────────────────────────────

test('parses hunk header with old and new start lines', function () {
    $result = parsed("@@ -10,5 +10,6 @@\n context line");

    $hunk = $result[0];
    expect($hunk['type'])->toBe('hunk')
        ->and($hunk['oldStart'])->toBe(10)
        ->and($hunk['newStart'])->toBe(10)
        ->and($hunk['raw'])->toBe('@@ -10,5 +10,6 @@');
});

test('parses hunk header without line count (single line form)', function () {
    $result = parsed("@@ -1 +1 @@\n context");

    expect($result[0]['oldStart'])->toBe(1)
        ->and($result[0]['newStart'])->toBe(1);
});

test('defaults oldStart and newStart to 1 when hunk header is malformed', function () {
    $result = parsed('@@ bad header @@');

    expect($result[0]['oldStart'])->toBe(1)
        ->and($result[0]['newStart'])->toBe(1);
});

// ── Context lines ─────────────────────────────────────────────────────────────

test('parses context line by stripping leading space', function () {
    $result = parsed(' some context');

    expect($result[0])->toBe(['type' => 'ctx', 'text' => 'some context']);
});

test('parses empty context line as empty text', function () {
    $result = parsed('');

    expect($result[0])->toBe(['type' => 'ctx', 'text' => '']);
});

// ── Change blocks ─────────────────────────────────────────────────────────────

test('parses consecutive deletions into a single change token', function () {
    $result = parsed("-old line 1\n-old line 2");

    $change = $result[0];
    expect($change['type'])->toBe('change')
        ->and($change['dels'])->toBe(['old line 1', 'old line 2'])
        ->and($change['adds'])->toBe([]);
});

test('parses consecutive additions into a single change token', function () {
    $result = parsed("+new line 1\n+new line 2");

    $change = $result[0];
    expect($change['type'])->toBe('change')
        ->and($change['dels'])->toBe([])
        ->and($change['adds'])->toBe(['new line 1', 'new line 2']);
});

test('groups mixed deletions and additions into one change token', function () {
    $result = parsed("-old\n+new");

    expect($result)->toHaveCount(1);
    $change = $result[0];
    expect($change['type'])->toBe('change')
        ->and($change['dels'])->toBe(['old'])
        ->and($change['adds'])->toBe(['new']);
});

test('starts a new change token after a context line', function () {
    $result = parsed("-del1\n ctx\n+add1");

    expect($result)->toHaveCount(3);
    expect($result[0]['type'])->toBe('change')
        ->and($result[1]['type'])->toBe('ctx')
        ->and($result[2]['type'])->toBe('change');
});

// ── Backslash lines ───────────────────────────────────────────────────────────

test('skips backslash no-newline marker inside a change block', function () {
    $result = parsed("+added line\n\\ No newline at end of file");

    expect($result)->toHaveCount(1)
        ->and($result[0]['type'])->toBe('change')
        ->and($result[0]['adds'])->toBe(['added line']);
});

test('skips standalone backslash line', function () {
    $result = parsed("\\ No newline at end of file\n ctx");

    expect($result)->toHaveCount(1)
        ->and($result[0]['type'])->toBe('ctx');
});

// ── Full diff ─────────────────────────────────────────────────────────────────

test('parses a realistic unified diff end-to-end', function () {
    $diff = implode("\n", [
        '@@ -1,4 +1,4 @@',
        ' <?php',
        '-function oldName(): void {}',
        '+function newName(): void {}',
        ' ',
        ' // end',
    ]);

    $result = parsed($diff);

    expect($result)->toHaveCount(5);
    expect($result[0])->toMatchArray(['type' => 'hunk', 'oldStart' => 1, 'newStart' => 1]);
    expect($result[1])->toMatchArray(['type' => 'ctx', 'text' => '<?php']);
    expect($result[2])->toMatchArray(['type' => 'change', 'dels' => ['function oldName(): void {}'], 'adds' => ['function newName(): void {}']]);
    expect($result[3])->toMatchArray(['type' => 'ctx', 'text' => '']);
    expect($result[4])->toMatchArray(['type' => 'ctx', 'text' => '// end']);
});

// ── parseAll ──────────────────────────────────────────────────────────────────

test('parseAll parses each diff by path', function () {
    $diffs = [
        'app/Foo.php' => "+new line",
        'app/Bar.php' => "-old line",
    ];

    $result = (new DiffParser)->parseAll($diffs);

    expect($result)->toHaveKeys(['app/Foo.php', 'app/Bar.php'])
        ->and($result['app/Foo.php'][0]['adds'])->toBe(['new line'])
        ->and($result['app/Bar.php'][0]['dels'])->toBe(['old line']);
});

test('parseAll returns empty array for empty input', function () {
    expect((new DiffParser)->parseAll([]))->toBe([]);
});
