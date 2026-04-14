<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\DiffParser\DiffParser;
use Vistik\LaravelCodeAnalytics\Enums\GraphLayout;
use Vistik\LaravelCodeAnalytics\Enums\NodeKind;
use Vistik\LaravelCodeAnalytics\GraphIndex\GraphIndexBuilder;
use Vistik\LaravelCodeAnalytics\PhpMetrics\FileMetrics;
use Vistik\LaravelCodeAnalytics\PhpMetrics\HotspotRatioBadgeDecider;
use Vistik\LaravelCodeAnalytics\PhpMetrics\MetricTrend;
use Vistik\LaravelCodeAnalytics\PhpMetrics\PhpMetricsBadgeDeciderInterface;
use Vistik\LaravelCodeAnalytics\PhpMetrics\PhpMetricsScorerInterface;
use Vistik\LaravelCodeAnalytics\PhpMetrics\WeightedDegradationScorer;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;
use Vistik\LaravelCodeAnalytics\Reports\FilterTogglesHtml;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

class GenerateHtmlReport implements ReportGenerator
{
    public function __construct(
        private readonly PhpMetricsScorerInterface $metricsScorer = new WeightedDegradationScorer,
        private readonly PhpMetricsBadgeDeciderInterface $metricsBadgeDecider = new HotspotRatioBadgeDecider,
    ) {}

    /** Generate HTML string for a single layout. */
    public function execute(
        GraphPayload $payload,
        PullRequestContext $pr,
        FilterTogglesHtml $toggles,
        GraphLayout $layout = GraphLayout::Force,
        ?LayerStack $layerStack = null,
    ): string {
        return $this->render($payload, $pr, $toggles, $layout, $layerStack);
    }

    /**
     * Write standalone HTML files for one or all layouts.
     *
     * @return array<string, string> Layout name => file path
     */
    public function writeFiles(
        GraphPayload $payload,
        PullRequestContext $pr,
        FilterTogglesHtml $toggles,
        string $outputDir,
        ?string $outputPath = null,
        GraphLayout $layout = GraphLayout::Force,
        bool $allLayouts = false,
        ?LayerStack $layerStack = null,
        ?array $onlyLayouts = null,
    ): array {
        $layouts = $allLayouts || ! $outputPath
            ? GraphLayout::cases()
            : [$layout];
        if ($onlyLayouts !== null) {
            $layouts = array_values(array_filter(
                GraphLayout::cases(),
                fn (GraphLayout $g) => in_array($g->value, $onlyLayouts, true),
            ));
        }

        // Pre-determine all file paths so the layout switcher can be built upfront.
        $generatedFiles = [];
        foreach ($layouts as $graphLayout) {
            $generatedFiles[$graphLayout->value] = $outputPath ?? "{$outputDir}/pr-{$pr->prNumber}-{$graphLayout->value}.html";
        }

        foreach ($generatedFiles as $layoutValue => $file) {
            $currentLayout = GraphLayout::from($layoutValue);
            $switcherHtml = count($generatedFiles) > 1
                ? $this->buildLayoutSwitcher($currentLayout, $generatedFiles)
                : '';

            file_put_contents($file, $this->render($payload, $pr, $toggles, $currentLayout, $layerStack, $switcherHtml));
        }

        return $generatedFiles;
    }

    protected function render(
        GraphPayload $payload,
        PullRequestContext $pr,
        FilterTogglesHtml $toggles,
        GraphLayout $layout,
        ?LayerStack $layerStack = null,
        string $layoutSwitcher = '',
    ): string {
        $renderer = $layout->renderer($layerStack);

        $nodesJson = json_encode($payload->nodes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $edgesJson = json_encode($payload->edges, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $diffsJson = json_encode($payload->fileDiffs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $analysisJson = json_encode($payload->analysisData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $metricsJson = json_encode($payload->metricsData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $fileContentsJson = json_encode((object) $payload->fileContents, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        $methodThresholds = config('laravel-code-analytics.method_metric_thresholds', [
            'cc' => ['warn' => 5,  'bad' => 10],
            'lloc' => ['warn' => 20, 'bad' => 50],
            'params' => ['warn' => 3,  'bad' => 5],
        ]);
        $methodThresholdsJson = json_encode($methodThresholds, JSON_HEX_TAG);

        $fd = $payload->filterDefaults;
        $filterDefaultsJson = json_encode([
            'hide_connected' => $fd['hide_connected'] ?? true,
            'hide_reviewed' => $fd['hide_reviewed'] ?? true,
            'hidden_domains' => array_values($fd['hidden_domains'] ?? ['tests']),
            'hidden_severities' => array_values($fd['hidden_severities'] ?? []),
            'hidden_extensions' => array_values($fd['hidden_extensions'] ?? []),
            'hidden_change_types' => array_values($fd['hidden_change_types'] ?? []),
            'hidden_kinds' => array_values($fd['hidden_kinds'] ?? []),
        ], JSON_HEX_TAG);

        $parsedDiffsJson = json_encode(
            (new DiffParser)->parseAll($payload->fileDiffs),
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG,
        );

        $graphIndex = (new GraphIndexBuilder)->build(
            nodes: $payload->nodes,
            edges: $payload->edges,
            metricsData: $payload->metricsData,
            fileDiffs: $payload->fileDiffs,
            fileContents: $payload->fileContents,
        );
        $graphIndexJson = json_encode($graphIndex, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        return view()->file(__DIR__.'/../../resources/views/analysis/inner.blade.php', [
            'prNumber' => $pr->prNumber,
            'prTitle' => $pr->prTitle,
            'prUrl' => $pr->prUrl,
            'fileCount' => $pr->fileCount,
            'prAdditions' => $pr->prAdditions,
            'prDeletions' => $pr->prDeletions,
            'repo' => $pr->repo,
            'headCommit' => substr($pr->headCommit, 0, 7),
            'nodesJson' => $nodesJson,
            'edgesJson' => $edgesJson,
            'diffsJson' => $diffsJson,
            'fileContentsJson' => $fileContentsJson,
            'analysisJson' => $analysisJson,
            'metricsJson' => $metricsJson,
            'methodThresholdsJson' => $methodThresholdsJson,
            'severityDataJs' => $this->buildSeverityDataJs(),
            'extTogglesHtml' => $toggles->ext,
            'folderTogglesHtml' => $toggles->folder,
            'severityTogglesHtml' => $toggles->severity,
            'kindTogglesHtml' => $toggles->kind,
            'connectedCount' => $pr->connectedCount,
            'layoutSetupJs' => $renderer->getLayoutSetupJs(),
            'simulationJs' => $renderer->getSimulationJs(),
            'frameHookJs' => $renderer->getFrameHookJs(),
            'riskPanel' => $this->buildRiskPanel($payload->riskScore),
            'layoutSwitcher' => $layoutSwitcher,
            'filterDefaultsJson' => $filterDefaultsJson,
            'graphIndexJson' => $graphIndexJson,
            'parsedDiffsJson' => $parsedDiffsJson,
        ])->render();
    }

    /**
     * @param  array<string, array{cc?: int, mi?: float, bugs?: float, coupling?: int, lloc?: int, methods?: int, before?: array<string, mixed>}>  $metricsData
     */
    public function buildMetricsBadge(array $metricsData): string
    {
        if (empty($metricsData)) {
            return '';
        }

        ['files' => $files, 'hotspotCount' => $hotspotCount] = $this->collectFileData($metricsData);

        $total = count($files);
        $badge = $this->metricsBadgeDecider->decide($hotspotCount, $total);
        $badgeColor = $badge->color();
        $badgeLabel = $badge->label($hotspotCount);

        $hasBeforeData = count(array_filter($files, fn ($f) => $f['hasBefore'])) > 0;
        $trendHtml = '';
        if ($hasBeforeData) {
            $totalDegradation = (float) array_sum(array_column($files, 'degradationScore'));
            $trend = MetricTrend::fromDegradation($totalDegradation);
            $trendHtml = "<span style=\"font-size:14px;color:{$trend->color()};font-weight:600\">{$trend->icon()}</span>";
        }

        $rows = '';
        foreach ($files as $file) {
            $name = htmlspecialchars($file['name']);
            $newTag = ! $file['hasBefore']
                ? '<span style="color:#3fb950;font-size:9px;font-weight:500;margin-right:5px">(new)</span>'
                : '';
            $hotspotDot = $file['isHotspot']
                ? '<span style="color:#f85149;font-size:7px;margin-right:4px" title="hotspot">&#9679;</span>'
                : '';

            $escapedPath = htmlspecialchars($file['path']);
            $rows .= "<tr data-path=\"{$escapedPath}\">"
                ."<td style=\"padding:2px 12px 2px 0;color:#c9d1d9;white-space:nowrap\">{$hotspotDot}{$newTag}{$name}</td>"
                .$this->metricCellHtml('cc', $file['metrics'], $file['deltas'], true)
                .$this->metricCellHtml('mi', $file['metrics'], $file['deltas'], false)
                .$this->metricCellHtml('bugs', $file['metrics'], $file['deltas'], true)
                .$this->metricCellHtml('coupling', $file['metrics'], $file['deltas'], true)
                .$this->metricCellHtml('lloc', $file['metrics'], $file['deltas'], true)
                .$this->metricCellHtml('methods', $file['metrics'], $file['deltas'], true)
                .'</tr>';
        }

        $th = '<th style="padding:0 10px 6px 0;color:#6e7681;font-weight:500;text-align:right;white-space:nowrap">';
        $thLeft = '<th style="padding:0 12px 6px 0;color:#6e7681;font-weight:500;text-align:left">';

        $tooltip = '<div class="metrics-tooltip">'
            .'<div style="font-size:11px;color:#6e7681;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px">Code Quality</div>'
            .'<table style="border-collapse:collapse;font-size:11px">'
            ."<thead><tr>{$thLeft}File</th>{$th}CC</th>{$th}MI</th>{$th}Bugs</th>{$th}Coupling</th>{$th}LLOC</th>{$th}Methods</th></tr></thead>"
            ."<tbody>{$rows}</tbody>"
            .'</table>'
            .'</div>';

        return '<div class="metrics-badge">'
            .'<span style="font-size:11px;color:#6e7681;text-transform:uppercase;letter-spacing:0.5px">Code</span>'
            ."<span style=\"font-size:20px;font-weight:700;color:{$badgeColor};line-height:1\">{$total}</span>"
            .'<span style="font-size:11px;color:#6e7681">files</span>'
            ."<span style=\"font-size:11px;color:{$badgeColor};background:{$badge->bgColor()};padding:2px 8px;border-radius:10px;border:1px solid {$badge->borderColor()};font-weight:500\">{$badgeLabel}</span>"
            .$trendHtml
            .$tooltip
            .'</div>';
    }

    /** @return array{files: array<int, array<string, mixed>>, hotspotCount: int} */
    private function collectFileData(array $metricsData): array
    {
        $files = [];
        $hotspotCount = 0;

        foreach ($metricsData as $path => $m) {
            $current = FileMetrics::fromArray($m);
            $before = isset($m['before']) ? FileMetrics::fromArray($m['before']) : null;

            $deltas = [];
            $beforeArr = $m['before'] ?? [];
            foreach (['cc', 'mi', 'bugs', 'coupling', 'lloc', 'methods'] as $key) {
                if (isset($m[$key], $beforeArr[$key])) {
                    $deltas[$key] = $m[$key] - $beforeArr[$key];
                }
            }

            $isHotspot = $this->metricsScorer->isHotspot($current);
            if ($isHotspot) {
                $hotspotCount++;
            }

            $files[] = [
                'path' => $path,
                'name' => basename($path),
                'current' => $current,
                'hasBefore' => $before !== null,
                'isHotspot' => $isHotspot,
                'metrics' => $m,
                'deltas' => $deltas,
                'degradationScore' => $this->metricsScorer->degradationScore($current, $before),
            ];
        }

        usort($files, fn ($a, $b) => $b['degradationScore'] <=> $a['degradationScore']);

        return ['files' => $files, 'hotspotCount' => $hotspotCount];
    }

    private function metricCellHtml(string $key, array $m, array $d, bool $higherIsBad): string
    {
        $val = $m[$key] ?? null;
        if ($val === null) {
            return '<td style="padding:2px 10px 2px 0;color:#484f58">—</td>';
        }

        $displayVal = is_float($val) ? number_format((float) $val, 1) : $val;
        $dot = $this->qualityDotHtml($key, $val);
        $deltaHtml = $this->metricDeltaHtml($d[$key] ?? null, $higherIsBad);

        return "<td style=\"padding:2px 10px 2px 0;color:#8b949e;text-align:right\">{$dot}{$displayVal}{$deltaHtml}</td>";
    }

    private function metricDeltaHtml(mixed $delta, bool $higherIsBad): string
    {
        if ($delta === null || abs((float) $delta) < 0.005) {
            return '';
        }

        $sign = $delta > 0 ? '+' : '';
        $formatted = abs($delta) < 1 ? ltrim(number_format((float) $delta, 2), '0') : (string) (int) round($delta);
        $bad = $higherIsBad ? $delta > 0 : $delta < 0;
        $deltaColor = $bad ? '#f85149' : '#3fb950';

        return "<span style=\"color:{$deltaColor};font-size:9px;margin-left:2px\">{$sign}{$formatted}</span>";
    }

    private function qualityDotHtml(string $key, mixed $val): string
    {
        $color = $this->qualityDotColor($key, $val);

        return $color !== null
            ? "<span style=\"color:{$color};font-size:7px;margin-right:3px\">&#9679;</span>"
            : '';
    }

    private function qualityDotColor(string $key, mixed $val): ?string
    {
        return match ($key) {
            'cc' => $this->ccDotColor($val),
            'mi' => $this->miDotColor($val),
            'bugs' => $this->bugsDotColor($val),
            'coupling' => $this->couplingDotColor($val),
            default => null,
        };
    }

    private function ccDotColor(mixed $val): string
    {
        return match (true) {
            $val > 10 => '#f85149',
            $val > 5 => '#d29922',
            default => '#3fb950',
        };
    }

    private function miDotColor(mixed $val): string
    {
        return match (true) {
            $val < 85 => '#f85149',
            $val < 100 => '#d29922',
            default => '#3fb950',
        };
    }

    private function bugsDotColor(mixed $val): string
    {
        return match (true) {
            $val > 0.1 => '#f85149',
            $val > 0.05 => '#d29922',
            default => '#3fb950',
        };
    }

    private function couplingDotColor(mixed $val): string
    {
        return match (true) {
            $val > 15 => '#f85149',
            $val > 8 => '#d29922',
            default => '#3fb950',
        };
    }

    public function buildQualitySummaryHtml(array $metricsData): string
    {
        if (empty($metricsData)) {
            return '';
        }

        $hotspotCount = 0;
        $total = 0;
        $totalDegradation = 0.0;
        $hasBeforeData = false;

        foreach ($metricsData as $m) {
            $current = FileMetrics::fromArray($m);
            $before = isset($m['before']) ? FileMetrics::fromArray($m['before']) : null;

            if ($this->metricsScorer->isHotspot($current)) {
                $hotspotCount++;
            }
            if ($before !== null) {
                $hasBeforeData = true;
                $totalDegradation += $this->metricsScorer->degradationScore($current, $before);
            }
            $total++;
        }

        $badge = $this->metricsBadgeDecider->decide($hotspotCount, $total);
        $color = $badge->color();
        $bgColor = $badge->bgColor();
        $borderColor = $badge->borderColor();
        $label = $badge->label($hotspotCount);

        $trendHtml = '';
        if ($hasBeforeData) {
            $trend = MetricTrend::fromDegradation($totalDegradation);
            $trendHtml = "<span style=\"font-size:13px;font-weight:700;margin-left:5px;color:{$trend->color()}\">{$trend->icon()}</span>";
        }

        return '<div style="display:flex;align-items:center;gap:8px;flex-shrink:0">'
            .'<span style="font-size:11px;color:#6e7681;text-transform:uppercase;letter-spacing:0.5px">Quality</span>'
            ."<span style=\"display:inline-flex;align-items:center;font-size:11px;color:{$color};background:{$bgColor};padding:2px 8px;border-radius:10px;border:1px solid {$borderColor};font-weight:500\">{$label}{$trendHtml}</span>"
            .'</div>';
    }

    private function buildRiskBadge(?RiskScore $riskScore): string
    {
        if ($riskScore === null) {
            return '';
        }

        $score = $riskScore->score;
        $factors = $riskScore->factors;

        [$color, $bgColor, $borderColor, $label] = match (true) {
            $score >= 75 => ['#f85149', '#3d1214', '#da3633', 'Very High'],
            $score >= 50 => ['#d29922', '#2d1c00', '#9e6a03', 'High'],
            $score >= 25 => ['#58a6ff', '#1c2a3a', '#1f6feb', 'Medium'],
            default => ['#3fb950', '#0d3520', '#238636', 'Low'],
        };

        $tooltipRows = '';
        foreach ($factors as $factor) {
            $pct = $factor['maxScore'] > 0
                ? round(($factor['score'] / $factor['maxScore']) * 100)
                : 0;

            $barColor = match (true) {
                $pct >= 75 => '#f85149',
                $pct >= 40 => '#d29922',
                default => '#3fb950',
            };

            $name = htmlspecialchars($factor['name']);
            $pts = "{$factor['score']}/{$factor['maxScore']}";

            $tooltipRows .= '<div style="margin-bottom:6px">'
                ."<div style=\"display:flex;justify-content:space-between;font-size:10px;color:#6e7681;margin-bottom:3px\"><span>{$name}</span><span style=\"color:#8b949e\">{$pts}</span></div>"
                .'<div style="height:3px;background:#21262d;border-radius:2px">'
                ."<div style=\"height:3px;width:{$pct}%;background:{$barColor};border-radius:2px\"></div>"
                .'</div>'
                .'</div>';
        }

        $tooltip = $tooltipRows !== ''
            ? '<div class="risk-tooltip">'
                .'<div style="font-size:11px;color:#6e7681;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px">Risk Breakdown</div>'
                .$tooltipRows
                .'</div>'
            : '';

        return '<div class="risk-badge">'
            ."<span class=\"risk-score-num\" style=\"color:{$color}\">{$score}</span>"
            .'<span class="risk-score-denom">/ 100</span>'
            ."<span class=\"risk-label\" style=\"color:{$color};background:{$bgColor};border-color:{$borderColor}\">{$label}</span>"
            .$tooltip
            .'</div>';
    }

    private function buildRiskPanel(?RiskScore $riskScore): string
    {
        if ($riskScore === null) {
            return '';
        }

        $score = $riskScore->score;
        $factors = $riskScore->factors;

        [$color, $bgColor, $borderColor, $label] = match (true) {
            $score >= 75 => ['#f85149', '#3d1214', '#da3633', 'Very High'],
            $score >= 50 => ['#d29922', '#2d1c00', '#9e6a03', 'High'],
            $score >= 25 => ['#58a6ff', '#1c2a3a', '#1f6feb', 'Medium'],
            default => ['#3fb950', '#0d3520', '#238636', 'Low'],
        };

        $html = '<div style="border-bottom:1px solid #30363d;padding-bottom:10px;margin-bottom:6px">'
            .'<span style="font-size:11px;color:#6e7681;text-transform:uppercase;letter-spacing:0.5px">Risk Score</span>'
            .'<div style="display:flex;align-items:baseline;gap:6px;margin-top:4px">'
            ."<span style=\"font-size:28px;font-weight:700;color:{$color};line-height:1\">{$score}</span>"
            .'<span style="font-size:12px;color:#6e7681">/ 100</span>'
            ."<span style=\"font-size:10px;color:{$color};background:{$bgColor};padding:2px 7px;border-radius:10px;border:1px solid {$borderColor};margin-left:2px\">{$label}</span>"
            .'</div>';

        if (! empty($factors)) {
            $html .= '<div style="margin-top:8px;display:flex;flex-direction:column;gap:5px">';
            foreach ($factors as $factor) {
                $pct = $factor['maxScore'] > 0
                    ? round(($factor['score'] / $factor['maxScore']) * 100)
                    : 0;

                $barColor = match (true) {
                    $pct >= 75 => '#f85149',
                    $pct >= 40 => '#d29922',
                    default => '#3fb950',
                };

                $name = htmlspecialchars($factor['name']);
                $pts = "{$factor['score']}/{$factor['maxScore']}";

                $html .= '<div>'
                    ."<div style=\"display:flex;justify-content:space-between;font-size:10px;color:#6e7681;margin-bottom:2px\"><span>{$name}</span><span>{$pts}</span></div>"
                    .'<div style="height:3px;background:#21262d;border-radius:2px">'
                    ."<div style=\"height:3px;width:{$pct}%;background:{$barColor};border-radius:2px\"></div>"
                    .'</div>'
                    .'</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private function buildSeverityDataJs(): string
    {
        $colors = [];
        $labels = [];
        $order = [];
        $scores = [];
        $i = 0;
        foreach (array_reverse(Severity::cases()) as $severity) {
            $key = $severity->value;
            $colors[] = "'{$key}':'{$severity->color()}'";
            $labels[] = "'{$key}':'{$severity->label()}'";
            $order[] = "'{$key}':{$i}";
            $scores[] = "'{$key}':{$severity->score()}";
            $i++;
        }

        return 'const sevColors={'.implode(',', $colors).'};'
            .'const sevLabels={'.implode(',', $labels).'};'
            .'const sevOrder={'.implode(',', $order).'};'
            .'const sevScores={'.implode(',', $scores).'};';
    }

    /** @param list<string> $hiddenKinds */
    public function buildKindToggles(array $nodes, array $hiddenKinds = []): string
    {
        $kindCounts = [];
        foreach (NodeKind::cases() as $kind) {
            $kindCounts[$kind->value] = 0;
        }

        foreach ($nodes as $node) {
            $k = $node['kind'] ?? null;
            if ($k !== null && isset($kindCounts[$k])) {
                $kindCounts[$k]++;
            }
        }

        $html = '';
        foreach (NodeKind::cases() as $kind) {
            $count = $kindCounts[$kind->value];
            if ($count === 0) {
                continue;
            }
            $checked = ! in_array($kind->value, $hiddenKinds) ? ' checked' : '';
            $badge = '<span style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:'.$kind->color().';color:#161b22;font-size:8px;font-weight:700;flex-shrink:0">'.$kind->letter().'</span>';
            $html .= '<div class="toggle-row">'
                .$badge
                .'<label class="toggle"><input type="checkbox" class="kind-toggle" data-kind="'.htmlspecialchars($kind->value).'"'.$checked.'><span class="slider"></span></label>'
                .'<label class="toggle-label">'.$kind->label().' <span style="color:#484f58">('.$count.')</span></label>'
                .'</div>';
        }

        return $html;
    }

    public function buildSeverityToggles(array $nodes, array $hiddenSeverities = []): string
    {
        $severityCounts = [];
        foreach (Severity::cases() as $severity) {
            $severityCounts[$severity->value] = 0;
        }

        foreach ($nodes as $node) {
            $sev = $node['severity'] ?? null;
            if ($sev !== null && isset($severityCounts[$sev])) {
                $severityCounts[$sev]++;
            }
        }

        $html = '';
        foreach (array_reverse(Severity::cases()) as $severity) {
            $count = $severityCounts[$severity->value];
            if ($count === 0) {
                continue;
            }
            $checked = ! in_array($severity->value, $hiddenSeverities) ? ' checked' : '';
            $html .= '<div class="toggle-row">'
                .'<span class="legend-dot" style="background:'.$severity->color().'"></span>'
                .'<label class="toggle"><input type="checkbox" class="severity-toggle" data-severity="'.htmlspecialchars($severity->value).'"'.$checked.'><span class="slider"></span></label>'
                .'<label class="toggle-label">'.$severity->label().' <span style="color:#484f58">('.$count.')</span></label>'
                .'</div>';
        }

        return $html;
    }

    /** @param list<string> $hiddenExtensions */
    public function buildExtToggles(array $nodes, array $hiddenExtensions = []): string
    {
        $extCounts = [];
        foreach ($nodes as $node) {
            $extCounts[$node['ext']] = ($extCounts[$node['ext']] ?? 0) + 1;
        }
        ksort($extCounts);

        $html = '';
        foreach ($extCounts as $ext => $count) {
            $checked = ! in_array($ext, $hiddenExtensions) ? ' checked' : '';
            $html .= '<div class="toggle-row">'
                .'<label class="toggle"><input type="checkbox" class="ext-toggle" data-ext="'.htmlspecialchars($ext).'"'.$checked.'><span class="slider"></span></label>'
                .'<label class="toggle-label">'.htmlspecialchars($ext).' <span style="color:#484f58">('.$count.')</span></label>'
                .'</div>';
        }

        return $html;
    }

    /** @param list<string> $hiddenDomains */
    public function buildFolderToggles(array $nodes, array $hiddenDomains = ['tests']): string
    {
        $domainCounts = [];
        foreach ($nodes as $node) {
            $domain = $node['domain'];
            $domainCounts[$domain] = ($domainCounts[$domain] ?? 0) + 1;
        }
        ksort($domainCounts);

        $html = '';
        foreach ($domainCounts as $domain => $count) {
            $color = $nodes[array_key_first(
                array_filter($nodes, fn ($n) => $n['domain'] === $domain)
            )]['domainColor'] ?? '#8b949e';

            $checked = ! in_array($domain, $hiddenDomains) ? ' checked' : '';
            $html .= '<div class="toggle-row">'
                .'<span class="legend-dot" style="background:'.htmlspecialchars($color).'"></span>'
                .'<label class="toggle"><input type="checkbox" class="domain-toggle" data-domain="'.htmlspecialchars($domain).'"'.$checked.'><span class="slider"></span></label>'
                .'<label class="toggle-label">'.htmlspecialchars($domain).' <span style="color:#484f58">('.$count.')</span></label>'
                .'</div>';
        }

        return $html;
    }

    private function buildLayoutSwitcher(GraphLayout $current, array $files): string
    {
        $buttons = '';
        foreach ($files as $layoutValue => $path) {
            $graphLayout = GraphLayout::from($layoutValue);
            $filename = basename($path);
            if ($graphLayout === $current) {
                $buttons .= "<span class=\"layout-btn active\">{$graphLayout->label()}</span>";
            } else {
                $buttons .= "<a class=\"layout-btn\" href=\"{$filename}\">{$graphLayout->label()}</a>";
            }
        }

        return $buttons;
    }

    public function generate(
        GraphPayload $payload,
        PullRequestContext $pr,
        ?GraphLayout $defaultView = null,
        ?LayerStack $layerStack = null,
    ): string {
        $toggles = new FilterTogglesHtml(
            ext: $this->buildExtToggles($payload->nodes, $payload->filterDefaults['hidden_extensions'] ?? []),
            folder: $this->buildFolderToggles($payload->nodes, $payload->filterDefaults['hidden_domains'] ?? ['tests']),
            severity: $this->buildSeverityToggles($payload->nodes, $payload->filterDefaults['hidden_severities'] ?? []),
            kind: $this->buildKindToggles($payload->nodes, $payload->filterDefaults['hidden_kinds'] ?? []),
        );

        return $this->buildWrapperHtml($payload, $pr, $toggles, $layerStack, defaultView: $defaultView ?? GraphLayout::Force);
    }

    public function writeFile(string $outputPath, string $content): void
    {
        file_put_contents($outputPath, $content);
    }

    /** Generate a single HTML file containing all layouts as embedded iframes with a tab switcher. */
    public function writeSingleFile(
        GraphPayload $payload,
        PullRequestContext $pr,
        FilterTogglesHtml $toggles,
        string $outputPath,
        ?LayerStack $layerStack = null,
        GraphLayout $defaultView = GraphLayout::Force,
    ): void {
        file_put_contents($outputPath, $this->buildWrapperHtml($payload, $pr, $toggles, $layerStack, $defaultView));
    }

    private function buildWrapperHtml(
        GraphPayload $payload,
        PullRequestContext $pr,
        FilterTogglesHtml $toggles,
        ?LayerStack $layerStack = null,
        GraphLayout $defaultView = GraphLayout::Grouped,
    ): string {
        // Risk panel lives in the wrapper topbar — suppress it in each inner iframe.
        $innerPayload = new GraphPayload(
            nodes: $payload->nodes,
            edges: $payload->edges,
            fileDiffs: $payload->fileDiffs,
            analysisData: $payload->analysisData,
            metricsData: $payload->metricsData,
            fileContents: $payload->fileContents,
            filterDefaults: $payload->filterDefaults,
            riskScore: null,
        );

        $jsEntries = [];
        foreach (GraphLayout::cases() as $graphLayout) {
            $html = $this->execute($innerPayload, $pr, $toggles, $graphLayout, $layerStack);
            // Hide the title-bar inside the iframe — it lives in the wrapper now
            $htmlForWrapper = str_replace('</head>', '<style>.title-bar{display:none!important}</style></head>', $html);
            $jsEntries[] = "'{$graphLayout->value}':'".base64_encode($htmlForWrapper)."'";
        }

        $tabButtons = '';
        foreach (GraphLayout::cases() as $graphLayout) {
            $active = $graphLayout === $defaultView ? ' active' : '';
            $tabButtons .= "<button class=\"tab{$active}\" data-layout=\"{$graphLayout->value}\">{$graphLayout->label()}</button>";
        }

        $escapedTitle = htmlspecialchars($pr->prTitle);
        $escapedUrl = htmlspecialchars($pr->prUrl);
        $headlineHtml = $pr->prUrl
            ? "<a href=\"{$escapedUrl}\" target=\"_blank\" class=\"pr-link\">{$escapedTitle}</a>"
            : "<span class=\"pr-link\">{$escapedTitle}</span>";

        return view()->file(__DIR__.'/../../resources/views/analysis/wrapper.blade.php', [
            'prNumber' => $pr->prNumber,
            'escapedTitle' => $escapedTitle,
            'headlineHtml' => $headlineHtml,
            'fileCount' => $pr->fileCount,
            'prAdditions' => $pr->prAdditions,
            'prDeletions' => $pr->prDeletions,
            'riskBadgeHtml' => $this->buildRiskBadge($payload->riskScore),
            'tabButtons' => $tabButtons,
            'wrapperSeverityJs' => $this->buildSeverityDataJs(),
            'wrapperNodesJson' => json_encode($payload->nodes, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG),
            'wrapperAnalysisJson' => json_encode($payload->analysisData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG),
            'wrapperMetricsJson' => json_encode($payload->metricsData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG),
            'jsLayoutData' => implode(",\n    ", $jsEntries),
            'defaultView' => $defaultView->value,
        ])->render();
    }
}
