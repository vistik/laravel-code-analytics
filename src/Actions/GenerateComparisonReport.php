<?php

namespace Vistik\LaravelCodeAnalytics\Actions;

use Vistik\LaravelCodeAnalytics\Enums\GraphLayout;
use Vistik\LaravelCodeAnalytics\Renderers\LayerStack;
use Vistik\LaravelCodeAnalytics\Reports\BridgeResolver;
use Vistik\LaravelCodeAnalytics\Reports\BridgeResult;
use Vistik\LaravelCodeAnalytics\Reports\FilterTogglesHtml;
use Vistik\LaravelCodeAnalytics\Reports\GraphPayload;
use Vistik\LaravelCodeAnalytics\Reports\PullRequestContext;

class GenerateComparisonReport
{
    public function generate(
        GraphPayload $payloadA,
        PullRequestContext $prA,
        LayerStack $layerStackA,
        GraphPayload $payloadB,
        PullRequestContext $prB,
        LayerStack $layerStackB,
        GraphLayout $defaultView = GraphLayout::Force,
    ): string {
        $htmlReport = new GenerateHtmlReport;

        $bridgeResult = (new BridgeResolver)->resolve($payloadA, $payloadB);
        $bridgeIdsInA = $bridgeResult->bridgeIdsInA();
        $bridgeIdsInB = $bridgeResult->bridgeIdsInB();

        $togglesA = new FilterTogglesHtml(
            ext: $htmlReport->buildExtToggles($payloadA->nodes, $payloadA->filterDefaults['hidden_extensions'] ?? []),
            folder: $htmlReport->buildFolderToggles($payloadA->nodes, $payloadA->filterDefaults['hidden_domains'] ?? ['tests']),
            severity: $htmlReport->buildSeverityToggles($payloadA->nodes, $payloadA->filterDefaults['hidden_severities'] ?? []),
            kind: $htmlReport->buildKindToggles($payloadA->nodes, $payloadA->filterDefaults['hidden_kinds'] ?? []),
        );
        $togglesB = new FilterTogglesHtml(
            ext: $htmlReport->buildExtToggles($payloadB->nodes, $payloadB->filterDefaults['hidden_extensions'] ?? []),
            folder: $htmlReport->buildFolderToggles($payloadB->nodes, $payloadB->filterDefaults['hidden_domains'] ?? ['tests']),
            severity: $htmlReport->buildSeverityToggles($payloadB->nodes, $payloadB->filterDefaults['hidden_severities'] ?? []),
            kind: $htmlReport->buildKindToggles($payloadB->nodes, $payloadB->filterDefaults['hidden_kinds'] ?? []),
        );

        // Suppress risk panel inside iframes (it lives in the comparison topbar)
        $innerPayloadA = new GraphPayload(
            nodes: $payloadA->nodes,
            edges: $payloadA->edges,
            fileDiffs: $payloadA->fileDiffs,
            analysisData: $payloadA->analysisData,
            metricsData: $payloadA->metricsData,
            fileContents: $payloadA->fileContents,
            filterDefaults: $payloadA->filterDefaults,
            riskScore: null,
            nodeFqcns: $payloadA->nodeFqcns,
        );
        $innerPayloadB = new GraphPayload(
            nodes: $payloadB->nodes,
            edges: $payloadB->edges,
            fileDiffs: $payloadB->fileDiffs,
            analysisData: $payloadB->analysisData,
            metricsData: $payloadB->metricsData,
            fileContents: $payloadB->fileContents,
            filterDefaults: $payloadB->filterDefaults,
            riskScore: null,
            nodeFqcns: $payloadB->nodeFqcns,
        );

        $jsEntriesLeft = [];
        $jsEntriesRight = [];
        $tabButtons = '';
        foreach (GraphLayout::cases() as $graphLayout) {
            $htmlA = $htmlReport->execute($innerPayloadA, $prA, $togglesA, $graphLayout, $layerStackA, $bridgeIdsInA);
            $htmlB = $htmlReport->execute($innerPayloadB, $prB, $togglesB, $graphLayout, $layerStackB, $bridgeIdsInB);

            $htmlAForWrapper = str_replace('</head>', '<style>.title-bar{display:none!important}</style></head>', $htmlA);
            $htmlBForWrapper = str_replace('</head>', '<style>.title-bar{display:none!important}</style></head>', $htmlB);

            $jsEntriesLeft[] = "'{$graphLayout->value}':'".base64_encode($htmlAForWrapper)."'";
            $jsEntriesRight[] = "'{$graphLayout->value}':'".base64_encode($htmlBForWrapper)."'";

            $active = $graphLayout === $defaultView ? ' active' : '';
            $tabButtons .= "<button class=\"tab{$active}\" data-layout=\"{$graphLayout->value}\">{$graphLayout->label()}</button>";
        }

        $wrapperSeverityJs = $htmlReport->buildSeverityDataJs();
        $wrapperNodesJsonA = json_encode($payloadA->nodes, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $wrapperAnalysisJsonA = json_encode($payloadA->analysisData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $wrapperMetricsJsonA = json_encode($payloadA->metricsData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $wrapperNodesJsonB = json_encode($payloadB->nodes, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $wrapperAnalysisJsonB = json_encode($payloadB->analysisData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $wrapperMetricsJsonB = json_encode($payloadB->metricsData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        $bridgesJson = json_encode($bridgeResult->bridges, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);

        // Bridge bar: labels for each bridge pair
        $bridgeBarItems = $this->buildBridgeBarItems($bridgeResult, $payloadA, $payloadB);
        $bridgeBarJson = json_encode($bridgeBarItems, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);

        $riskBadgeHtmlA = $this->buildInlineRiskBadge($payloadA->riskScore?->score, 'left');
        $riskBadgeHtmlB = $this->buildInlineRiskBadge($payloadB->riskScore?->score, 'right');

        $escapedTitleA = htmlspecialchars($prA->prTitle);
        $escapedTitleB = htmlspecialchars($prB->prTitle);
        $headlineHtmlA = $prA->prUrl
            ? '<a href="'.htmlspecialchars($prA->prUrl).'" target="_blank" class="pr-link">'.$escapedTitleA.'</a>'
            : '<span class="pr-link">'.$escapedTitleA.'</span>';
        $headlineHtmlB = $prB->prUrl
            ? '<a href="'.htmlspecialchars($prB->prUrl).'" target="_blank" class="pr-link">'.$escapedTitleB.'</a>'
            : '<span class="pr-link">'.$escapedTitleB.'</span>';

        return view()->file(__DIR__.'/../../resources/views/analysis/comparison-wrapper.blade.php', [
            'prANumber' => $prA->prNumber,
            'prBNumber' => $prB->prNumber,
            'headlineHtmlA' => $headlineHtmlA,
            'headlineHtmlB' => $headlineHtmlB,
            'fileCountA' => $prA->fileCount,
            'fileCountB' => $prB->fileCount,
            'prAdditionsA' => $prA->prAdditions,
            'prDeletionsA' => $prA->prDeletions,
            'prAdditionsB' => $prB->prAdditions,
            'prDeletionsB' => $prB->prDeletions,
            'repoA' => $prA->repo,
            'repoB' => $prB->repo,
            'riskBadgeHtmlA' => $riskBadgeHtmlA,
            'riskBadgeHtmlB' => $riskBadgeHtmlB,
            'tabButtons' => $tabButtons,
            'jsLayoutDataLeft' => implode(",\n    ", $jsEntriesLeft),
            'jsLayoutDataRight' => implode(",\n    ", $jsEntriesRight),
            'defaultView' => $defaultView->value,
            'bridgesJson' => $bridgesJson,
            'bridgeBarJson' => $bridgeBarJson,
            'bridgeCount' => count($bridgeResult->bridges),
            'wrapperSeverityJs' => $wrapperSeverityJs,
            'wrapperNodesJsonA' => $wrapperNodesJsonA,
            'wrapperAnalysisJsonA' => $wrapperAnalysisJsonA,
            'wrapperMetricsJsonA' => $wrapperMetricsJsonA,
            'wrapperNodesJsonB' => $wrapperNodesJsonB,
            'wrapperAnalysisJsonB' => $wrapperAnalysisJsonB,
            'wrapperMetricsJsonB' => $wrapperMetricsJsonB,
        ])->render();
    }

    private function buildBridgeBarItems(
        BridgeResult $bridgeResult,
        GraphPayload $payloadA,
        GraphPayload $payloadB,
    ): array {
        if ($bridgeResult->isEmpty()) {
            return [];
        }

        $nodeMapA = [];
        foreach ($payloadA->nodes as $n) {
            $nodeMapA[$n['id']] = $n;
        }
        $nodeMapB = [];
        foreach ($payloadB->nodes as $n) {
            $nodeMapB[$n['id']] = $n;
        }

        $items = [];
        foreach ($bridgeResult->bridges as $idA => $idB) {
            $nA = $nodeMapA[$idA] ?? null;
            $nB = $nodeMapB[$idB] ?? null;
            if ($nA && $nB) {
                $items[] = [
                    'leftId' => $idA,
                    'rightId' => $idB,
                    'leftLabel' => $nA['id'],
                    'rightLabel' => $nB['id'],
                ];
            }
        }

        return $items;
    }

    private function buildInlineRiskBadge(?int $score, string $side): string
    {
        if ($score === null) {
            return '';
        }

        [$color, $bgColor, $borderColor, $label] = match (true) {
            $score >= 75 => ['#f85149', '#3d1214', '#da3633', 'Very High'],
            $score >= 50 => ['#d29922', '#2d1c00', '#9e6a03', 'High'],
            $score >= 25 => ['#58a6ff', '#1c2a3a', '#1f6feb', 'Medium'],
            default => ['#3fb950', '#0d3520', '#238636', 'Low'],
        };

        return '<span style="font-size:10px;color:#6e7681">Risk</span>'
            ."<span style=\"font-size:16px;font-weight:700;color:{$color};line-height:1;letter-spacing:-0.03em\">{$score}</span>"
            .'<span style="font-size:10px;color:#6e7681">/ 100</span>'
            ."<span style=\"font-size:10px;color:{$color};background:{$bgColor};padding:2px 7px;border-radius:10px;border:1px solid {$borderColor};font-weight:500\">{$label}</span>";
    }
}
