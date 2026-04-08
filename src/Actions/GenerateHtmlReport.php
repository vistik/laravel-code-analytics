<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\Contracts\ReportGenerator;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\PhpMetrics\FileMetrics;
use Vistik\LaravelCodeAnalytics\PhpMetrics\HotspotRatioBadgeDecider;
use Vistik\LaravelCodeAnalytics\PhpMetrics\MetricTrend;
use Vistik\LaravelCodeAnalytics\PhpMetrics\PhpMetricsBadgeDeciderInterface;
use Vistik\LaravelCodeAnalytics\PhpMetrics\PhpMetricsScorerInterface;
use Vistik\LaravelCodeAnalytics\PhpMetrics\WeightedDegradationScorer;
use Vistik\LaravelCodeAnalytics\Renderers\ForceGraphRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\GroupedRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\LayeredArchRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\LayeredCakeRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;
use Vistik\LaravelCodeAnalytics\Renderers\LayoutRenderer;
use Vistik\LaravelCodeAnalytics\Renderers\TreeRenderer;
use Vistik\LaravelCodeAnalytics\RiskScoring\RiskScore;

class GenerateHtmlReport implements ReportGenerator
{
    /** @var array<string, class-string<LayoutRenderer>> */
    public const RENDERERS = [
        'force' => ForceGraphRenderer::class,
        'tree' => TreeRenderer::class,
        'grouped' => GroupedRenderer::class,
        'cake' => LayeredCakeRenderer::class,
        'arch' => LayeredArchRenderer::class,
    ];

    public function __construct(
        private readonly PhpMetricsScorerInterface $metricsScorer = new WeightedDegradationScorer,
        private readonly PhpMetricsBadgeDeciderInterface $metricsBadgeDecider = new HotspotRatioBadgeDecider,
    ) {}

    /**
     * Generate HTML string for a single layout from raw arrays.
     *
     * @param  array<string, array{cc?: int, mi?: float, bugs?: float, coupling?: int, lloc?: int, methods?: int}>  $metricsData
     */
    public function execute(
        array $nodes,
        array $edges,
        array $fileDiffs,
        array $analysisData,
        string $prNumber,
        string $prTitle,
        string $prUrl,
        int $prAdditions,
        int $prDeletions,
        string $repo,
        string $headCommit,
        int $fileCount,
        int $connectedCount,
        string $extTogglesHtml,
        string $folderTogglesHtml,
        string $severityTogglesHtml = '',
        string $layout = 'force',
        ?LayerStack $layerStack = null,
        ?RiskScore $riskScore = null,
        array $metricsData = [],
    ): string {
        return $this->render(
            nodes: $nodes,
            edges: $edges,
            fileDiffs: $fileDiffs,
            analysisData: $analysisData,
            prNumber: $prNumber,
            prTitle: $prTitle,
            prUrl: $prUrl,
            prAdditions: $prAdditions,
            prDeletions: $prDeletions,
            repo: $repo,
            headCommit: $headCommit,
            fileCount: $fileCount,
            connectedCount: $connectedCount,
            extTogglesHtml: $extTogglesHtml,
            folderTogglesHtml: $folderTogglesHtml,
            severityTogglesHtml: $severityTogglesHtml,
            layout: $layout,
            layerStack: $layerStack,
            riskScore: $riskScore,
            metricsData: $metricsData,
        );
    }

    /**
     * Write standalone HTML files for one or all layouts.
     *
     * @param  array<string, array{cc?: int, mi?: float, bugs?: float, coupling?: int, lloc?: int, methods?: int}>  $metricsData
     * @return array<string, string> Layout name => file path
     */
    public function writeFiles(
        array $nodes,
        array $edges,
        array $fileDiffs,
        array $analysisData,
        string $prNumber,
        string $prTitle,
        string $prUrl,
        int $prAdditions,
        int $prDeletions,
        string $repo,
        string $headCommit,
        int $fileCount,
        int $connectedCount,
        string $extTogglesHtml,
        string $folderTogglesHtml,
        string $severityTogglesHtml,
        string $outputDir,
        ?string $outputPath = null,
        string $layout = 'force',
        bool $allLayouts = false,
        ?LayerStack $layerStack = null,
        ?RiskScore $riskScore = null,
        array $metricsData = [],
    ): array {
        $layouts = $allLayouts || ! $outputPath
            ? array_keys(self::RENDERERS)
            : [$layout];

        // Pre-determine all file paths so the layout switcher can be built upfront.
        $generatedFiles = [];
        foreach ($layouts as $layoutName) {
            $generatedFiles[$layoutName] = $outputPath ?? "{$outputDir}/pr-{$prNumber}-{$layoutName}.html";
        }

        foreach ($generatedFiles as $currentLayout => $file) {
            $switcherHtml = count($generatedFiles) > 1
                ? $this->buildLayoutSwitcher($currentLayout, $generatedFiles)
                : '';

            $html = $this->render(
                nodes: $nodes,
                edges: $edges,
                fileDiffs: $fileDiffs,
                analysisData: $analysisData,
                prNumber: $prNumber,
                prTitle: $prTitle,
                prUrl: $prUrl,
                prAdditions: $prAdditions,
                prDeletions: $prDeletions,
                repo: $repo,
                headCommit: $headCommit,
                fileCount: $fileCount,
                connectedCount: $connectedCount,
                extTogglesHtml: $extTogglesHtml,
                folderTogglesHtml: $folderTogglesHtml,
                severityTogglesHtml: $severityTogglesHtml,
                layout: $currentLayout,
                layerStack: $layerStack,
                riskScore: $riskScore,
                metricsData: $metricsData,
                layoutSwitcher: $switcherHtml,
            );

            file_put_contents($file, $html);
        }

        return $generatedFiles;
    }

    /**
     * @param  array<string, array{cc?: int, mi?: float, bugs?: float, coupling?: int, lloc?: int, methods?: int}>  $metricsData
     */
    protected function render(
        array $nodes,
        array $edges,
        array $fileDiffs,
        array $analysisData,
        string $prNumber,
        string $prTitle,
        string $prUrl,
        int $prAdditions,
        int $prDeletions,
        string $repo,
        string $headCommit,
        int $fileCount,
        int $connectedCount,
        string $extTogglesHtml,
        string $folderTogglesHtml,
        string $severityTogglesHtml,
        string $layout,
        ?LayerStack $layerStack = null,
        ?RiskScore $riskScore = null,
        array $metricsData = [],
        string $layoutSwitcher = '',
    ): string {
        $rendererClass = self::RENDERERS[$layout] ?? ForceGraphRenderer::class;
        $isLayered = in_array($rendererClass, [LayeredCakeRenderer::class, LayeredArchRenderer::class]);
        $renderer = $isLayered ? new $rendererClass($layerStack) : new $rendererClass;

        $nodesJson = json_encode($nodes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $edgesJson = json_encode($edges, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $diffsJson = json_encode($fileDiffs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $analysisJson = json_encode($analysisData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $metricsJson = json_encode($metricsData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        $methodThresholds = config('laravel-code-analytics.method_metric_thresholds', [
            'cc'     => ['warn' => 5,  'bad' => 10],
            'lloc'   => ['warn' => 20, 'bad' => 50],
            'params' => ['warn' => 3,  'bad' => 5],
        ]);
        $methodThresholdsJson = json_encode($methodThresholds, JSON_HEX_TAG);

        return view('laravel-code-analytics::analysis.inner', [
            'prNumber' => $prNumber,
            'prTitle' => $prTitle,
            'prUrl' => $prUrl,
            'fileCount' => $fileCount,
            'prAdditions' => $prAdditions,
            'prDeletions' => $prDeletions,
            'repo' => $repo,
            'headCommit' => substr($headCommit, 0, 7),
            'nodesJson' => $nodesJson,
            'edgesJson' => $edgesJson,
            'diffsJson' => $diffsJson,
            'analysisJson' => $analysisJson,
            'metricsJson' => $metricsJson,
            'methodThresholdsJson' => $methodThresholdsJson,
            'severityDataJs' => $this->buildSeverityDataJs(),
            'extTogglesHtml' => $extTogglesHtml,
            'folderTogglesHtml' => $folderTogglesHtml,
            'severityTogglesHtml' => $severityTogglesHtml,
            'connectedCount' => $connectedCount,
            'layoutSetupJs' => $renderer->getLayoutSetupJs(),
            'simulationJs' => $renderer->getSimulationJs(),
            'frameHookJs' => $renderer->getFrameHookJs(),
            'riskPanel' => $this->buildRiskPanel($riskScore),
            'layoutSwitcher' => $layoutSwitcher,
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

        $qualityDot = static function (string $key, mixed $val): string {
            $color = match ($key) {
                'cc' => match (true) {
                    $val > 10 => '#f85149',
                    $val > 5 => '#d29922',
                    default => '#3fb950',
                },
                'mi' => match (true) {
                    $val < 85 => '#f85149',
                    $val < 100 => '#d29922',
                    default => '#3fb950',
                },
                'bugs' => match (true) {
                    $val > 0.1 => '#f85149',
                    $val > 0.05 => '#d29922',
                    default => '#3fb950',
                },
                'coupling' => match (true) {
                    $val > 15 => '#f85149',
                    $val > 8 => '#d29922',
                    default => '#3fb950',
                },
                default => null,
            };

            return $color !== null
                ? "<span style=\"color:{$color};font-size:7px;margin-right:3px\">&#9679;</span>"
                : '';
        };

        $fmt = function (string $key, array $m, array $d, bool $higherIsBad) use ($qualityDot): string {
            $val = $m[$key] ?? null;
            if ($val === null) {
                return '<td style="padding:2px 10px 2px 0;color:#484f58">—</td>';
            }

            $delta = $d[$key] ?? null;
            $deltaHtml = '';
            if ($delta !== null && abs((float) $delta) >= 0.005) {
                $sign = $delta > 0 ? '+' : '';
                $formatted = abs($delta) < 1 ? ltrim(number_format((float) $delta, 2), '0') : (string) (int) round($delta);
                $bad = $higherIsBad ? $delta > 0 : $delta < 0;
                $deltaColor = $bad ? '#f85149' : '#3fb950';
                $deltaHtml = "<span style=\"color:{$deltaColor};font-size:9px;margin-left:2px\">{$sign}{$formatted}</span>";
            }

            $displayVal = is_float($val) ? number_format((float) $val, 1) : $val;
            $dot = $qualityDot($key, $val);

            return "<td style=\"padding:2px 10px 2px 0;color:#8b949e;text-align:right\">{$dot}{$displayVal}{$deltaHtml}</td>";
        };

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
                .$fmt('cc', $file['metrics'], $file['deltas'], true)
                .$fmt('mi', $file['metrics'], $file['deltas'], false)
                .$fmt('bugs', $file['metrics'], $file['deltas'], true)
                .$fmt('coupling', $file['metrics'], $file['deltas'], true)
                .$fmt('lloc', $file['metrics'], $file['deltas'], true)
                .$fmt('methods', $file['metrics'], $file['deltas'], true)
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

    public function buildSeverityToggles(array $nodes): string
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
            $html .= '<div class="toggle-row">'
                .'<span class="legend-dot" style="background:'.$severity->color().'"></span>'
                .'<label class="toggle"><input type="checkbox" class="severity-toggle" data-severity="'.htmlspecialchars($severity->value).'" checked><span class="slider"></span></label>'
                .'<label class="toggle-label">'.$severity->label().' <span style="color:#484f58">('.$count.')</span></label>'
                .'</div>';
        }

        return $html;
    }

    public function buildExtToggles(array $nodes): string
    {
        $extCounts = [];
        foreach ($nodes as $node) {
            $extCounts[$node['ext']] = ($extCounts[$node['ext']] ?? 0) + 1;
        }
        ksort($extCounts);

        $html = '';
        foreach ($extCounts as $ext => $count) {
            $html .= '<div class="toggle-row">'
                .'<label class="toggle"><input type="checkbox" class="ext-toggle" data-ext="'.htmlspecialchars($ext).'" checked><span class="slider"></span></label>'
                .'<label class="toggle-label">'.htmlspecialchars($ext).' <span style="color:#484f58">('.$count.')</span></label>'
                .'</div>';
        }

        return $html;
    }

    public function buildFolderToggles(array $nodes): string
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

            $checked = $domain !== 'tests' ? ' checked' : '';
            $html .= '<div class="toggle-row">'
                .'<span class="legend-dot" style="background:'.htmlspecialchars($color).'"></span>'
                .'<label class="toggle"><input type="checkbox" class="domain-toggle" data-domain="'.htmlspecialchars($domain).'"'.$checked.'><span class="slider"></span></label>'
                .'<label class="toggle-label">'.htmlspecialchars($domain).' <span style="color:#484f58">('.$count.')</span></label>'
                .'</div>';
        }

        return $html;
    }

    private function buildLayoutSwitcher(string $current, array $files): string
    {
        $labels = ['force' => 'Force', 'tree' => 'Tree', 'grouped' => 'Grouped', 'cake' => 'Cake', 'arch' => 'Architecture'];
        $buttons = '';
        foreach ($files as $layout => $path) {
            $label = $labels[$layout] ?? $layout;
            $filename = basename($path);
            if ($layout === $current) {
                $buttons .= "<span class=\"layout-btn active\">{$label}</span>";
            } else {
                $buttons .= "<a class=\"layout-btn\" href=\"{$filename}\">{$label}</a>";
            }
        }

        return $buttons;
    }

    public function generate(
        array $nodes,
        array $edges,
        array $fileDiffs,
        array $analysisData,
        string $title,
        string $repo,
        string $headCommit,
        int $prAdditions,
        int $prDeletions,
        int $fileCount,
        ?RiskScore $riskScore = null,
        array $metricsData = [],
    ): string {
        return $this->buildWrapperHtml(
            nodes: $nodes,
            edges: $edges,
            fileDiffs: $fileDiffs,
            analysisData: $analysisData,
            prNumber: '',
            prTitle: $title,
            prUrl: '',
            prAdditions: $prAdditions,
            prDeletions: $prDeletions,
            repo: $repo,
            headCommit: $headCommit,
            fileCount: $fileCount,
            connectedCount: 0,
            extTogglesHtml: $this->buildExtToggles($nodes),
            folderTogglesHtml: $this->buildFolderToggles($nodes),
            severityTogglesHtml: $this->buildSeverityToggles($nodes),
            riskScore: $riskScore,
            metricsData: $metricsData,
        );
    }

    public function writeFile(string $outputPath, string $content): void
    {
        file_put_contents($outputPath, $content);
    }

    /**
     * Generate a single HTML file containing all 5 layouts as embedded iframes with a tab switcher.
     *
     * @param  array<string, array{cc?: int, mi?: float, bugs?: float, coupling?: int, lloc?: int, methods?: int}>  $metricsData
     */
    public function writeSingleFile(
        array $nodes,
        array $edges,
        array $fileDiffs,
        array $analysisData,
        string $prNumber,
        string $prTitle,
        string $prUrl,
        int $prAdditions,
        int $prDeletions,
        string $repo,
        string $headCommit,
        int $fileCount,
        int $connectedCount,
        string $extTogglesHtml,
        string $folderTogglesHtml,
        string $severityTogglesHtml,
        string $outputPath,
        ?LayerStack $layerStack = null,
        ?RiskScore $riskScore = null,
        array $metricsData = [],
        string $defaultView = 'force',
    ): void {
        file_put_contents($outputPath, $this->buildWrapperHtml(
            nodes: $nodes,
            edges: $edges,
            fileDiffs: $fileDiffs,
            analysisData: $analysisData,
            prNumber: $prNumber,
            prTitle: $prTitle,
            prUrl: $prUrl,
            prAdditions: $prAdditions,
            prDeletions: $prDeletions,
            repo: $repo,
            headCommit: $headCommit,
            fileCount: $fileCount,
            connectedCount: $connectedCount,
            extTogglesHtml: $extTogglesHtml,
            folderTogglesHtml: $folderTogglesHtml,
            severityTogglesHtml: $severityTogglesHtml,
            layerStack: $layerStack,
            riskScore: $riskScore,
            metricsData: $metricsData,
            defaultView: $defaultView,
        ));
    }

    /**
     * @param  array<string, array{cc?: int, mi?: float, bugs?: float, coupling?: int, lloc?: int, methods?: int}>  $metricsData
     */
    private function buildWrapperHtml(
        array $nodes,
        array $edges,
        array $fileDiffs,
        array $analysisData,
        string $prNumber,
        string $prTitle,
        string $prUrl,
        int $prAdditions,
        int $prDeletions,
        string $repo,
        string $headCommit,
        int $fileCount,
        int $connectedCount,
        string $extTogglesHtml,
        string $folderTogglesHtml,
        string $severityTogglesHtml,
        ?LayerStack $layerStack = null,
        ?RiskScore $riskScore = null,
        array $metricsData = [],
        string $defaultView = 'grouped',
    ): string {
        $labels = ['force' => 'Force', 'tree' => 'Tree', 'grouped' => 'Grouped', 'cake' => 'Cake', 'arch' => 'Architecture'];

        $jsEntries = [];
        foreach (array_keys(self::RENDERERS) as $layoutName) {
            $html = $this->execute(
                nodes: $nodes,
                edges: $edges,
                fileDiffs: $fileDiffs,
                analysisData: $analysisData,
                prNumber: $prNumber,
                prTitle: $prTitle,
                prUrl: $prUrl,
                prAdditions: $prAdditions,
                prDeletions: $prDeletions,
                repo: $repo,
                headCommit: $headCommit,
                fileCount: $fileCount,
                connectedCount: $connectedCount,
                extTogglesHtml: $extTogglesHtml,
                folderTogglesHtml: $folderTogglesHtml,
                severityTogglesHtml: $severityTogglesHtml,
                layout: $layoutName,
                layerStack: $layerStack,
                riskScore: null, // Risk panel lives in the wrapper topbar tooltip
                metricsData: $metricsData,
            );
            // Hide the title-bar inside the iframe — it lives in the wrapper now
            $htmlForWrapper = str_replace('</head>', '<style>.title-bar{display:none!important}</style></head>', $html);
            $jsEntries[] = "'{$layoutName}':'".base64_encode($htmlForWrapper)."'";
        }

        $tabButtons = '';
        foreach ($labels as $name => $label) {
            $active = $name === $defaultView ? ' active' : '';
            $tabButtons .= "<button class=\"tab{$active}\" data-layout=\"{$name}\">{$label}</button>";
        }

        $escapedTitle = htmlspecialchars($prTitle);
        $escapedUrl = htmlspecialchars($prUrl);
        $headlineHtml = $prUrl
            ? "<a href=\"{$escapedUrl}\" target=\"_blank\" class=\"pr-link\">{$escapedTitle}</a>"
            : "<span class=\"pr-link\">{$escapedTitle}</span>";
        $jsLayoutData = implode(",\n    ", $jsEntries);
        $riskBadgeHtml = $this->buildRiskBadge($riskScore);
        $metricsBadgeHtml = $this->buildMetricsBadge($metricsData);

        $wrapperNodesJson = json_encode($nodes, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $wrapperAnalysisJson = json_encode($analysisData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $wrapperMetricsJson = json_encode($metricsData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $wrapperSeverityJs = $this->buildSeverityDataJs();

        return view('laravel-code-analytics::analysis.wrapper', [
            'prNumber' => $prNumber,
            'escapedTitle' => $escapedTitle,
            'headlineHtml' => $headlineHtml,
            'fileCount' => $fileCount,
            'prAdditions' => $prAdditions,
            'prDeletions' => $prDeletions,
            'metricsBadgeHtml' => $metricsBadgeHtml,
            'riskBadgeHtml' => $riskBadgeHtml,
            'tabButtons' => $tabButtons,
            'wrapperSeverityJs' => $wrapperSeverityJs,
            'wrapperNodesJson' => $wrapperNodesJson,
            'wrapperAnalysisJson' => $wrapperAnalysisJson,
            'wrapperMetricsJson' => $wrapperMetricsJson,
            'jsLayoutData' => $jsLayoutData,
        ])->render();
    }
}
