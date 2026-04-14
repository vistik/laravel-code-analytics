<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;
use Vistik\LaravelCodeAnalytics\Enums\GraphLayout;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;

/**
 * Ultra-compact report for LLM consumption.
 *
 * Format:
 *   RISK:<score> +<add>-<del> files:<n> head:<sha7>
 *
 *   DEPS
 *   <path>:<dep1>,<dep2>
 *
 *   FILES
 *   <signal> <sev> +<add>-<del> <path>
 *     <sev>:<description>
 */
class GenerateLlmReport implements ReportGenerator
{
    /** @param list<string>|null $focusPatterns */
    public function __construct(private readonly ?array $focusPatterns = null) {}

    private const SEV = [
        'info' => 'I',
        'low' => 'L',
        'medium' => 'M',
        'high' => 'H',
        'very_high' => 'VH',
    ];

    public function generate(
        GraphPayload $payload,
        PullRequestContext $pr,
        ?GraphLayout $defaultView = null,
        ?LayerStack $layerStack = null,
    ): string {
        $nodes = $payload->nodes;
        $edges = $payload->edges;
        $analysisData = $payload->analysisData;

        // id → path index
        $idToPath = [];
        foreach ($nodes as $node) {
            $idToPath[$node['id']] = $node['path'];
        }

        $lines = [];

        // ── Header ──────────────────────────────────────────────────────────
        $risk = $payload->riskScore !== null ? $payload->riskScore->score : '?';
        $sha = substr($pr->headCommit, 0, 7);
        $lines[] = "RISK:{$risk} +{$pr->prAdditions}-{$pr->prDeletions} files:{$pr->fileCount} head:{$sha}";

        if ($this->focusPatterns !== null) {
            // ── Focus mode ───────────────────────────────────────────────────
            // Identify focus files
            $focusPaths = [];
            foreach ($nodes as $node) {
                foreach ($this->focusPatterns as $pattern) {
                    if (str_contains($node['path'], $pattern)) {
                        $focusPaths[$node['path']] = true;
                        break;
                    }
                }
            }

            // Build outgoing (src→tgt) and incoming (tgt→src) neighbour sets
            $depsOut = []; // focus → [targets]
            $depsIn = [];  // focus → [sources]
            foreach ($edges as [$src, $tgt]) {
                $srcPath = $idToPath[$src] ?? $src;
                $tgtPath = $idToPath[$tgt] ?? $tgt;
                if (isset($focusPaths[$srcPath])) {
                    $depsOut[$srcPath][] = $tgtPath;
                }
                if (isset($focusPaths[$tgtPath])) {
                    $depsIn[$tgtPath][] = $srcPath;
                }
            }

            foreach (array_keys($focusPaths) as $path) {
                $lines[] = '';
                $lines[] = "FOCUS {$path}";

                foreach ($analysisData[$path] ?? [] as $f) {
                    $fsev = self::SEV[$f['severity'] ?? 'info'] ?? 'I';
                    $desc = $f['description'] ?? '';
                    if (isset($f['location'])) {
                        $desc .= ' ['.$f['location'].']';
                    }
                    $lines[] = "  {$fsev}:{$desc}";
                }

                if (! empty($depsOut[$path])) {
                    $lines[] = '  DEPS_OUT:'.implode(',', array_unique($depsOut[$path]));
                }
                if (! empty($depsIn[$path])) {
                    $lines[] = '  DEPS_IN:'.implode(',', array_unique($depsIn[$path]));
                }
            }
        } else {
            // ── Full report mode ─────────────────────────────────────────────
            // Files sorted by signal, skip unchanged no-signal entries
            $sorted = array_values(array_filter(
                $nodes,
                fn ($n) => ($n['add'] + $n['del']) > 0 || ($n['_signal'] ?? 0) > 0,
            ));
            usort($sorted, fn ($a, $b) => ($b['_signal'] ?? 0) <=> ($a['_signal'] ?? 0));

            if (! empty($sorted)) {
                $lines[] = '';
                $lines[] = 'FILES';
                foreach ($sorted as $node) {
                    $signal = $node['_signal'] ?? 0;
                    $sev = self::SEV[$node['severity'] ?? 'info'] ?? ($node['severity'] ?? 'I');
                    $lines[] = "{$signal} {$sev} +{$node['add']}-{$node['del']} {$node['path']}";

                    foreach ($analysisData[$node['path']] ?? [] as $f) {
                        $fsev = self::SEV[$f['severity'] ?? 'info'] ?? 'I';
                        $desc = $f['description'] ?? '';
                        if (isset($f['location'])) {
                            $desc .= ' ['.$f['location'].']';
                        }
                        $lines[] = "  {$fsev}:{$desc}";
                    }
                }
            }

            // Dependency graph
            $depsBySource = [];
            foreach ($edges as [$src, $tgt]) {
                $srcPath = $idToPath[$src] ?? $src;
                $tgtPath = $idToPath[$tgt] ?? $tgt;
                $depsBySource[$srcPath][] = $tgtPath;
            }

            if (! empty($depsBySource)) {
                $lines[] = '';
                $lines[] = 'DEPS';
                foreach ($depsBySource as $src => $targets) {
                    $lines[] = $src.':'.implode(',', array_unique($targets));
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    public function writeFile(string $outputPath, string $content): void
    {
        file_put_contents($outputPath, $content);
    }
}
