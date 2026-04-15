<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;
use Vistik\LaravelCodeAnalytics\Enums\GraphLayout;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;

class GenerateLlmReport extends GenerateMetricsReport implements ReportGenerator
{
    /** @param list<string>|null $focusPatterns */
    public function __construct(private readonly ?array $focusPatterns = null) {}

    public function generate(
        GraphPayload $payload,
        PullRequestContext $pr,
        ?GraphLayout $defaultView = null,
        ?LayerStack $layerStack = null,
    ): string {
        $focusedPayload = $this->focusPatterns !== null
            ? new GraphPayload(
                nodes: $payload->nodes,
                edges: $payload->edges,
                fileDiffs: $payload->fileDiffs,
                analysisData: $payload->analysisData,
                metricsData: $this->filterByFocus($payload->metricsData),
                fileContents: $payload->fileContents,
                filterDefaults: $payload->filterDefaults,
                riskScore: $payload->riskScore,
            )
            : $payload;

        $output = parent::generate($focusedPayload, $pr, $defaultView, $layerStack);
        $metricsData = $focusedPayload->metricsData;

        if (empty($metricsData)) {
            return $output;
        }

        $methodLines = [];

        foreach ($metricsData as $path => $m) {
            $methods = $m['method_metrics'] ?? [];
            if (empty($methods)) {
                continue;
            }

            $beforeMethods = [];
            foreach ($m['before_method_metrics'] ?? [] as $bm) {
                $beforeMethods[$bm['name']] = $bm;
            }
            $hasBefore = ! empty($beforeMethods);

            $methodLines[] = sprintf('  %s — methods:', $path);
            $methodLines[] = sprintf('    %-30s  %6s  %6s  %6s', '', 'CC', 'LLOC', 'Params');
            $methodLines[] = '    '.str_repeat('─', 52);

            usort($methods, fn ($a, $b) => $b['cc'] <=> $a['cc'] ?: $b['lloc'] <=> $a['lloc']);

            foreach ($methods as $method) {
                $name = $method['name'];
                $methodLines[] = sprintf(
                    '    %-30s  %6s  %6s  %6s',
                    $name,
                    $method['cc'],
                    $method['lloc'],
                    $method['params'],
                );

                if ($hasBefore) {
                    $bm = $beforeMethods[$name] ?? null;
                    $d = fn (string $key) => $this->methodDelta($method[$key], $bm[$key] ?? null);
                    $deltas = [$d('cc'), $d('lloc'), $d('params')];
                    if (array_filter($deltas, fn ($v) => $v !== '=')) {
                        $methodLines[] = sprintf(
                            '    %-30s  %6s  %6s  %6s',
                            '  delta',
                            ...$deltas,
                        );
                    }
                }
            }
        }

        if (empty($methodLines)) {
            return $output;
        }

        $output = rtrim($output)."\n\nMethod Metrics:\n".implode("\n", $methodLines)."\n";

        $depLines = $this->buildDepsSection($payload);
        if ($depLines !== '') {
            $output = rtrim($output)."\n\n".$depLines;
        }

        return $output;
    }

    private function buildDepsSection(GraphPayload $payload): string
    {
        $edges = $payload->edges;
        if (empty($edges)) {
            return '';
        }

        $idToPath = [];
        foreach ($payload->nodes as $node) {
            $idToPath[$node['id']] = $node['path'];
        }

        $outgoing = [];
        $incoming = [];
        foreach ($edges as [$src, $tgt]) {
            $srcPath = $idToPath[$src] ?? $src;
            $tgtPath = $idToPath[$tgt] ?? $tgt;
            $outgoing[$srcPath][] = $tgtPath;
            $incoming[$tgtPath][] = $srcPath;
        }

        // When focus patterns are set, only show entries for matching files.
        // We still use the full graph so <- incoming edges are captured.
        $subjects = array_keys($outgoing);
        if ($this->focusPatterns !== null) {
            $subjects = array_values(array_filter(
                $subjects,
                fn ($f) => $this->matchesFocus($f),
            ));
            // Also include focus files that only appear as targets (only incoming edges).
            foreach (array_keys($incoming) as $file) {
                if ($this->matchesFocus($file) && ! in_array($file, $subjects, true)) {
                    $subjects[] = $file;
                }
            }
        }

        if (empty($subjects)) {
            return '';
        }

        $lines = ['Dependencies:'];
        foreach ($subjects as $file) {
            $lines[] = '  '.$file.':';
            foreach (array_unique($outgoing[$file] ?? []) as $dep) {
                $lines[] = '    -> '.$dep;
            }
            foreach (array_unique($incoming[$file] ?? []) as $dep) {
                $lines[] = '    <- '.$dep;
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function matchesFocus(string $path): bool
    {
        if ($this->focusPatterns === null) {
            return true;
        }
        foreach ($this->focusPatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    private function filterByFocus(array $data): array
    {
        if ($this->focusPatterns === null) {
            return $data;
        }

        return array_filter($data, fn ($path) => $this->matchesFocus($path), ARRAY_FILTER_USE_KEY);
    }

    private function methodDelta(int|float $after, int|float|null $before): string
    {
        if ($before === null) {
            return 'new';
        }
        $diff = $after - $before;
        if ($diff === 0) {
            return '=';
        }

        return sprintf('%+g', $diff);
    }
}
